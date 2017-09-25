-- usage
if not argv[1] then
	stream:write('  service [start|stop|status]\n')
	return
end

local api = require "resources.functions.api"

local action = argv[2]

if action == 'start' then
	local response = api:execute('luarun', 'app/messenger/resources/scripts/service.lua')
	stream:write(response)
	return
end

if action == 'stop' then
	local response = api:execute('lua', 'service messenger stop')
	stream:write(response)
	return
end

if action == 'status' then
	local response = api:execute('lua', 'service messenger status')
	stream:write(response)
	return
end

return stream:write('-ERR invalid action for service command')

