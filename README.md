# FusionPBX Messenger

Application for [FusionPBX](http://www.fusionpbx.com) to send/receive SMS messages

### Usage

#### Messenger client class from Lua scripts

```Lua
local MessengerClient = require "app.messenger.resources.classes.Messenger.Client"

local messenger = MessengerClient.new()

-- Service response when it accept message, not when
-- message actually send.
local response = messenger:sendSync(2000,
  'outbound', 'info', 'sip:100@pbx.local',
  'email:customer@outer.domain.com', 'Test message', '<b>Hello world</b>',{
    content_type = 'text/html'
  }
))

-- Send HTML message. (Tested with X-Lite/Bria)
messenger:send(
  'local', 'test', 'sms:100@pbx.local',
  'sms:105@pbx.local', 'SIP SIMPLE', [[
<span style="font-family: Arial; font-size: 10pt; color: #ff0000">
  <span style="color: #000080">
    <strong>Hello, </strong>
  </span>
  <span style="font-family: ; font-size: 8pt; color: #ff0000">world<span style="color: #008000">
      <strong><em>!!!</em></strong>
    </span>
  </span>
</span>
]], {content_type = 'text/html'}
)
```

#### Forward messages from chatplan

```XML
<!-- Convert number to E164 and send via Messener router -->
<extension name="messenger-ru-mobile">
  <condition field="${to_user}" expression="^(?:\+?7|8|8107)(9\d{9})$">
    <action application="lua" data="messenger forward outbound router/sms:${user_context} 7$1@${to_host}" />
  </condition>
</extension>

<!-- Convert number to E164 and send via Messener channel -->
<extension name="messenger-ru-mobile">
  <condition field="${to_user}" expression="^(?:\+?7|8|8107)(9\d{9})$">
    <action application="lua" data="messenger forward outbound channel/sms:gsm01 7$1" />
  </condition>
</extension>

<!-- Forward USSD request to gsm01 channel. -->
<!-- GSM channel also route response to original user -->
<extension name="messenger-ru-mobile">
  <condition field="${to_user}" expression="^ussd01$">
    <action application="lua" data="messenger forward outbound channel/ussd:gsm01 ussd" />
  </condition>
</extension>
```

### Messenger cli

```
> lua messenger 
USAGE:
--------------------------------------------------------------------------------
lua messenger
  channels [list|rescan]
  channel [kill|start] <channel uuid>
  routes reload
  message resend <message uuid>
  service [start|stop|status]
  mwi [start|stop|status]
--------------------------------------------------------------------------------
```

### Todo

 * Router mechanism is really not very powerfull. In fact it only allows select channel based
   on destination number and message type. The main motivation for this class is ability
   to route SMS messages via several GSM modems. If you need any logic you have to use
   FS chatplan. E.g. if you want forward SMS to EMail you have to write chatplan where
   set email address as destination.
 * Configuration service (e.g. connect to FS)
 * Support not ony SIP user messages. E.g. be able just configure extension number and
   then route to different endpoint (SIP/EMail/Verto etc.)
