-- luacheck: ignore messenger stream freeswitch argv

-- usage
if not argv[1] then
	stream:write('  message resend <message uuid>\n')
	return
end

local action = argv[2]
if action == 'resend' then
	local message_uuid = argv[3]
	if not message_uuid then
		stream:write('-ERR no message uuid')
	else
		local response = messenger:resendSync(30, message_uuid)
		if response then
			stream:write(response)
		else
			stream:write('-ERR no response')
		end
	end
	return
end

return stream:write('-ERR invalid action for message command')