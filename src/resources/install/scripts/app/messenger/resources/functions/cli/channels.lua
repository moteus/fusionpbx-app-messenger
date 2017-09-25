-- usage
if not argv[1] then
	stream:write('  channels [list|rescan]\n')
	return
end

local json = require "resources.functions.lunajson"

local action = argv[2]
if action == 'list' then
	local response = messenger:channelsList()
	if response then
		stream:write(json.encode(response))
	else
		stream:write('-ERR no response')
	end
	return
end

if action == 'rescan' then
	local response = messenger:channelsRescan()
	if response then
		stream:write(response)
	else
		stream:write('-ERR no response')
	end
	return
end

return stream:write('-ERR invalid action for channels command')