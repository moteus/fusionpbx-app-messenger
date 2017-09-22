require "resources.functions.split"

local log           = require "resources.functions.log".messenger_client
local json          = require "resources.functions.lunajson"
local api           = require "resources.functions.api"
local Consumer      = require "resources.functions.event_consumer"
local IntervalTimer = require "resources.functions.interval_timer"
local Database      = require "resources.functions.database"

local function create_uuid()
	return api:executeString("create_uuid")
end

local function send_recv(event, timeout)
	local request_uuid = create_uuid()
	local timer        = IntervalTimer.new(timeout):start()
	local events       = freeswitch.EventConsumer('CUSTOM', 'messenger::response')

	event:addHeader('Messenger-Response-UUID', request_uuid)
	event:fire()

	local response
	for event in Consumer.ievents(events, timer:rest()) do
		local response_uuid = event and event:getHeader('Messenger-Response-UUID')
		if request_uuid == response_uuid then
			response = event
			break
		end
		if timer:rest() == 0 then
			break
		end
	end

	events:cleanup()

	if not response then return end

	local res = response:getHeader('Messenger-Response')
	if not res then
		if 'application/json' == response:getHeader('Content-Type') then
			local body = response:getBody()
			res = body and json.decode(body)
		end
	end
	return res, response
end

local last_message_elapsed_sql = [[
SELECT extract(epoch from (NOW() at time zone 'utc')-messenger_message_time)
  FROM public.v_messenger_messages
  WHERE messenger_message_destination = :message_destination
    AND messenger_message_type = :message_type
    AND messenger_message_category = :message_category
  ORDER BY messenger_message_time DESC
  LIMIT 1
]]

local function last_message_elapsed(typ, cat, dst)
	local dbh = Database.new('system')
	local elapsed
	if dbh then
		elapsed = dbh:first_value(last_message_elapsed_sql, {
			message_destination = dst;
			message_type        = typ;
			message_category    = cat;
		})
		dbh:release()
		elapsed = tonumber(elapsed)
		
	end
	return elapsed and math.floor(elapsed)
end

local MessengerClient = {} do
MessengerClient.__index = MessengerClient

function MessengerClient.new(domain)
	local self = setmetatable({
		_domain = domain,
		_conf_timout = 1000,
	}, MessengerClient)

	return self
end

local function split_message_type(number, default)
	local typ, num = split_first(number, ':', true)
	if typ then return typ, num end
	return default or 'sms', number
end

function MessengerClient:_resend(timeout, message_uuid)
	local event = freeswitch.Event('CUSTOM', 'messenger::resend')
	event:addHeader('Message-UUID', message_uuid)
	if timeout and timeout > 0 then
		return send_recv(event, timeout)
	end
	event:fire()
	return true
end

---
-- @param timeout number of seconds. If false or 0 then do not wait response from messenger
-- @param channel channel uuid or channel name with domain name.
--    If set then messenger service will send message to this channel without routing.
-- @param context messenger routing context. By default using domain name for inbound/local
--   messages and public for inbound messages.
-- @param direction can be `local`, `inbound` or `outbound`
-- @param category any string. this string just save in database
-- @param source source string in format `<source_type>:<source>` (e.g. `sip:100@domain.com`)
-- @param destination destination string in format `<destination_type>:<destination>` (e.g. `email:user@domain.com`)
-- @param subject subject string. This string can be displayed by several channels. E.g. email channel.
-- @param text message itself
-- @param expire in seconds. how long try delivery this message
-- @param params
function MessengerClient:_send(timeout, channel, context, direction, category, source, destination, subject, text, ...)
	local expire, params
	if ... then
		if type(...) == 'table' then
			params = ...
		else
			expire, params = ...
		end
	end

	local dst_typ, src_typ
	dst_typ, destination = split_message_type(destination)
	src_typ, source = split_message_type(source, 'sip')

	expire = tostring(tonumber(expire))

	local event = freeswitch.Event('CUSTOM', 'messenger::send')

	event:addHeader('Message-Type',               dst_typ      )
	event:addHeader('Message-Category',           category     )
	event:addHeader('Message-Direction',          direction    )
	event:addHeader('Message-Source',             source       )
	event:addHeader('Message-Source-Proto',       src_typ      )
	event:addHeader('Message-Source-Destination', destination  )
	event:addHeader('Message-Destination',        destination  )
	event:addHeader('Message-Destination-Proto',  dst_typ      )
	if expire then
		event:addHeader('Message-Expire',         expire       )
	end
	if channel then
		event:addHeader('Message-Channel',        channel      )
	end
	if context then
		event:addHeader('Message-Context',        context      )
	end
	if subject then
		event:addHeader('Message-Subject',        subject      )
	end

	-- event:getHeader('Message-Domain-UUID')
	-- event:getHeader('Message-Subject')
	-- event:getHeader('Message-Context')

	local body
	if #text <= 255 then event:addHeader('Message-Text', text)
	else body = {message = text} end

	if params then
		if not body then body = {} end
		body.parameters = params
	end

	if body then
		event:addHeader('Content-Type', 'application/json')
		event:addBody(json.encode(body))
	end

	if timeout and timeout > 0 then
		return send_recv(event, timeout)
	end

	event:fire()

	return true
end

function MessengerClient:send(...)
	return self:_send(false, nil, nil, ...)
end

function MessengerClient:sendSync(timeout, ...)
	assert(timeout and timeout > 0)
	return self:_send(timeout, nil, nil, ...)
end

function MessengerClient:sendViaChannel(channel, ...)
	return self:_send(false, channel, nil, ...)
end

function MessengerClient:sendViaChannelSync(timeout, channel, ...)
	assert(timeout and timeout > 0)
	return self:_send(timeout, channel, nil, ...)
end

function MessengerClient:sendViaContext(context, ...)
	return self:_send(false, nil, context, ...)
end

function MessengerClient:sendViaContextSync(timeout, context, ...)
	assert(timeout and timeout > 0)
	return self:_send(timeout, nil, context, ...)
end

function MessengerClient:sendViaChatplan(proto, context, direction, category, source, destination, text, ...)
	local expire, params
	if ... then
		if type(...) == 'table' then
			params = ...
		else
			expire, params = ...
		end
	end

	local from_full = source
	if from_proto == 'sip' and not string.find(source, '^sip:') then
		from_full = 'sip:' .. from_full
	end

	local from_user, from_host --! todo
	local to_user, to_host --! todo

	expire = tostring(tonumber(expire) or 3600)

	local event = freeswitch.Event("CUSTOM", "SMS::SEND_MESSAGE"    )
	event:addHeader("proto",               proto                    )
	event:addHeader("dest_proto",          "GLOBAL"                 )
	event:addHeader("context",             context                  )
	event:addHeader("skip_global_process", "false"                  )

	event:addHeader("from",                source                   )
	event:addHeader("from_user",           from_user                )
	event:addHeader("from_host",           from_host                )
	event:addHeader("from_full",           from_full                )

	event:addHeader("to",                  destination              )
	event:addHeader("to_user",             to_user                  )
	event:addHeader("to_host",             to_host                  )

	event:addHeader("subject",             category                 )
	event:addHeader("type",                "text/plain"             )
	event:addHeader("hint",                "expire: " .. expire     )
	event:addHeader("direction",           direction                )

	if params then for k, v in pairs(params) do
		event:addHeader('param_' .. k, v)
	end end

	event:addBody(text)

	event:fire()
end

function MessengerClient:resend(...)
	return self:_resend(false, ...)
end

function MessengerClient:resendSync(timeout, ...)
	assert(timeout and timeout > 0)
	return self:_resend(timeout, ...)
end

-- Configuration methods

function MessengerClient:channelsRescan(timeout)
	local event = freeswitch.Event('CUSTOM', 'messenger::configure')
	event:addHeader('Messenger-Action',  'channels-rescan')
	return send_recv(event, timeout or self._conf_timout)
end

function MessengerClient:routeReload(timeout)
	local event = freeswitch.Event('CUSTOM', 'messenger::configure')
	event:addHeader('Messenger-Action',  'route-reload')
	return send_recv(event, timeout or self._conf_timout)
end

function MessengerClient:channelKill(channel, timeout)
	local event = freeswitch.Event('CUSTOM', 'messenger::configure')
	event:addHeader('Messenger-Action', 'channel-close')
	event:addHeader('Messenger-Action-Argument', channel)
	return send_recv(event, timeout or self._conf_timout)
end

function MessengerClient:channelStart(channel, timeout)
	local event = freeswitch.Event('CUSTOM', 'messenger::configure')
	event:addHeader('Messenger-Action', 'channel-start')
	event:addHeader('Messenger-Action-Argument', channel)
	return send_recv(event, timeout or self._conf_timout)
end

function MessengerClient:channelsList(timeout)
	local event = freeswitch.Event('CUSTOM', 'messenger::configure')
	event:addHeader('Messenger-Action',  'channels-list')
	return send_recv(event, timeout or self._conf_timout)
end

-- Some statistic functions

function MessengerClient:lastSendElapsed(typ, cat, dst)
	return last_message_elapsed(typ, cat, dst)
end

end

return MessengerClient