-- Implement service class for FusionPBX that uses LibUV library to connect to FS.
-- So it can be run from FS like `luarun service.lua` or as standalone process.
--
-- @usage
--
-- local service = EventService.new('blf', { '127.0.0.1', '8021', 'ClueCon',
--   reconnect = 5;
--   subscribe = {'PRESENCE_PROBE'}, filter = { ['Caller-Direction']='inbound' }
-- })
--
-- -- FS receive SUBSCRIBE to BLF from device
-- service:on("esl::event::PRESENCE_PROBE::*", , function(self, eventName, event)
--   local proto = event:getHeader('proto')
-- end)
--
-- service:run()
--

-- luacheck: push ignore freeswitch argv scripts_dir

local uv            = require "lluv"
local ut            = require "lluv.utils"
local esl           = require "lluv.esl"
local ESLConnection = require "lluv.esl.connection".Connection
local Logging       = require "log"

local new_uuid
if freeswitch then
  local api  = require "resources.functions.api"
  new_uuid = function()
    return api:execute("create_uuid")
  end
else
  local Uuid = require "uuid"
  new_uuid = function()
    return Uuid.new()
  end
end

local json
if freeswitch then
  json  = require "resources.functions.lunajson"
else
  json  = require "dkjson"
end

-- send response to FS
local function service_response(service, request, response, cb)
  local request_uuid = request:getHeader('Service-Request-UUID')

  -- if sender does not ask as to send response just ignore it
  if not request_uuid then
    if cb then uv.defer(cb) end
    return
  end

  local event = esl.Event('CUSTOM', 'fusion::service::response')
  event:addHeader('Service-Response-UUID', request_uuid)
  if type(response) == 'string' then
    event:addHeader('Service-Response', response)
  else
    event:addBody(json.encode(response), 'application/json')
  end

  if cb then
    service:sendEvent(event, cb)
  else
    service:sendEvent(event)
  end
end

-- create new pid file and start monitor on it.
-- if file removed/changed then service terminates
local function service_pid_file(service, pid_path)
  local log = service:logger()
  local service_name = service:name()

  local buffers = {
    push = function(self, v)
      self[#self + 1] = v
      return self
    end;
    pop  = function(self)
      local v = self[#self];
      self[#self] = nil
      return v
    end;
  }

  local function read_file(p, cb)
    local buf = buffers:pop() or uv.buffer(1024)
    uv.fs_open(p, "r", function(file, err)
      buffers:push(buf)
      if err then return cb(err) end
      file:read(buf, function(file, err, data, size) -- luacheck: ignore file err
        file:close()
        if err then return cb(err) end
        return cb(nil, data:to_s(size))
      end)
    end)
  end

  local function write_file(p, data, cb)
    uv.fs_open(p, 'w+', function(file, err)
      if err then return cb(err) end
      file:write(data, function(file, err) -- luacheck: ignore file err
        file:close()
        return cb(err)
      end)
    end)
  end

  local uuid = new_uuid()
  local pid_file = pid_path .. "/" .. service_name .. ".tmp"

  local pid = {pid = uuid, file = pid_file, valid = true}

  local test_in_progress
  local function test_pid_file()
    if test_in_progress then return end
    test_in_progress = true
    read_file(pid.file, function(err, data)
      test_in_progress = false
      if err then
        log.infof('can not read pid file: %s', tostring(err))
        return uv.stop()
      end

      if data ~= pid.pid then
        log.infof('detect launch second instance of service')
        pid.valid = false -- we do not remove it when stop service
        return uv.stop()
      end
    end)
  end

  -- crete pid file
  uv.fs_mkdir(pid_path, function(_, err)
    if err and err:no() ~= uv.EEXIST then
      log.errf('can not create pid directory: %s', tostring(err))
      return uv.stop()
    end
    write_file(pid.file, pid.pid, function(err) -- luacheck: ignore err
      if err then
        log.errf('can not create pid file: %s', tostring(err))
        return uv.stop()
      end

      uv.timer():start(30000, 30000, test_pid_file)

      uv.fs_event():start(pid.file, function(_, err) -- luacheck: ignore err
        if err then
          log.warningf('can not start file monitoring')
        end
        return test_pid_file()
      end)
    end)
  end)

  return pid
end

-- start service process
local function service_start(service, error_handler)
  local log = service:logger()

  local pid if freeswitch then
    require "resources.functions.config"
    local pid_path = scripts_dir .. "/run"
    pid = service_pid_file(service, pid_path)
  end

  log.infof('service %s started', service:name())

  local ok, err = pcall(uv.run, error_handler)

  if not ok then
    log.errf('%s', tostring(err))
  end

  log.infof('service %s stopped', service:name())

  if pid and pid.valid then
    os.remove(pid.file)
  end
end

-- register all needed listeners to manage service status
local function service_init_loop(service)
  local log = service:logger()

  if service._plain_events then
    service:on("esl::event::**", function(self, eventName, event) -- luacheck: ignore eventName
      local name, subclass = event:getHeader('Event-Name'), event:getHeader('Event-Subclass')
      if subclass then
        self:emit(name .. '::' .. subclass, event)
      else
        self:emit(name, event)
      end
    end)
  end

  service:on("esl::event::CUSTOM::*", function(self, eventName, event) -- luacheck: ignore self eventName
    if event:getHeader('Event-Subclass') ~= 'fusion::service::control' then
      return
    end

    if service:name() ~= event:getHeader('service-name') then return end

    local command = event:getHeader('service-command')

    if command == "stop" then
      log.infof('receive stop service command')
      return service_response(service, event, '+OK', function()
        uv.stop()
      end)
    end

    if command == "status" then
      log.notice("receive status service command")
      return service_response(service, event, '+OK running')
    end

    log.warningf('Unknown service command: %s', command or '<NONE>')
    return service_response(service, event, '-ERR unsupported command')
  end)

  service:on("esl::event::SHUTDOWN::*", function(self, eventName, event) -- luacheck: ignore self eventName event
    log.infof('freeswitch shutdown')
    return uv.stop()
  end)

  service:on('esl::reconnect', function(self, eventName) -- luacheck: ignore self eventName
    log.infof('esl connected')
  end)

  service:on('esl::disconnect', function(self, eventName, err) -- luacheck: ignore self eventName
    log.infof('esl disconnected: %s', tostring(err))
  end)

  service:on('esl::error::**', function(self, eventName, err) -- luacheck: ignore self eventName
    log.errf('esl error: %s', tostring(err))
  end)

  service:on('esl::close', function(self, eventName, err) -- luacheck: ignore self eventName err
    -- print(eventName, err)
  end)

  --! @todo way to stop service if it runnuing not from FS
  -- E.g. using LuaService on Windows and signals on *nix systems

  return service
end

local function append(t, v)
  t[#t + 1] = v
end

local EventService = ut.class(ESLConnection) do
local super = ut.class.super(EventService)

function EventService:__init(service_name, params)
  params = params or {}
  params.subscribe = params.subscribe or {}

  if freeswitch then
    append(params.subscribe, 'CUSTOM::fusion::service::control')
    append(params.subscribe, 'SHUTDOWN')
    if params.filter and next(params.filter) then
        params.filter['Event-Subclass'] = 'fusion::service::control'
        params.filter['Event-name']     = 'SHUTDOWN'
    end
  end

  self = super(self, '__init', params)

  local log do
    local writer if freeswitch then
      writer = require "log.writer.prefix".new('[' .. service_name .. '] ',
        require "log.writer.freeswitch".new()
      )
    else
      writer = require "log.writer.stdout".new()
    end

    log = Logging.new( writer,
      require "log.formatter.pformat".new(true, true)
    )

    log.errf     = log.error
    log.warningf = log.warning
    log.infof    = log.info
    log.debugf   = log.debug
    log.tracef   = log.trace
  end
  self._logger = log

  self._service_name = service_name
  self._plain_events = params.emit_plain

  service_init_loop(self)

  return self:open()
end

function EventService:run(error_handler)
  service_start(self, error_handler)
end

function EventService:stop() -- luacheck: ignore self
  uv.stop()
end

function EventService:logger()
  return self._logger
end

function EventService:name()
  return self._service_name
end

end

-- luacheck: pop

return EventService