local uv            = require "lluv"
local ut            = require "lluv.utils"
local curl          = require "lluv.curl"
local sendmail      = require "sendmail"

local AsyncRequest = ut.class() do

local function trim(s)
  return s and string.match(s, '^%s*(.-)%s*$')
end

function AsyncRequest:__init(...)
  self._queue  = curl.queue(...)

  return self
end

function AsyncRequest:sendmail(t, cb)
  self._queue:perform(function(request)
    local response, msg request
    :on('start', function(_,_,handle)
      t.engine = 'curl'
      t.curl = {handle = handle, async = true}

      local ok, err = sendmail(t)

      t.engine, t.curl = nil -- luacheck: ignore 532

      if not ok then
        if cb then
          return uv.defer(cb, err)
        end
        return nil, err
      end

      assert(ok == handle)
      msg = err
    end)
    :on('header', function(_,_,h)
      -- ignores close connection responses
      if string.sub(h, 1, 3) ~= '221' then
        response = h
      end
    end)
    :on('error', function(_, _, err) cb(err) end)
    :on('done', function(_, _, easy)
      local res  = (type(msg.rcpt) == 'table') and #msg.rcpt or 1
      local code = easy:getinfo_response_code()
      cb(nil, res, code, trim(response))
    end)
  end)
end

local function char_to_hex(ch)
  return string.format("%%%.2X", string.byte(ch))
end

local function url_encode(str)
  return (string.gsub(str, '[^A-Za-z0-9.,%-/_]', char_to_hex))
end

local function params_join(t)
  local params = ''
  for k, v in pairs(t) do
    params = params .. k .. '=' .. url_encode(v) .. '&'
  end
  return (#params > 0) and params or nil
end

function AsyncRequest:get(url, ...)
  local params, cb = ...

  if type(...) == 'function' then
    cb, params = ..., nil
  end

  if params then
    params = params_join(params)
    if params then
      url = url .. '?' .. params
    end
  end

  self._queue:perform(url, function(request)
    local data = {} request
    :on('start', function(_,_,easy)
      easy:setopt_writefunction(table.insert, data)
    end)
    :on('error', function(_, _, err) cb(err) end)
    :on('done', function(_, _, easy)
      local code = easy:getinfo_response_code()
      local response = table.concat(data)
      cb(nil, response, code)
    end)
  end)
end

end

return AsyncRequest
