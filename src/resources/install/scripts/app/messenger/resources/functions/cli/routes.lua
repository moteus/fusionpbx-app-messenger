-- luacheck: ignore messenger stream freeswitch argv

-- usage
if not argv[1] then
	stream:write('  routes reload\n')
	return
end

local action = argv[2]
if action == 'reload' then
	local response = messenger:routeReload()
	if response then
		stream:write(response)
	else
		stream:write('-ERR no response')
	end
	return
end

return stream:write('-ERR invalid action for routes command')
