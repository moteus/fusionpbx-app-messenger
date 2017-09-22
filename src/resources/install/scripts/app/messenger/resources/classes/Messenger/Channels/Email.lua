local function messenger_require(name)
  return require ('app.messenger.resources.classes.' .. name)
end

local ut            = require "lluv.utils"

local utils         = messenger_require "Messenger.Utils"
local BaseChannel   = messenger_require "Messenger.Channels.Base"
local AsyncRequest  = messenger_require "Messenger.AsyncRequest"
local Logger        = messenger_require "Messenger.Logger"

local log           = Logger.get('chann.email')
local STATUS        = utils.STATUS

local EmailChannel = ut.class(BaseChannel) do

local super = ut.class.super(EmailChannel)

function EmailChannel:__init(messenger, channel_info)
  self = super(self, '__init', messenger, channel_info)

  self._settings  = channel_info.settings
  self._request   = AsyncRequest.new{concurent = 5}

  log.info("[%s] ready", self:name())

  return self
end

function EmailChannel:send(message, settings)
  local messenger = self._messenger
  local request = self._request

  local to = {
      address = message.destination,
      -- charset = "utf-8"
      -- title   = ....
  }

  local subject = {
    title = message.subject or settings and settings.subject or self._settings.subject,
    charset = "utf-8"
  }

  local text = {message.text;
    mime_type = message.content_type;
    charset   = "utf-8";
  }

  local SMTP = {
    server  = settings and settings.server or self._settings.server;
    from    = settings and settings.from or self._settings.from;
    to      = settings and settings.to or self._settings.to or to;
    message = {subject, text};
  }

  messenger:notification_register(self:id(), message, settings, function(message_uuid, res) -- luacheck: ignore res
    --! @todo check `res` code
    request:sendmail(SMTP, function(err, result, code, response)
      local msg
      if err then
        msg = tostring(err)
      else
        msg = string.format("[%s] %s", tostring(code), response)
      end
      if err or not utils.is_2xx(code) then
        log.error('[%s] can not send email: %s', self:name(), msg)
        messenger:notification_status(message_uuid, self:id(), STATUS.FAIL, msg)
      else
        log.info("[%s] send email done: %s %s %s",
          self:name(), tostring(result), tostring(code), tostring(response)
        )
        messenger:notification_status(message_uuid, self:id(), STATUS.SUCCESS, msg)
      end
    end)
  end)
end

end

return EmailChannel