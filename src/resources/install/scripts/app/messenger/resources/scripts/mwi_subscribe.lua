require "resources.functions.config"
require "resources.functions.split"

-- luacheck: ignore split_first

local function messenger_require(name)
  return require ('app.messenger.resources.classes.' .. name)
end

local service_name = "mwi_messenger"

local log               = require "resources.functions.log"[service_name]
local Database          = require "resources.functions.database"
local BasicEventService = require "resources.functions.basic_event_service"
local MessengerClient   = messenger_require "Messenger.Client"

local service = BasicEventService.new(log, service_name)
local messenger = MessengerClient.new()

local resend_sip_messages, check_expire_messages do

local select_sql = [[
SELECT messenger_message_uuid
  FROM v_messenger_messages
  WHERE messenger_message_status = 'fail'
    AND (
      messenger_message_status_text = 'error/user_not_registered'
      OR messenger_message_status_text = 'Sync send - fail (0)'
    )
    AND messenger_message_expire_at >= NOW() at time zone 'utc'
    AND messenger_message_destination = :destination
  ORDER BY messenger_message_expire_at ASC
]]

local aquire_sql = [[
UPDATE v_messenger_messages
  SET messenger_message_status  = 'wait',
    messenger_message_status_time = (NOW() at time zone 'utc')
  WHERE messenger_message_uuid = :uuid
    AND messenger_message_status = 'fail'
]]

local expire_sql = [[
  UPDATE v_messenger_messages
    SET messenger_message_status = 'fail',
      messenger_message_status_time = (NOW() at time zone 'utc'),
      messenger_message_status_text = (case
        when messenger_message_status = 'sending' then 'expire'
        else messenger_message_status_text
      end)
    WHERE
      (messenger_message_status = 'wait'
        AND messenger_message_status_time < NOW() at time zone 'utc' + :seconds * interval '1 second')
      OR (messenger_message_status = 'sending'
        AND messenger_message_expire_at < NOW() at time zone 'utc')
]]

resend_sip_messages = function(self, user)
	local dbh = Database.new('system')
	if not dbh then return end

	local messages = dbh:fetch_all(select_sql, {destination=user})

	if not messages then return end

	for _, message in ipairs(messages) do
		local ok, err = dbh:query(aquire_sql, {uuid = message.messenger_message_uuid})
		if not ok then
			log.errf('can not aquire message: ', tostring(err))
		else
			local rows = dbh:affected_rows()
			if rows == 1 then
				self:resend(message.messenger_message_uuid)
			end
		end
	end
	dbh:release()
end

check_expire_messages = function (self, seconds) -- luacheck: ignore self
	local dbh = Database.new('system')
	if not dbh then return end
	dbh:query(expire_sql, {seconds = -seconds})
	local rows = dbh:affected_rows()
	dbh:release()
	return rows
end

end

service:bind("MESSAGE_QUERY", function(self, eventName, event) -- luacheck: ignore self eventName
	local account_header = event:getHeader('Message-Account')
	if not account_header then
		return log.warningf("MWI message without `Message-Account` header")
	end

	local proto, account = split_first(account_header, ':', true)

	if (not account) or (proto ~= 'sip' and proto ~= 'sips') then
		return log.warningf("invalid format for voicemail id: %s", account_header)
	end

	pcall(
		resend_sip_messages, messenger, account
	)
end)

service:onInterval(60000, function()
	pcall(
		check_expire_messages, messenger, 30
	)
end)

log.notice("start")

service:run()

log.notice("stop")
