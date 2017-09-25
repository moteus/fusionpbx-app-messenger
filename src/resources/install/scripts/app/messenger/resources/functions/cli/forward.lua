local message = message
if not message then
	return log.err('this action should be called only from chatplan')
end

local json = require "resources.functions.lunajson"

local function urldecode(str)
	return (string.gsub(str, "%%(%x%x)", function(ch)
		return string.char(tonumber(ch, 16))
	end))
end

local function dump_event(event)
	local class = event:getHeader('Event-Subclass')
	local name  = event:getHeader('Event-Name')
	if class then name = name .. '::' .. class end

	-- decade and trim quote characters
	freeswitch.consoleLog("info", name .. ":\n" .. urldecode(event:serialize()):sub(2, -2) .. "\n")
end

local function decode_route(dest)
	if not dest then return end

	local mode, data = split_first(dest, '/', true)
	if not data then return end

	if mode == 'channel' then
		local type, channel = split_first(data, ':')
		if not channel then return end
		return mode, type, channel
	end

	if mode == 'router' then
		local type, context = split_first(data, ':')
		if not context then return end
		return mode, type, context
	end
end

local function H(h, ...)
	local v = message:getHeader(h)
	if v then return v end
	if ... then return H(...) end
end

local direction = argv[2]
local route_mode, message_type, route_info = decode_route(argv[3])
local destination = argv[4] or message:getHeader('to')

if not direction then
	return log.err('no direction specify')
end

local directions = {['local'] = true, inbound = true, outbound = true}
if not directions[direction] then
	return log.err('unsupported direction')
end

if not argv[3] then
	return log.err('no route specify')
end

if not route_mode then
	return log.err('invalid route parameters')
end

if not destination then
	return log.err('no destination specify')
end

local dest_info = {
	['Message-Direction'          ] = direction;
	['Message-Type'               ] = message_type;
	['Message-Category'           ] = H('category', 'hint');
	['Message-Subject'            ] = H('subject');
	['Message-Source'             ] = H('from');
	['Message-Source-Proto'       ] = H('from_proto', 'proto');
	['Message-Source-Destination' ] = H('to_source', 'to');
	['Message-Destination'        ] = destination;
	['Message-Destination-Proto'  ] = message_type;
	['Message-Domain-UUID'        ] = H('domain_uuid');
	['Message-Expire'             ] = H('expire');
	['Content-Type'               ] = 'application/json';
}

if route_mode == 'channel' then
	dest_info['Message-Channel'] = route_info
end

if route_mode == 'router' then
	dest_info['Message-Context'] = route_info
end

local msg = json.encode{
	message = message:getBody(),
	content_type = H('type') or 'text/plain'
}

local event = freeswitch.Event('CUSTOM', 'messenger::send')
for name, value in pairs(dest_info) do
	event:addHeader(name, value)
end

event:addBody(msg)

local msg = ("[messenger] Forward message from [%s:%s] to [%s:%s] via %s/%s \n"):format(
	event:getHeader('Message-Source-Proto'), event:getHeader('Message-Source'),
	event:getHeader('Message-Destination-Proto'), event:getHeader('Message-Destination'),
	route_mode, route_info
)

freeswitch.consoleLog('notice', msg)

event:fire()

dump_event(event)
