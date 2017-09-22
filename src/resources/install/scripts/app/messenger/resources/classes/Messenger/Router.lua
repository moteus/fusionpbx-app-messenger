local function messenger_require(name)
  return require ('app.messenger.resources.classes.' .. name)
end

local ut            = require "lluv.utils"
local prefix_tree   = require "prefix_tree"

local utils         = messenger_require "Messenger.Utils"
local Logger        = messenger_require "Messenger.Logger"

local log           = Logger.get('router')
local json          = utils.json

----------------------------------------------------------------------
local Router = ut.class() do

function Router:__init(messenger)
  self._messenger  = messenger
  self._contexts   = {}

  return self
end

function Router:send(message)
  local routes = self:find(message)
  if not routes then return end

  for _, route in ipairs(routes) do
    log.info('found route [%s][%s][%s] => [%s]',
      routes.context, message.type, message.destination, route[1]
    )
    self._messenger:send(route, message)
  end
end

function Router:find(message)

  local ctx = message.context
  if not ctx then
    ctx = (message.direction == 'inbound') and 'public' or message.domain_name
  end

  if not ctx then
    return log.error('can not determinate context name')
  end

  local context = self._contexts[ctx]

  if not context then
    return log.error('can not find route for context [%s]', ctx)
  end

  local routes = context[message.type]

  if not routes then
    return log.error('can not find route for message type [%s] in context [%s]', message.type, ctx)
  end

  local route = routes:find(message.destination)

  if not route and message.type == 'email' then
    local _, domain = ut.split_first(message.destination, '@', true)
    if domain then domain = '@' .. domain end
    route = routes:find(domain)
  end

  if not route then
    return log.error('can not find route [%s][%s][%s]', ctx, message.type, message.destination)
  end

  return route
end

function Router:_add(route)
  log.info('add route [%s] [%s] [%s] [%s]', route.context, route.type, route.name, route.destination)

  local context = self._contexts[route.context]
  if not context then
    context = {}
    self._contexts[route.context] = context
  end

  local routes = context[route.type]
  if not routes then
    routes = prefix_tree.new()
    context[route.type] = routes
  end

  routes:add(route.destination, route)
  return self
end

function Router:reload()
  local cnn = self._messenger._cnn

  local sql = [[SELECT
  r.messenger_route_uuid, r.messenger_route_context, r.messenger_route_name, r.messenger_route_type,
  r.messenger_route_destination, rd.messenger_channel_uuid, rd.messenger_route_detail_settings, r.domain_uuid
FROM v_messenger_routes r inner join v_messenger_route_details rd
  ON r.messenger_route_uuid = rd.messenger_route_uuid
ORDER BY r.messenger_route_uuid, rd.messenger_route_detail_order
]]

  cnn:query(sql, function(cnn, err, res) -- luacheck: ignore cnn
    if err then
      log.error('can not get routes: %s', err)
      return
    end

    self._contexts = {}
    log.info('loading routes')

    local route
    for _, row in ipairs(res) do
      local notification_route_uuid
        ,notification_route_context
        ,notification_route_name
        ,notification_route_type
        ,notification_route_destination
        ,notification_channel_uuid
        ,notification_route_detail_settings
        ,domain_uuid = utils.unpack(row)
      if (not route) or route.uuid ~= notification_route_uuid then
        if route then self:_add(route) end

        route = {context = notification_route_context, domain_uuid = domain_uuid, type = notification_route_type,
          name = notification_route_name, uuid = notification_route_uuid,
          destination = utils.coalesce(notification_route_destination, '')
        }
      end
      local settings = utils.coalesce(notification_route_detail_settings)
      if settings then settings = json.decode(settings) end
      utils.append(route, { notification_channel_uuid, settings })
    end

    if route then self:_add(route) end
  end)
end

end
----------------------------------------------------------------------

return Router