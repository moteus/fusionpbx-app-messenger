<?php
/*
	FusionPBX
	Version: MPL 1.1

	The contents of this file are subject to the Mozilla Public License Version
	1.1 (the "License"); you may not use this file except in compliance with
	the License. You may obtain a copy of the License at
	http://www.mozilla.org/MPL/

	Software distributed under the License is distributed on an "AS IS" basis,
	WITHOUT WARRANTY OF ANY KIND, either express or implied. See the License
	for the specific language governing rights and limitations under the
	License.

	The Original Code is FusionPBX

	The Initial Developer of the Original Code is
	Mark J Crane <markjcrane@fusionpbx.com>
	Copyright (C) 2008-2015
	All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/
include "root.php";
require_once "resources/require.php";
require_once "resources/check_auth.php";
require_once "resources/functions/messenger_utils.php";

if (permission_exists('messenger_message_view')) {
	//access granted
}
else {
	echo "access denied";
	exit;
}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

	$messenger_message_uuid = $_GET['id'];
	if (!is_uuid($messenger_message_uuid)) {
		echo "access denied";
		exit;
	}

	$domain_uuid = $_SESSION['domain_uuid'];
	$domain_name = $_SESSION['domain_name'];
	$current_page = 'messenger_message_view.php';

	$back_params = json_decode($_POST['back']);
	if($back_params === null){
		$back_params = array();
	}
	$back_url = build_url('messenger_messages.php', $back_params);

	$sql = 'select m.*, c.messenger_channel_name, c.messenger_channel_transport from v_messenger_messages m ';
	$sql .= 'left outer join v_messenger_channels c on c.messenger_channel_uuid = m.messenger_channel_uuid ';
	$sql .= 'inner join v_domains d on m.domain_uuid = d.domain_uuid ';
	$sql .= 'where messenger_message_uuid = :messenger_message_uuid ';
	if (!permission_exists('messenger_message_all')) {
		$sql .= 'and m.domain_uuid = :domain_uuiddomain_uuid ';
	}
	$query = $db->prepare($sql);
	$query->bindParam(':messenger_message_uuid', $messenger_message_uuid);
	$query->bindParam(':domain_uuid', $domain_uuid);
	$result = $query->execute();
	$messenger_message = $query->fetch(PDO::FETCH_NAMED);
	unset ($query, $sql);

	if(empty($messenger_message)){
		$_SESSION['message_mood'] = 'negtive';
		$_SESSION['message'] = $text['message-message_not_found'];
		header("Location: $back_url");
		exit;
	}

	$messenger_channel_name               = $messenger_message['messenger_channel_name'];
	$messenger_channel_transport          = $messenger_message['messenger_channel_transport'];
	$messenger_message_direction          = $messenger_message['messenger_message_direction'];
	$messenger_message_source             = $messenger_message['messenger_message_source'];
	$messenger_message_source_proto       = $messenger_message['messenger_message_source_proto'];
	$messenger_message_source_destination = $messenger_message['messenger_message_source_destination'];
	$messenger_message_destination        = $messenger_message['messenger_message_destination'];
	$messenger_message_destination_proto  = $messenger_message['messenger_message_destination_proto'];
	$messenger_message_type               = $messenger_message['messenger_message_type'];
	$messenger_message_category           = $messenger_message['messenger_message_category'];
	$messenger_message_subject            = $messenger_message['messenger_message_subject'];
	$messenger_message_content_type       = $messenger_message['messenger_message_content_type'];
	$messenger_message_data               = $messenger_message['messenger_message_data'];
	$messenger_message_settings           = $messenger_message['messenger_message_settings'];
	$messenger_message_time               = $messenger_message['messenger_message_time'];
	$messenger_message_expire_at          = $messenger_message['messenger_message_expire_at'];
	$messenger_message_status             = $messenger_message['messenger_message_status'];
	$messenger_message_status_text        = $messenger_message['messenger_message_status_text'];
	$messenger_message_status_time        = $messenger_message['messenger_message_status_time'];

//show the header
	$document['title'] = $text['title-message_view'];
	require_once "resources/header.php";

	$resend_url = build_url('messenger_messages.php', array_merge($back_params, array(
		'a' => 'resend',
		'msg' => $messenger_message_uuid,
		
	)));

//show content
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo "	<tr>";
	echo "		<td valign='top' align='left' nowrap>";
	echo "			<b>".$text['title-message_view']."</b>\n";
	echo "		</td>";
	echo "		<td valign='top' align='right' nowrap>";
	echo "			<input type='button' class='btn' alt='".$text['button-back']."' onclick=\"document.location.href='".escape($back_url)."';\" value='".$text['button-back']."'>";
	echo "			<input type='button' class='btn' alt='".$text['button-resend']."' onclick=\"document.location.href='".escape($resend_url)."';\" value='".$text['button-resend']."'>";
	echo "		</td>";
	echo "	</tr>";
	echo "</table>";
	echo "<br>\n";

	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_direction']."</td>\n";
	echo "<td width='70%' class='vtable' align='left'>".escape($messenger_message_direction)."</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td width='30%' class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_time']."</td>\n";
	echo "<td width='70%' class='vtable' align='left'>".format_datetime($messenger_message_time)."&nbsp/&nbsp".format_datetime($messenger_message_expire_at)."</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_channel_name']."</td>\n";
	echo "<td class='vtable' align='left'>".escape($messenger_channel_name)."&nbsp(".escape($messenger_channel_transport).")</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_type']."</td>\n";
	echo "<td class='vtable' align='left'>".escape($messenger_message_type)."&nbsp/&nbsp".escape($messenger_message_category)."</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_status']."</td>\n";
	echo "<td class='vtable' align='left'>".escape($messenger_message_status)."&nbsp/&nbsp".format_datetime($messenger_message_status_time)."</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_status_text']."</td>\n";
	echo "<td class='vtable' align='left'>".escape($messenger_message_status_text)."</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_source']."</td>\n";
	echo "<td class='vtable' align='left'>(".escape($messenger_message_source_proto).")&nbsp".escape($messenger_message_source)."&nbsp/&nbsp".escape($messenger_message_source_destination)."</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_destination']."</td>\n";
	echo "<td class='vtable' align='left'>(".escape($messenger_message_destination_proto).")&nbsp".$messenger_message_destination."</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_subject']."</td>\n";
	echo "<td class='vtable' align='left'>".escape($messenger_message_subject)."</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_data']."</td>\n";
	echo "<td class='vtable' align='left'>";
	echo "	<iframe id='msg_display' width='100%' height='250' scrolling='auto' cellspacing='0' style='border: 1px solid #c5d1e5; overflow: scroll;'></iframe>\n";
	echo "	<textarea id='msg' width='1' height='1' style='width: 1px; height: 1px; display: none;'>".escape(escape($messenger_message_data))."</textarea>\n";
	echo "	<script>";
	echo "		var iframe = document.getElementById('msg_display');";
	echo "		iframe.contentDocument.write(document.getElementById('msg').value);";
	echo "		iframe.contentDocument.close();";
	echo "	</script>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap>".$text['label-messenger_message_settings']."</td>\n";
	echo "<td class='vtable' align='left'>";
	echo "	<iframe id='settings_display' width='100%' height='250' scrolling='auto' cellspacing='0' style='border: 1px solid #c5d1e5; overflow: scroll;'></iframe>\n";
	echo "	<textarea id='settings' width='1' height='1' style='width: 1px; height: 1px; display: none;'>".escape(escape($messenger_message_settings))."</textarea>\n";
	echo "	<script>";
	echo "		var iframe = document.getElementById('settings_display');";
	echo "		iframe.contentDocument.write(document.getElementById('settings').value);";
	echo "		iframe.contentDocument.close();";
	echo "	</script>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>\n";
	echo "<br><br>";

//include the footer
	require_once "resources/footer.php";
