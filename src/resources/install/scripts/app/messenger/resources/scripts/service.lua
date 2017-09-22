-- hack to be able easy run this script from current dir.
if not pcall(require,  'app.messenger.resources.classes.Messenger.Utils') then
  package.path = '../../../../?.lua;' .. package.path
end

local function messenger_require(name)
  return require ('app.messenger.resources.classes.' .. name)
end

require "resources.functions.config"

local pp            = require "pp"
local uv            = require "lluv"
local ut            = require "lluv.utils"
local esl           = require "lluv.esl"
local stp           = require "StackTracePlus"

local EventService  = messenger_require "Messenger.Service"
local utils         = messenger_require "Messenger.Utils"
local Messenger     = messenger_require "Messenger.Messenger"
local Router        = messenger_require "Messenger.Router"
local Logger        = messenger_require "Messenger.Logger"

local json          = utils.json
local log           = Logger.get('messenger')

local service_name  = 'messenger'

assert(database.type == "pgsql")

local _, db_name, db_user, db_pass = ut.usplit(database.system, ':/?/?')

--! @todo read params from configuration
local service = EventService.new(service_name, { '127.0.0.1', '8021', 'ClueCon',
  reconnect = 5; no_execute_result = true; no_bgapi = true;
  subscribe = {
    'CUSTOM::messenger::send',
    'CUSTOM::messenger::resend',
    'CUSTOM::messenger::configure',
  };
  emit_plain = true;
})

Logger.set(service:logger())

local messenger = Messenger.new{
  database = db_name,
  user     = db_user,
  password = db_pass,
  config = {
    application_name = 'fusion-messenger'
  },
}

local router = Router.new(messenger)

messenger.__ROUTER  = router

messenger.__SERVICE = service

function service:sendResponse(event, response)
  local response_uuid = event:getHeader('Messenger-Response-UUID')
  if response_uuid then
    local event = esl.Event('CUSTOM', 'messenger::response')
    event:addHeader('Messenger-Response-UUID', response_uuid)
    if type(response) == 'string' then
      event:addHeader('Messenger-Response', response)
    else
      event:addBody(json.encode(response), 'application/json')
    end
    service:sendEvent(event)
  end
end

local function decode_message(event, cb)
  local message_uuid               = event:getHeader('Message-UUID')
  local message_type               = event:getHeader('Message-Type')
  local message_category           = event:getHeader('Message-Category')
  local domain_uuid                = event:getHeader('Message-Domain-UUID')
  local message_direction          = event:getHeader('Message-Direction') or 'outbound'
  local message_subject            = event:getHeader('Message-Subject')
  local message_source             = event:getHeader('Message-Source')
  local message_source_destination = event:getHeader('Message-Source-Destination')
  local message_source_proto       = event:getHeader('Message-Source-Proto')
  local message_destination        = event:getHeader('Message-Destination')
  local message_destination_proto  = event:getHeader('Message-Destination-Proto')
  local message_text               = event:getHeader('Message-Text')
  local message_expire             = event:getHeader('Message-Expire')
  local message_context            = event:getHeader('Message-Context')
  local content_type               = event:getHeader('Content-Type')
  local message_channel            = event:getHeader('Message-Channel')
  local body                       = event:getBody()

  local message_params, message_content_type
  if body then
    if content_type == 'application/json' then
      local t = json.decode(body)
      if t then
        if not message_text then
          message_text = t.message
          message_content_type = t.content_type
        end
        message_params = t.parameters
      end
    elseif not message_text then
      message_text = body
      message_content_type = content_type
    end
  end

  local message = {
    domain_uuid        = domain_uuid;
    uuid               = message_uuid;
    type               = message_type;
    category           = message_category;
    direction          = message_direction;
    source             = message_source;
    source_destination = message_source_destination;
    source_proto       = message_source_proto;
    destination        = message_destination;
    destination_proto  = message_destination_proto;
    destination_number = ut.split_first(message_destination, '@', true);
    subject            = message_subject;
    text               = message_text;
    expire             = message_expire;
    context            = message_context;
    params             = message_params;
    content_type       = message_content_type;
    channel            = message_channel;
  }

  local number
  if domain_uuid then
    messenger:domain_by_uuid(domain_uuid, function(domain_name)
      message.domain_name = domain_name
      if domain_name then
        cb(message)
      else
        log.error('can not find domain name for [%s]', domain_uuid)
      end
    end)
  elseif message_direction == 'outbound' then
    number = message.source
  elseif message_direction == 'inbound' or message_direction == 'local' then
    number = message.destination
  end

  if number then
    local number, domain_name = ut.split_first(number, '@', true)
    if domain_name then
      message.domain_name = domain_name
      messenger:domain_by_name(domain_name, function(domain_uuid)
        message.domain_uuid = domain_uuid
        if domain_uuid then
          cb(message)
        else
          log.error('can not find domain name for [%s]', domain_name)
        end
      end)
    else
      log.error('can not detect domain name for [%s]', number)
    end
  end
end

service:on('CUSTOM::messenger::send', function(self, eventName, event)
  log.trace(eventName)

  decode_message(event, function(message)
    log.debug_dump(pp.format, message)

    -- send via specific channel
    if message.channel then
      channel = messenger:channel(message.channel)
      if not channel then
        log.error('can not found channel [%s]', message_channel)
        return
      end
      return channel:send(message)
    end

    router:send(message)
  end)

  self:sendResponse(event, '+OK')
end)

service:on('CUSTOM::messenger::resend', function(self, eventName, event)
  local message_uuid = event:getHeader('Message-UUID')
  log.info('resend message [%s]', message_uuid)
  messenger:resend(message_uuid)
  self:sendResponse(event, '+OK')
end)

service:on('CUSTOM::messenger::configure', function(self, eventName, event)
  local action = event:getHeader('Messenger-Action')

  if action == 'channels-rescan' then
    log.info('rescan channels')
    messenger:rescan_channels()
    return self:sendResponse(event, '+OK')
  end

  if action == 'channels-list' then
    log.info('list channels')
    local channels = messenger:channels()
    return self:sendResponse(event, channels)
  end

  if action == 'channel-close' then
    local channel_uuid = event:getHeader('Messenger-Action-Argument')
    if not utils.is_uuid(channel_uuid) then
      return self:sendResponse(event, '-ERR invalid channel uuid')
    end

    local channel = messenger:remove_channel(channel_uuid, true)
    if channel then
      log.info('closing channel [%s]',  channel_uuid)
    else
      log.error('can not find channel [%s]',  channel_uuid)
    end
    return self:sendResponse(event, '+OK')
  end

  if action == 'channel-start' then
    local channel_uuid = event:getHeader('Messenger-Action-Argument')
    if not utils.is_uuid(channel_uuid) then
      return self:sendResponse(event, '-ERR invalid channel uuid')
    end

    log.info('starting channel [%s]',  channel_uuid)
    messenger:start_channel(channel_uuid)
    return self:sendResponse(event, '+OK')
  end

  if action == 'route-reload' then
    log.info('route reload')
    router:reload()
    return self:sendResponse(event, '+OK')
  end

  if action == 'cache-flush' then
    log.info('flush cache')
    messenger:flush_domains()
    return self:sendResponse(event, '+OK')
  end

  return self:sendResponse(event, string.format('-ERR unknown action: %s', tostring(action)))
end)

uv.defer(function()
  messenger:rescan_channels()
  router:reload()
end)

do service:run(stp.stacktrace) end