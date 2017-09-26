local function messenger_require(name)
  return require ('app.messenger.resources.classes.' .. name)
end

local ut            = require "lluv.utils"
local pg            = require "lluv.pg"
local Uuid          = require "uuid"

local utils         = messenger_require "Messenger.Utils"
local Channels      = messenger_require "Messenger.Channels"
local Logger        = messenger_require "Messenger.Logger"

local json          = utils.json
local STATUS        = utils.STATUS
local log           = Logger.get('messenger')

----------------------------------------------------------------------
local Messenger = ut.class() do

local date_utc_now_sql, date_utc_now_add_sql do -- pgsql
  date_utc_now_sql     = "NOW() at time zone 'utc'"
  date_utc_now_add_sql = "NOW() at time zone 'utc' + interval '%d second'"
end

function Messenger:__init(db)
  db.reconnect = 5
  self._channels = {}
  self._channel_names = {}
  self._domains = {} -- cache to domain names
  self._cnn = pg.new(db)
  self._cnn:connect()

  -- luacheck: push ignore eventName
  self._cnn:on('reconnect', function(_, eventName)
    log.info('connected to database.')
  end)

  self._cnn:on('disconnect', function(_, eventName, err)
    log.error('disconnected from database: %s', utils.pgerr_tostring(err))
  end)
  -- luacheck: pop

  return self
end

function Messenger:_start_channel(info)
  local channel_uuid      = info[1]
  local channel_name      = utils.coalesce(info[2])
  local channel_transport = utils.coalesce(info[3])
  local channel_settings  = info[4] and json.decode(info[4])
  local domain_uuid       = utils.coalesce(info[5])
  local domain_name       = utils.coalesce(info[6])

  if not channel_name then
    return log.critical('can not start channel [%s] - no name',  channel_uuid)
  end

  local full_name = channel_name .. '@' .. (domain_name or 'GLOBAL')

  if not channel_transport then
    return log.critical('can not start channel [%s][%s] - no transport', full_name, channel_uuid)
  end

  log.info('starting channel [%s][%s][%s] ...', channel_transport, full_name, channel_uuid)

  local Channel = Channels[channel_transport]
  if not Channel then
    return log.error('can not start channel [%s][%s] - unsupported transport [%s]',
      full_name, channel_uuid, channel_transport)
  end

  local channel = self._channels[channel_uuid]
  if channel then
    return log.warning('channel [%s/%s] already started', full_name, channel_uuid)
  end

  local channel_info = {
    uuid        = channel_uuid;
    name        = channel_name;
    transport   = channel_transport;
    settings    = channel_settings;
    domain_uuid = domain_uuid;
    domain_name = domain_name;
  }

  channel = Channel.new(self, channel_info)
  if channel then
    log.info('channel [%s][%s][%s] created', channel_transport, full_name, channel_uuid)
    self:add_channel(channel)
  end
end

local select_channel_sql = [[
SELECT
  c.messenger_channel_uuid, c.messenger_channel_name,
  c.messenger_channel_transport, c.messenger_channel_settings,
  c.domain_uuid, d.domain_name
FROM v_messenger_channels c left outer join v_domains d on c.domain_uuid = d.domain_uuid
WHERE c.messenger_channel_enabled = 'true'
]]

function Messenger:domain_by_uuid(domain_uuid, cb)
  local domain_name = self._domains[domain_uuid]
  if domain_name then return cb(domain_name) end

  local sql = 'select domain_name from v_domains where domain_uuid = $1'
  self._cnn:query(sql, {domain_uuid}, function(cnn, err, res) -- luacheck: ignore cnn
    if err then
      log.error('can not find domain: %s', utils.pgerr_tostring(err))
      return cb()
    end
    domain_name = res[1] and res[1][1]
    if domain_name then
      self._domains[domain_uuid] = domain_name
      self._domains[domain_name] = domain_uuid
    end
    return cb(domain_name)
  end)
end

function Messenger:domain_by_name(domain_name, cb)
  local domain_uuid = self._domains[domain_name]
  if domain_uuid then return cb(domain_uuid) end

  local sql = 'select domain_uuid from v_domains where domain_name = $1'
  self._cnn:query(sql, {domain_name}, function(cnn, err, res) -- luacheck: ignore cnn
    if err then
      log.error('can not find domain: %s', utils.pgerr_tostring(err))
      return cb()
    end
    domain_uuid = res[1] and res[1][1]
    if domain_uuid then
      self._domains[domain_uuid] = domain_name
      self._domains[domain_name] = domain_uuid
    end
    return cb(domain_uuid)
  end)
end

function Messenger:channel(name)
  return self._channels[name] or self._channel_names[name]
end

function Messenger:flush_domains()
  self._domains = {}
end

function Messenger:rescan_channels()
  local sql = select_channel_sql
  self._cnn:query(sql, function(_, err, res)
    if err then
      log.error('can not select channels: %s', utils.pgerr_tostring(err))
      return
    end

    for _, info in ipairs(res) do
      self:_start_channel(info)
    end
  end)
end

function Messenger:start_channel(channel_uuid)
  local sql = select_channel_sql .. [[
  AND c.messenger_channel_uuid = $1
]]
  self._cnn:query(sql, {channel_uuid}, function(_, err, res, count)
    if err then
      log.error('can not select channel: %s', utils.pgerr_tostring(err))
      return
    end

    if count == 0 then
      log.warning('can not find channel: %s', channel_uuid)
      return
    end

    for _, info in ipairs(res) do
      self:_start_channel(info)
    end
  end)
end

function Messenger:remove_channel(channel_uuid, close)
  local channel = self._channels[channel_uuid]
  if channel then
    if close then
      channel:close(function()
        self._channels[channel_uuid] = nil
        self._channel_names[channel:name()] = nil
      end)
    else
      self._channels[channel_uuid] = nil
      self._channel_names[channel:name()] = nil
    end
  end
  return channel
end

function Messenger:add_channel(channel)
  self._channels[channel:id()] = channel
  self._channel_names[channel:name()] = channel
  return channel
end

function Messenger:send(route, message)
  local parameters, channel_name, settings = message.params, utils.unpack(route, 1, 2)
  local channel = self:channel(channel_name)
  if settings then
    settings = utils.deep_render(settings, message, parameters)
  end

  if not channel then
    log.error('unknown channel uuid or name [%s]', channel_name)
  else
    channel:send(message, settings)
  end
end

function Messenger:resend(message_uuid)
  return self:_resend(
    "messenger_message_uuid=$1",
    {message_uuid}
  )

end

function Messenger:_resend(cond, params)
  local sql = [[
    SELECT messenger_message_uuid, messenger_channel_uuid, messenger_message_destination,
           messenger_message_type, messenger_message_subject,
           messenger_message_content_type, messenger_message_data,
           messenger_message_settings, messenger_message_category,
           messenger_message_direction, messenger_message_source,
           d.domain_uuid, d.domain_name
      FROM v_messenger_messages m left outer join v_domains d on m.domain_uuid = d.domain_uuid
      WHERE ]] .. cond

  self._cnn:query(sql, params, function(_, err, res)
    if err then
      return log.error('can not select message: %s', utils.pgerr_tostring(err))
    end

    for _, row in ipairs(res) do
      local
        message_uuid,
        channel_uuid,
        message_destination,
        message_type,
        message_subject,
        message_content_type,
        message_data,
        message_settings,
        message_category,
        message_direction,
        message_source,
        domain_uuid, domain_name = utils.unpack(row)

      local message = {
        domain_uuid        = utils.coalesce(domain_uuid);
        domain_name        = utils.coalesce(domain_name);
        uuid               = message_uuid;
        type               = message_type;
        category           = message_category;
        direction          = message_direction;
        source             = message_source;
        destination        = message_destination;
        subject            = utils.coalesce(message_subject);
        content_type       = utils.coalesce(message_content_type);
        text               = message_data;
      }
      message.destination_number = ut.split_first(message_destination, '@', true);

      local channel = self._channels[channel_uuid]
      if not channel then
        log.error('unknown channel uuid or name [%s]', channel_uuid)
      else
        channel:send(message, utils.coalesce(message_settings))
      end
    end
  end)
end

function Messenger:message_register(channel_uuid, message, settings, cb)
  local cnn = self._cnn

  if settings then settings = json.encode(settings) end

  local expire = tonumber(message.expire) or 3600

  local sql, params
  -- we already have this message in database
  if message.uuid then
    sql = [[
       UPDATE v_messenger_messages SET
         messenger_channel_uuid = $1,
         messenger_message_settings = $2,
         messenger_message_status = $3,
         messenger_message_status_text = NULL,
         messenger_message_status_time = ]] .. date_utc_now_sql .. [[,
         messenger_message_expire_at = ]] .. date_utc_now_add_sql:format(expire) .. [[
       WHERE
         messenger_message_uuid = $4
    ]]
    params = {channel_uuid, settings or pg.NULL, STATUS.SENDING, message.uuid}
  else
    message.uuid = Uuid.new()
    sql = [[
    INSERT INTO v_messenger_messages(
      messenger_message_uuid,
      messenger_channel_uuid,
      domain_uuid,
      messenger_message_direction,
      messenger_message_source,
      messenger_message_source_proto,
      messenger_message_source_destination,
      messenger_message_destination,
      messenger_message_destination_proto,
      messenger_message_type,
      messenger_message_category,
      messenger_message_subject,
      messenger_message_content_type,
      messenger_message_data,
      messenger_message_settings,
      messenger_message_status,
      messenger_message_time,
      messenger_message_status_time,
      messenger_message_expire_at
    )
    VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12, $13, $14, $15, $16, ]] ..
    date_utc_now_sql .. ', ' ..
    date_utc_now_sql .. ', ' ..
    date_utc_now_add_sql:format(expire) .. ')'

    params = {
      message.uuid,
      channel_uuid,
      message.domain_uuid or pg.NULL,
      message.direction,
      message.source,
      message.source_proto or pg.NULL,
      message.source_destination or pg.NULL,
      message.destination,
      message.destination_proto or pg.NULL,
      message.type or pg.NULL,
      message.category or pg.NULL,
      message.subject or pg.NULL,
      message.content_type or pg.NULL,
      message.text,
      settings or pg.NULL,
      STATUS.SENDING,
    }
  end

  cnn:query(sql, params, function(_, err, res)
    if err then
      log.error('can not create message: %s', utils.pgerr_tostring(err))
      return
    end
    return cb and cb(message.uuid, res)
  end)
end

function Messenger:message_mark(message_uuid, id, cb)
  local cnn = self._cnn

  assert(id ~= nil)

  local sql = "UPDATE v_messenger_messages SET messenger_message_id = $1 WHERE messenger_message_uuid = $2"
  local params = {tostring(id), message_uuid}
  cnn:query(sql, params, function(_, err, res, count)
    if err then
      log.error('can not mark message: %s', utils.pgerr_tostring(err))
      return
    end
    return cb and cb(res, count)
  end)
end

function Messenger:message_status(message_uuid, channel_uuid, status, status_message, cb)
  local cnn = self._cnn

  local sql = [[UPDATE v_messenger_messages
    SET messenger_message_status = $1,
    messenger_message_status_text = $2,
    messenger_message_status_time = ]] .. date_utc_now_sql .. [[
    WHERE messenger_message_status = $3
      AND ( messenger_message_uuid = $4
      OR ( messenger_channel_uuid = $5 AND messenger_message_id = $6))
  ]]

  local params = { status, status_message or pg.NULL, STATUS.SENDING,
    utils.is_uuid(message_uuid) or pg.NULL,
    channel_uuid, message_uuid
  }

  cnn:query(sql, params, function(_, err, res, count)
    if err then
      log.error('can not set status message: %s', utils.pgerr_tostring(err))
      return
    end
    return cb and cb(res, count)
  end)
end

function Messenger:channels()
  local res = {}
  for _, channel in pairs(self._channels) do
    res[#res + 1] = {channel:id(), channel:name()}
  end
  return res
end

end
----------------------------------------------------------------------

return Messenger