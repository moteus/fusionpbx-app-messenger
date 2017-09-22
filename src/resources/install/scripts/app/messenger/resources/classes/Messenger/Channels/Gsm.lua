local function messenger_require(name)
  return require ('app.messenger.resources.classes.' .. name)
end

local uv            = require "lluv"
local ut            = require "lluv.utils"
local GsmModem      = require "lluv.gsmmodem"
local esl           = require "lluv.esl"
local EventEmitter  = require "EventEmitter".EventEmitter

local utils         = messenger_require "Messenger.Utils"
local BaseChannel   = messenger_require "Messenger.Channels.Base"
local Logger        = messenger_require "Messenger.Logger"

local log           = Logger.get('chann.gsm')
local STATUS        = utils.STATUS

---------------------------------------------------------------
local MultipartRecvQueue = ut.class(EventEmitter) do
local super = ut.class.super(MultipartRecvQueue)

local function is_full(storage)
  for i = 1, storage.n do
    if not storage[i] then
      return false
    end
  end
  return true
end

local function get_messages(storage)
  if storage.timer then
    storage.timer:close()
    storage.timer = nil
  end
  return storage
end

function MultipartRecvQueue:__init(timeout)
  self = super(self, '__init', {wildcard = false})

  self._timeout = timeout or 5000
  self._storage = {}
  return self
end

function MultipartRecvQueue:_on_recv(messages)
  self:emit('recv', messages)
end

function MultipartRecvQueue:wait(sms)
  local ref = sms:concat_reference()
  if not ref then return false end

  local id = ('%s/%s'):format(sms:number(), ref)
  local storage = self._storage[id] or {}

  if storage.n and storage.n ~= sms:concat_count() then
    local messages = get_messages(storage)
    storage = {}
    self._storage[id] = storage
    self:_on_recv(messages)
  end

  storage.n = sms:concat_count()
  storage[sms:concat_number()] = sms

  if is_full(storage) then
    local messages = get_messages(storage)
    self._storage[id] = nil
    self:_on_recv(messages)
  else
    self._storage[id] = storage
    local timer = storage.timer or uv.timer()
    storage.timer = timer

    timer:start(self._timeout, function()
      self:_on_recv(get_messages(storage))
      self._storage[id] = nil
    end)
  end

  return true
end

end
---------------------------------------------------------------

---------------------------------------------------------------
local GsmChannel = ut.class(BaseChannel) do

local super = ut.class.super(GsmChannel)

local function modem_init(self)
  self._modem:configure(function(modem, err, cmd, info)
    if err then
      log.warning('[%s] configure error: %s; Command: %s; Info: %s', self:name(), err, cmd, info)
      return utils.delay_call(10000, modem_init, self)
    end

    self._ready = true
    log.info("[%s] ready", self:name())
    if modem.cmd then
      -- luacheck: push ignore err
      modem:cmd():OperatorName(function(_, err, name)
        self._gsm_operator = name or self._gsm_operator
        log.info("[%s] operator: %s", self:name(), err or name)
      end)

      modem:cmd():IMEI(function(_, err, imei)
        self._gsm_imei = imei or self._gsm_imei
        log.info("[%s] IMEI: %s", self:name(), err or imei)
      end)
      -- luacheck: pop
    end
  end)
end

local function route_message(messenger, message, cb)
  local number
  if message.domain_uuid then
    messenger:domain_by_uuid(message.domain_uuid, function(domain_name)
      message.domain_name = domain_name
      if domain_name then
        cb(message)
      end
    end)
  elseif message.direction == 'outbound' then
    number = message.source
  elseif message.direction == 'inbound' or message.direction == 'local' then
    number = message.destination
  end

  if number then
    local _, domain_name = ut.split_first(number, '@', true)
    if domain_name then
      message.domain_name = domain_name
      messenger:domain_by_name(domain_name, function(domain_uuid)
        message.domain_uuid = domain_uuid
        if domain_uuid then
          cb(message)
        end
      end)
    end
  end
end

local function forward_to_chatplan(self, sms, subject, context, destination)
  local service = self._messenger.__SERVICE

  local to_user, to_host = ut.split_first(destination, '@', true)

  local event = esl.Event("CUSTOM", "SMS::SEND_MESSAGE")
  event:addHeader("proto",               "gsm"                      )
  event:addHeader("dest_proto",          "GLOBAL"                   )
  event:addHeader("context",             context                    )
  event:addHeader("skip_global_process", "false"                    )

  event:addHeader("direction",           'inbound'                  )

  event:addHeader("from_proto",          "gsm"                      )
  event:addHeader("from",                sms:number()               )
  event:addHeader("from_user",           sms:number()               )

  -- event:addHeader("to_proto",            "gsm"                      ) --! @todo is it required
  event:addHeader("to",                  destination                )
  event:addHeader("to_user",             to_user                    )
  event:addHeader("to_host",             to_host                    )

  event:addHeader("hint",                self._routing.number       )
  event:addHeader("subject",             subject                    )

  event:addHeader("flash",                sms:flash() and 'true' or 'false' )
  event:addHeader("servicecentreaddress", sms:smsc()                )
  event:addHeader("date-gmt",             sms:date():fmt('%F %T')   )
  event:addHeader("date-local",           sms:date():tolocal():fmt('%F %T'))

  event:addHeader("Channel-UUID",        self:id()                  )
  event:addHeader("Channel-Name",        self:name()                )

  event:addHeader("type",                "text/plain"               )
  event:addBody(sms:text('utf-8'),       "text/plain"               )

  service:sendEvent(event)
end

local function forward_to_router(self, sms, subject, context, destination)
  local router = self._messenger.__ROUTER
  local message = {
    type               = 'sms';
    context            = context;
    category           = self:name();
    direction          = 'inbound';
    source             = sms:number();
    source_proto       = 'gsm';
    source_destination = self._routing.number;
    destination        = destination;
    destination_number = ut.split_first(destination, '@', true);
    subject            = subject,
    text               = sms:text('utf-8');
  }
  route_message(self._messenger, message, function(message) -- luacheck: ignore message
    router:send(message)
  end)
end

local function on_sms_message(self, sms)
  log.info("[%s] SMS from: %s; Text: %s", self:name(), sms:number(), sms:text('cp866'))

  local context     = self._routing.context
  local method      = self._routing.method
  local destination = self._routing.destination
  local subject     = self._routing.properties.subject or 'SMS From ${source_number}'

  local date_local = sms:date():tolocal():fmt('%F %T')
  subject = utils.render(subject, {
    source             = sms:number(),
    source_number      = sms:number(),
    destination        = self._routing.number,
    destination_number = self._routing.number,
    channel            = self:name(),
    date_gmt           = sms:date():fmt('%F %T'),
    date_local         = date_local,
    date               = date_local,
  })

  log.info("[%s] [%s] forward to [%s][%s][%s]",
    subject, self:name(), method, context, destination
  )

  if method == 'messenger' then
    forward_to_router(self, sms, subject, context, destination)
  end
  if method == 'chatplan' then
    forward_to_chatplan(self, sms, subject, context, destination)
  end
end

local function on_delivery_report(self, sms)
  local messenger = self._messenger

  local success, status = sms:delivery_status()
  local ref = tostring(sms:reference())

  if success then
    log.info('[%s] SMS Send pass; Message reference: %s', self:name(), ref)
    messenger:notification_status(ref, self:id(), STATUS.SUCCESS)
  elseif status.temporary then
    log.warning('[%s] SMS send continue: %s; Message reference: %s',
      self:name(), status.info, sms:reference()
    )
  else
    log.error('[%s] SMS Send fail: %s; Message reference: %s',
      self:name(), status.info, sms:reference()
    )
    messenger:notification_status(tostring(sms:reference()), self:id(), STATUS.FAIL, status.info)
  end
end

local combine_messages do
--! @fixme ugly hack to concat several sms messages to one.
-- I think best way is to implement separate class to multipart sms
-- because each sms from it also should be avaliable.
-- E.g. each one has its own sms memory index. So to be able remove it
-- we need all of them

local function append_sms(dst, src)
  dst._text = dst._text .. src._text
end

combine_messages = function(self, messages) -- luacheck: ignore self
  local sms
  -- `messages` may have not all parts
  for i = 1, messages.n do if messages[i] then
    local msg = messages[i]
    if sms then append_sms(sms, msg) else sms = msg end
  end end
  return sms
end

end

function GsmChannel:__init(messenger, channel_info)
  self = super(self, '__init', messenger, channel_info)

  local port, port_settings = channel_info.settings

  if channel_info.settings then
    port          = channel_info.settings.port
    port_settings = channel_info.settings.settings
    self._routing = channel_info.settings.route
  end

  local properties = self._routing and self._routing.properties or {}
  for _, property in ipairs(properties) do
    properties[property.name] = property.value
    log.info('add property %s: %s', property.name, property.value)
  end

  self._multipart_queue     = MultipartRecvQueue.new(10000)
  self._routing = self._routing or {}
  self._routing.method      = self._routing.method or 'messenger'
  self._routing.context     = self._routing.context or 'public'
  self._routing.number      = self._routing.number or self:name()
  self._routing.destination = self._routing.destination or self._routing.number
  self._routing.properties  = properties

  if not port then
    log.error('[%s] undefined port number', self:name())
    return
  end

  local modem = GsmModem.new(port, port_settings)

  self._ready = false

  -- modem:set_rs232_trace('trace')

  modem:open(function(_, err, info) -- luacheck: ignore info
    if err then
      log.error('[%s] can not open port: %s', self:name(), err)
      self:close()
      return messenger:remove_channel(self)
    end
    modem_init(self)
  end)

  if modem.on then
    modem:on('boot', function()
      if self._ready then
        self._ready = false
        utils.delay_call(10000, modem_init, self)
      end
    end)

    -- luacheck: push ignore event
    modem:on('report::recv', function(_, event, sms)
      return on_delivery_report(self, sms)
    end)

    modem:on('sms::recv', function(_, event, sms)
      if sms:concat_number() and sms:concat_count() then
        log.info('[%s] SMS from %s; Part %d / %d',
          self:name(), sms:number(), sms:concat_number(), sms:concat_count()
        )
      end

      local waiting = self._multipart_queue and self._multipart_queue:wait(sms)
      if not waiting then return on_sms_message(self, sms) end
    end)
    -- luacheck: pop

    -- recv multipart message. It can be not full one
    self._multipart_queue:on('recv', function(this, eventName, messages) -- luacheck: ignore eventName
      local sms = combine_messages(this, messages)
      return on_sms_message(self, sms)
    end)
  end

  self._modem = modem
  return self
end

function GsmChannel:send(message, settings)
  local messenger = self._messenger

  local expire = tonumber(message.expire) or 3600

  messenger:notification_register(self:id(), message, settings, function(message_uuid, res )-- luacheck: ignore res
    if not self._ready then
      local msg = 'channel not ready'
      log.error('[%s] SMS Send fail: %s', self:name(), msg)
      return messenger:notification_status(message_uuid, self:id(), STATUS.FAIL, msg)
    end

    if message.type == 'ussd' then
      return self._modem:send_ussd(message.text, function(_, err, msg)
        if err or not msg then
          local status_text = tostring(err or 'No response')
          log.error('[%s] USSD Send fail: %s', self:name(), status_text)
          return messenger:notification_status(message_uuid, self:id(), STATUS.FAIL, status_text)
        end
        log.info('[%s] USSD response: [%s] %s', self:name(), msg:status(), msg:text('cp866'))

        local router = messenger.__ROUTER
        local response = {
          type               = message.source_proto;
          context            = message.context;
          category           = 'response';
          direction          = 'inbound';
          source             = message.destination;
          source_proto       = 'ussd';
          source_destination = message.text;
          destination        = message.source;
          destination_proto  = message.source_proto;
          destination_number = ut.split_first(message.source, '@', true);
          text               = msg:text('utf-8');
        }

        route_message(self._messenger, response, function(message) -- luacheck: ignore message
          router:send(message)
        end)
        messenger:notification_status(message_uuid, self:id(), STATUS.SUCCESS)
      end)
    end

    local opt = {
      -- in minutes with 5 min step;
      validity = math.floor(expire / 60);
      charset  = 'utf-8';
      -- waitReport = 'final';
    }

    self._modem:send_sms(message.destination_number, message.text, opt, function(_, err, ref, response)
      -- multi part sms
      if type(ref) == 'table' then
        ref = ref[#ref] -- track only last part
      end

      if response and (not response.success) and response.temporary then
        log.info('[%s] Get temporary response: %s; Message reference: %s',
          self:name(), response.info, ref or '<NONE>'
        )
        if ref then messenger:notification_mark(message_uuid, ref) end
        return
      end

      if not (response or err) then
        log.info('[%s] Sending message...; Message reference: %s', self:name(), ref or '<NONE>')
        if ref then messenger:notification_mark(message_uuid, ref) end
        return
      end

      if err or not response.success then
        local msg = tostring(err or response.info)
        log.error('[%s] SMS Send fail: %s; Message reference: %s', self:name(), msg, ref or '<NONE>')
        messenger:notification_status(message_uuid, self:id(), STATUS.FAIL, msg)
      else
        log.info('[%s] SMS Send pass; Message reference: %s', self:name(), ref or '<NONE>')
        messenger:notification_status(message_uuid, self:id(), STATUS.SUCCESS)
      end
    end)
  end)
end

function GsmChannel:close(cb)
  self._modem:close(function(_, err)
    if err then
      log.error('[%s] error while closing modem connection: %s', self:name(), err)
    end
    super(self, 'close', cb)
  end)
end

end
---------------------------------------------------------------

return GsmChannel