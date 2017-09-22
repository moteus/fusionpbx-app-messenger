local function messenger_require(name)
  return require ('app.messenger.resources.classes.' .. name)
end

local uv            = require "lluv"
local ut            = require "lluv.utils"

local utils         = messenger_require "Messenger.Utils"
local Logger        = messenger_require "Messenger.Logger"

local log           = Logger.get('chann')

local BaseChannel = ut.class() do

BaseChannel._logger = log

function BaseChannel:__init(messenger, channel_info)
  self._messenger = messenger
  self._info      = channel_info
  self._name      = channel_info.name
  
  if channel_info.domain_name then
    self._name = self._name .. '@' .. channel_info.domain_name
  end

  return self
end

function BaseChannel:close(cb)
  if cb then
    uv.defer(cb, self)
  end
  log.info('channel [%s/%s] closed', self:id(), self:name())
end

function BaseChannel:id()
  return self._info.uuid
end

function BaseChannel:name()
  return self._name
end

function BaseChannel:domain()
  return self._info.domain_name, self._info.domain_uuid
end

function BaseChannel:transport()
  return self._info.transport
end

end

return BaseChannel