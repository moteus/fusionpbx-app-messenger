local uv            = require "lluv"
local iconv         = require "iconv"
local json          = require "dkjson"
local NULL          = require "null".null

local utils = {}

utils.json = json

utils.NULL = NULL

utils.STATUS = {
  SENDING = 'sending',
  SUCCESS = 'success',
  FAIL    = 'fail',
}

function utils.coalesce(v, ...)
  if v == NULL then return utils.coalesce(...) end
  return v
end

local unpack = unpack or table.unpack -- luacheck: ignore unpack

utils.unpack = unpack

do -- is_uuid
local pattern = '^%x%x%x%x%x%x%x%x%-%x%x%x%x%-%x%x%x%x%-%x%x%x%x%-%x%x%x%x%x%x%x%x%x%x%x%x$'

function utils.is_uuid(s)
  return s and string.match(s, pattern)
end
end

function utils.is_2xx(c) return c and (c >= 200) and (c <= 299) and c end

function utils.delay_call(timeout, cb, ...)
  local argv, argc = {...}, select('#', ...)
  uv.timer():start(timeout, function(timer)
    timer:close()
    cb(unpack(argv, 1, argc))
  end)
end

local function pass(msg) return msg end

function utils.iconv_converter(src, dst)
  if dst == src then return pass end
  local conv = iconv.new(dst, src)
  if not conv then return pass end
  return function(msg)
    return msg and conv:iconv(msg) or msg
  end
end

do -- pgerr_tostring

local conv = utils.iconv_converter('utf-8', 'cp866')

function utils.pgerr_tostring(err)
  if err and err.cat and err:cat() == "PostgreSQL" then
    err = conv(tostring(err))
  end
  return tostring(err)
end

end

function utils.render(str, params, ...)
  for k, v in pairs(params) do
    str = string.gsub(str, '${' .. k .. '}', v)
  end
  if ... then return utils.render(str, ...) end
  return str
end

function utils.deep_render(t, ...)
  if type(t) == 'string' then
    return utils.render(t, ...)
  end

  local res = {}
  for k, v in pairs(t) do
    res[k] = utils.deep_render(v, ...)
  end

  return res
end

function utils.append(t, v)
  t[#t + 1] = v
  return t
end

function utils.pg_apply_names(rs, no_null)
  local colnames = rs.header and rs.header[1]
  for rowid = 1, #rs do
    local row = rs[rowid]
    for i = 1, #row do
      local name  = colnames[i]
      local value = row[i]
      print(value, NULL)
      if value == NULL then
        print("::",  name, value, no_null)
        if no_null then value = nil end
      end
      row[name] = value
    end
  end
  return rs
end

function utils.pg_named_cb(no_null, cb)
  if not cb then cb, no_null = no_null, nil end
  return function(self, err, ...)
    if ... then utils.pg_apply_names(..., no_null) end
    return cb(self, err, ...)
  end
end

utils.is = setmetatable ({}, {
  __index = function (self, TYPE)
    local fn = function (VALUE)
      if type(VALUE) ~= TYPE then
        local msg = string.format('Invalid value type. Expected `%s` but got `%s`',
          TYPE, type(VALUE)
        )
        return error(msg, 2)
      end
      return VALUE
    end
    self[TYPE] = fn
    return fn
  end
})

return utils