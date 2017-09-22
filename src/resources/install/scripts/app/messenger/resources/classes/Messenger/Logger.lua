
local default_writer = function() end

local current_writer = default_writer

local level = 'none'
local formatter = require "log.formatter.pformat".new(true, true)
local writer = function(...) return current_writer(...) end

local function create_prefix_writer(prefix)
  return require "log.writer.prefix".new(prefix, writer)
end

local loggers = setmetatable({},{__mode = 'v'})

local function set_level(lvl)
  if level == lvl then return end

  level = lvl
  for _, logger in pairs(loggers) do
    logger.set_lvl(level)
  end
end

local function set_writer(wrt)
  current_writer = wrt or default_writer
end

local function set_base_logger(log)
  set_level(log.lvl())
  set_writer(log.writer())
end

local function get_logger(name)
  local logger = loggers[name]
  if logger then return logger end
  logger = require"log".new(level,
    create_prefix_writer('['..name..'] '),
    formatter
  )
  loggers[name] = logger
  return logger
end

local Logger = {}

function Logger.set(v)
  if type(v) == 'string' or type(v) == 'number' then
    return set_level(v)
  end

  if type(v) == 'function' then
    return set_writer(v)
  end

  if type(v) == 'table' then
    return set_base_logger(v)
  end
end

function Logger.get(name)
  return get_logger(name)
end

return Logger