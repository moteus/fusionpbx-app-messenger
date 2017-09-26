local function messenger_require(name)
  return require ('app.messenger.resources.classes.' .. name)
end

local uv            = require "lluv"
local ut            = require "lluv.utils"
local esl           = require "lluv.esl"

local utils         = messenger_require "Messenger.Utils"
local BaseChannel   = messenger_require "Messenger.Channels.Base"
local Logger        = messenger_require "Messenger.Logger"

local log           = Logger.get('chann.sip')
local STATUS        = utils.STATUS

local function sip_contact(cnn, user, cb)
  cnn:api("sofia_contact " .. user, function(self, err, reply)
    local contact
    if not err then
      contact = (reply:getHeader('Content-Type') == 'api/response') and reply:getBody()
    end

    return cb(self, err, contact, reply)
  end)
end

local function sip_message_continue(cnn, options, cb)
  local event = esl.Event('custom', 'SMS::SEND_MESSAGE');

  event:addHeader('proto',      'sip');
  event:addHeader('dest_proto', 'sip');

  event:addHeader('from',      options.from)
  event:addHeader('from_full', 'sip:' .. options.from)

  event:addHeader('to',          options.to)
  event:addHeader('sip_profile', options.profile)
  event:addHeader('subject',     options.subject or 'SIP SIMPLE')

  if options.waitReport then
    event:addHeader('blocking', 'true')
  end

  local content_type = options.type or 'text/plain'
  event:addBody(options.body, content_type)
  event:addHeader('type', content_type)

  cnn:sendEvent(event, function(self, err, reply)
    if err then
      return cb(self, err)
    end

    local uuid = reply:getReplyOk()
    if not uuid then
      return cb(self, nil, reply)
    end

    local eventName = 'esl::event::CUSTOM::' .. uuid

    local timeout

    cnn:on(eventName, function(self, event, reply) -- luacheck: ignore self reply event
      if (reply:getHeader('Nonblocking-Delivery') == 'true') or reply:getHeader('Delivery-Failure') then
        self:off(eventName)
        timeout:close()
        cb(self, nil, reply)
      end
    end)

    timeout = uv.timer():start(options.timeout * 1000, function()
      self:off(eventName)
      cb(self, 'timeout')
    end)
  end)
end

local function sip_message(cnn, options, cb)
  assert(options.to)
  assert(options.from)
  options.subject = options.subject  or options.to
  options.body    = options.body     or ''
  options.timeout = options.timeout  or 120

  if (not options.profile) or options.checkContact then
    sip_contact(cnn, options.to, function(self, err, contact, reply)
      local profile
      if contact then
        profile = string.match(contact, '^sofia/(.-)/')
      end
      if profile then
        options.profile = options.profile or profile
        uv.defer(sip_message_continue, cnn, options, cb)
      else
        cb(self, err, reply)
      end
    end)
    return
  end

  return sip_message_continue(cnn, options, cb)
end

local SipChannel = ut.class(BaseChannel) do

local super = ut.class.super(SipChannel)

function SipChannel:__init(messenger, channel_info)
  self = super(self, '__init', messenger, channel_info)

  self._settings  = channel_info.settings

  local host, port, auth = self._settings.host, self._settings.port, self._settings.auth

  self._cnn = esl.Connection{ host, port, auth,
    reconnect = 5, no_execute_result = true, no_bgapi = true;
    subscribe = {'CUSTOM SMS::SEND_MESSAGE'};
    filter    = {
      ["Nonblocking-Delivery"] = "true",
      ["Delivery-Failure"]     = {"true", "false"},
    };
  }

  -- luacheck: push ignore eventName

  self._cnn:on('esl::reconnect', function(_, eventName)
    log.info('[%s] esl connected', self:name())
    log.info("[%s] ready", self:name())
  end)

  self._cnn:on('esl::disconnect', function(_, eventName, err)
    local msg = '[%s] esl disconnected'
    if err then msg = msg .. ': ' tostring(err) end
    log.info(msg, self:name())
  end)

  self._cnn:on('esl::error::**', function(_, eventName, err)
    log.info('[%s] esl error: %s', self:name(), tostring(err))
  end)

  self._cnn:on('esl::close', function(_, eventName, err)
    log.info('[%s] %s %s', self:name(), eventName, err)
  end)

  -- luacheck: pop

  self._cnn:open()

  return self
end

function SipChannel:send(message, settings)
  local messenger = self._messenger
  local cnn = self._cnn

  messenger:message_register(self:id(), message, settings, function(message_uuid, res) -- luacheck: ignore res
    -- @todo check `res` parameter
    local sip_message_optins = {
      from         = message.source;
      to           = message.destination;
      body         = message.text;
      -- profile      = 'internal';
      -- subject      = '----';
      type         = 'text/plain; charset=utf-8';
      waitReport   = true;
      checkContact = true;
      -- timeout      = 120;
    }

    sip_message(cnn, sip_message_optins, function(_, err, res) -- luacheck: ignore res
      if err then
        log.error('[%s] Fail send message: %s', self:name(), err)
        return messenger:message_status(message_uuid, self:id(), STATUS.FAIL, tostring(err))
      end

      -- We send message without waiting response. So we really can not
      -- be sure either this message delivery or not.
      if res:getHeader('Nonblocking-Delivery') == 'true' then
        messenger:message_status(message_uuid, self:id(), STATUS.SUCCESS, 'async send without delivery report')
        return log.info("[%s] Async send - pass", self:name())
      end

      -- We send message with waiting response
      if res:getHeader('Delivery-Failure') then
        local code   = res:getHeader('Delivery-Result-Code') or '--'
        local status, msg
        if res:getHeader('Delivery-Failure') == 'true' then
          status = STATUS.FAIL
          msg = 'Sync send - fail (' .. code .. ')'
        else
          status = STATUS.SUCCESS
          msg = 'Sync send - pass (' .. code .. ')'
        end
        log.info('[%s] %s', self:name(), msg)
        return messenger:message_status(message_uuid, self:id(), status, msg)
      end

      -- E.g. if we use `sip_contact` to get profile and user not registered.
      if nil == res:getReply() then
        local reply = res:getBody() or '----'
        log.info('[%s] Fail send message: %s', self:name(), reply)
        return messenger:message_status(message_uuid, self:id(), STATUS.FAIL, reply)
      end

      -- This can be if `sendEvent` returns error?
      local _, _, reply = res:getReply()
      reply = reply or '----'
      log.info('[%s] Fail send message: %s', self:name(), reply)
      return messenger:message_status(message_uuid, self:id(), STATUS.FAIL, reply)
    end)
  end)
end

function SipChannel:close(cb)
  self._cnn:close(function(_, err)
    if err then
      log.error('[%s] error while closing esl connection: %s', self:name(), err)
    end
    super(self, 'close', cb)
  end)
end

end

return SipChannel