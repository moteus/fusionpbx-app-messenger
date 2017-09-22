local function messenger_require(name)
  return require ('app.messenger.resources.classes.' .. name)
end

local Channels = {
  gsm   = messenger_require "Messenger.Channels.Gsm";
  email = messenger_require "Messenger.Channels.Email";
  sip   = messenger_require "Messenger.Channels.Sip";
}



return Channels