-- usage
if not argv[1] then
	stream:write('  channel [kill|start] <channel uuid>\n')
	return
end

local action = argv[2]
if action == 'kill' then
	local uuid = argv[3]
	if not uuid then
		stream:write('-ERR no channel uuid')
	else
		local response, event = messenger:channelKill(uuid)
		if response then
			stream:write(response)
		else
			stream:write('-ERR no response')
		end
	end
	return
end

if action == 'start' then
	local uuid = argv[3]
	if not uuid then
		stream:write('-ERR no channel uuid')
	else
		local response, event = messenger:channelStart(uuid)
		if response then
			stream:write(response)
		else
			stream:write('-ERR no response')
		end
	end
	return
end

return stream:write('-ERR invalid action for channel command')