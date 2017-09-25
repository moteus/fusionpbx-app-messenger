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
	Portions created by the Initial Developer are Copyright (C) 2008-2015
	the Initial Developer. All Rights Reserved.

	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
	Luis Daniel Lucio Quiroz <dlucio@okay.com.mx>

	Call Block is written by Gerrit Visser <gerrit308@gmail.com>
*/
require_once "root.php";
require_once "resources/require.php";
require_once "resources/check_auth.php";
require_once "resources/functions/messenger_utils.php";

if (permission_exists('messenger_channel_edit') || permission_exists('messenger_channel_add')) {
	//access granted
}
else {
	echo 'access denied';
	exit;
}

//action add or update
	if (isset($_GET['id'])) {
		if (! permission_exists('messenger_channel_edit')) {
			echo 'access denied';
			exit;
		}
		$action = 'update';
		$messenger_channel_uuid = $_GET['id'];
	}
	else {
		if (! permission_exists('messenger_channel_add')) {
			echo 'access denied';
			exit;
		}
		$action = 'add';
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

	$domain_uuid = $_SESSION['domain_uuid'];
	$domain_name = $_SESSION['domain_name'];
	$current_page = 'messenger_channel_edit.php';

	$is_post_form = !empty($_POST['submit']);
	$is_persist   = 'true' === $_POST['persistformvar'];

//collect form values
	if ($is_post_form) {
		$messenger_channel_name      = $_POST['messenger_channel_name'];
		$messenger_channel_transport = $_POST['messenger_channel_transport'];
		$messenger_channel_settings  = $_POST['messenger_channel_settings'];
		$messenger_channel_enabled   = $_POST['messenger_channel_enabled'];
		$messenger_channel_domain    = $_POST['messenger_channel_domain'];
		if (action == 'update') {
			if ($messenger_channel_uuid != $_POST['messenger_channel_uuid']) {
				//! they have to match
				echo 'access denied';
				exit;
			}
		}
		if (empty($messenger_channel_domain)) {
			$messenger_channel_domain = null;
		}
	}

	$back_params = json_decode($_POST['back']);
	$back_url = build_url('messenger_channels.php', $back_params);

// handle form
	if ($is_post_form && !$is_persist) {
		//check for all required data
			$msg = '';
			if (strlen($messenger_channel_name) == 0)      { $msg .= $text['label-provide-name']."<br>\n"; }
			if (strlen($messenger_channel_transport) == 0) { $msg .= $text['label-provide-transport']."<br>\n"; }
			if (strlen($messenger_channel_enabled) == 0)   { $msg .= $text['label-provide-enabled']."<br>\n"; }
			if (!empty($messenger_channel_settings)){
				if (NULL === json_decode($messenger_channel_settings)){
					$msg .= $text['label-invalid-json'] . "<br>\n";
				}
			}

			if (strlen($msg) > 0) {
				require_once "resources/header.php";
				require_once "resources/persist_form_var.php";
				echo "<div align='center'>\n";
				echo "<table><tr><td>\n";
				echo "$msg<br />";
				echo "</td></tr></table>\n";
				persistformvar($_POST);
				echo "</div>\n";
				require_once "resources/footer.php";
				return;
			}

		//add or update the database
			if ($action == 'add') {
				$messenger_channel_uuid = uuid();
				$insert_sql = 'INSERT INTO v_messenger_channels(messenger_channel_uuid, domain_uuid, messenger_channel_name, messenger_channel_transport, messenger_channel_enabled, messenger_channel_settings) VALUES (:messenger_channel_uuid, :domain_uuid, :messenger_channel_name, :messenger_channel_transport, :messenger_channel_enabled, :messenger_channel_settings)';
				$query = $db->prepare($insert_sql);
			}
			else {
				$update_sql = 'UPDATE v_messenger_channels SET messenger_channel_name=:messenger_channel_name, domain_uuid=:domain_uuid, messenger_channel_transport=:messenger_channel_transport, messenger_channel_enabled=:messenger_channel_enabled, messenger_channel_settings=:messenger_channel_settings WHERE messenger_channel_uuid=:messenger_channel_uuid';
				$query = $db->prepare($update_sql);
			}

			$query->bindParam(':domain_uuid',                 $messenger_channel_domain);
			$query->bindParam(':messenger_channel_uuid',      $messenger_channel_uuid);
			$query->bindParam(':messenger_channel_name',      $messenger_channel_name);
			$query->bindParam(':messenger_channel_transport', $messenger_channel_transport);
			$query->bindParam(':messenger_channel_settings',  $messenger_channel_settings);
			$query->bindParam(':messenger_channel_enabled',   $messenger_channel_enabled);
			$result = $query->execute();
			if ($result) {
				$_SESSION['message'] = '<strong>' . $text["label-$action-complete"] . '</strong>';
				header("Location: $back_url");
				exit;
			}
			$_SESSION['message_mood'] = 'negtive';
			$_SESSION['message']      = '<strong>' . $text["label-$action-failed"] . '</strong>';
	}

//pre-populate the form
	if (!$is_post_form) {
		if ($action == 'add') {
			$messenger_channel_domain = $domain_uuid;
			$messenger_channel_enabled = 'true';
			$messenger_channel_transport = 'sip';
		}
		else{
			$sql = 'select * from v_messenger_channels ';
			$sql .= 'where messenger_channel_uuid = :messenger_channel_uuid ';
			if (!permission_exists('messenger_channel_all')) {
				$sql .= 'and domain_uuid = :domain_uuid ';
			}
			$query = $db->prepare($sql);
			$query->bindParam(':messenger_channel_uuid', $messenger_channel_uuid);
			$query->bindParam(':domain_uuid', $domain_uuid);
			$result = $query->execute();
			$row = $query->fetch();
			$messenger_channel_name      = $row['messenger_channel_name'];
			$messenger_channel_domain    = $row['domain_uuid'];
			$messenger_channel_transport = $row['messenger_channel_transport'];
			$messenger_channel_enabled   = $row['messenger_channel_enabled'];
			$messenger_channel_settings  = $row['messenger_channel_settings'];
		}
	}

//select domain
	$sql = 'select domain_name, domain_uuid from v_domains order by domain_name';
	$query = $db->prepare($sql);
	$query->execute();
	$domains = array(NULL => 'Global');
	while ($row = $query->fetch(PDO::FETCH_NAMED)) {
		$domains[ $row['domain_uuid'] ] = $row['domain_name'];
	}
	unset ($query, $row, $sql);

//show the header
	require_once "resources/header.php";

//show the content
	echo "<form method='post' name='frm' action=''>\n";
	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	$action_text = ($action == 'add') ? $text['label-edit-add'] : $text['label-edit-edit'];
	$action_note = ($action == 'add') ? $text['label-add-note'] : $text['label-edit-note'];
	$back_text = $text['button-back']; $save_text = $text['button-save'];

	echo "<tr>\n";
	echo "<td align='left' width='30%' nowrap='nowrap'><b>$action_text</b></td>\n";
	echo "<td width='70%' align='right'>";
	echo "	<input type='button' class='btn' name='' alt='$back_text' onclick=\"window.location='$back_url'\" value='$back_text'>";
	echo "	<input type='submit' name='submit' class='btn' value='$save_text'>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td align='left' colspan='2'>\n";
	echo "$action_note<br /><br />\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-messenger_channel_name']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<input class='formfld' name='messenger_channel_name' type='text' maxlength='255' required='required' ";
	echo "value='";
	echo escape($messenger_channel_name);
	echo "'>\n";
	echo "<br />\n".$text['description-messenger_channel_name']."\n<br />\n";
	echo "</td>\n";
	echo "</tr>\n";

	$enabled_select = array(
		'true'  => $text['label-true'],
		'false' => $text['label-false'],
	);

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-messenger_channel_domain']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='messenger_channel_domain'>\n";
	echo build_select_list($messenger_channel_domain, $domains, NULL);
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-messenger_channel_domain']."\n";
	echo "\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-messenger_channel_enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='messenger_channel_enabled'>\n";
	echo build_select_list($messenger_channel_enabled, $enabled_select, 'true');
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-messenger_channel_enabled']."\n";
	echo "\n";
	echo "</td>\n";
	echo "</tr>\n";

	$transport_select = array(
		'sip'   => 'Sip',
		'gsm'   => 'GSM modem',
		'email' => 'Email',
	);

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-messenger_channel_transport']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='messenger_channel_transport'>\n";
	echo build_select_list($messenger_channel_transport, $transport_select, 'sip');
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-messenger_channel_transport']."\n";
	echo "\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr id=tr_channel_settings_form style='display: none;'>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-messenger_channel_settings']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "<div id=channel_settings></div>";
	echo "</td>\n";
	echo "</tr>\n";

	echo "<tr id=tr_channel_settings_text>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-messenger_channel_settings']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<textarea class='formfld' name='messenger_channel_settings' style='width: 100%; max-width: 100%; height: 450px; padding:20px;'>";
	echo escape($messenger_channel_settings);
	echo "</textarea>\n";
	echo "<br />\n";
	echo $text['description-messenger_channel_settings']."\n";
	echo "<br />\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "	<tr>\n";
	echo "		<td colspan='2' align='right'>\n";
	if ($action == 'update') {
		echo "		<input type='hidden' name='messenger_channel_uuid' value='" . escape($messenger_channel_uuid) . "'>\n";
	}
	echo "			<br>";
	echo "			<input type='submit' name='submit' class='btn' value='".$text['button-save']."'>\n";
	echo "		</td>\n";
	echo "	</tr>";
	echo "</table>";
	echo "<br><br>";
	echo "</form>";


	echo '<script src="'.PROJECT_PATH.'/resources/jsoneditor.min.js"></script>';
?>

<script type="text/javascript">

	var properties_scheme = {
		"title": "Parameters",
		"type": "array",
		"items": {
			"title": "Parameter",
			"type": "object",
			"properties": {
				"name": {
					"title": "Name",
					"oneOf": [
						{
							"title": "EMail",
							"type": "string",
							"enum": [
								"title",
								"subject"
							],
							"options": {
								"enum_titles": [
									"Title",
									"Subject"
								]
							}
						},
						{
							"title": "Custom",
							"type": "string"
						}
					],
					"required": true,
					"default": null
				},
				"value": {
					"title": "Value",
					"required": true,
					"type": "string",
					"default": ""
				}
			},
			"format": "grid"
		}
	}

	var schemas = {
		"gsm": {
			"title": "GSM Modem",
			"type": "object",
			"properties": {
				"port": {
					"title": "Serial port",
					"type": "string",
					"default": ""
				},
				"settings": {
					"title": "Port settings",
					"type": "object",
					"properties": {
						"parity": {
							"title": "Parity",
							"oneOf": [
								{
									"title": "Default",
									"type": "null"
								},
								{
									"title": "Value",
									"type": "string",
									"enum": [
										"NONE",
										"ODD",
										"EVEN"
									],
									"default": "NONE"
								}
							],
							"default": null
						},
						"baud": {
							"title": "Baud rate",
							"oneOf": [
								{
									"title": "Default",
									"type": "null"
								},
								{
									"title": "Value",
									"type": "string",
									"enum": [
										"_300",
										"_2400",
										"_4800",
										"_9600",
										"_19200",
										"_38400",
										"_57600",
										"_115200",
										"_460800"
									],
									"options": {
										"enum_titles": [
											"300",
											"2400",
											"4800",
											"9600",
											"19200",
											"38400",
											"57600",
											"115200",
											"460800"
										]
									},
									"default": "_9600"
								}
							],
							"default": null
						},
						"rts": {
							"title": "RTS",
							"oneOf": [
								{
									"title": "Default",
									"type": "null"
								},
								{
									"title": "Value",
									"type": "string",
									"enum": [
										"OFF",
										"ON"
									],
									"default": "ON"
								}
							],
							"default": null
						},
						"dtr": {
							"title": "DTR",
							"oneOf": [
								{
									"title": "Default",
									"type": "null"
								},
								{
									"title": "Value",
									"type": "string",
									"enum": [
										"OFF",
										"ON"
									],
									"default": ""
								}
							],
							"default": null
						},
						"data_bits": {
							"title": "Data bits",
							"oneOf": [
								{
									"title": "Default",
									"type": "null"
								},
								{
									"title": "Value",
									"type": "string",
									"enum": [
										"_5",
										"_6",
										"_7",
										"_8"
									],
									"options": {
										"enum_titles": [
											"5",
											"6",
											"7",
											"8"
										]
									},
									"default": "_8"
								}
							],
							"default": null
						},
						"stop_bits": {
							"title": "Stop bits",
							"oneOf": [
								{
									"title": "Default",
									"type": "null"
								},
								{
									"title": "Value",
									"type": "string",
									"enum": [
										"_1",
										"_2"
									],
									"options": {
										"enum_titles": [
											"1",
											"2"
										]
									},
									"default": "_1"
								}
							],
							"default": null
						},
						"flow_control": {
							"title": "Flow control",
							"oneOf": [
								{
									"title": "Default",
									"type": "null"
								},
								{
									"title": "Value",
									"type": "string",
									"enum": [
										"OFF",
										"HW",
										"XON_XOFF"
									],
									"options": {
										"enum_titles": [
											"OFF",
											"HARDWARE",
											"XON/XOFF"
										]
									},
									"default": "OFF"
								}
							],
							"default": null
						}
					},
					"format": "grid"
				},
				"route":{
					"title": "Inbound routing",
					"type": "object",
					"properties": {
						"destination": {
							"title": "Destination",
							"type": "string",
							"default": "",
							"options": {
								"grid_columns": 4
							}
						},
						"number": {
							"title": "My number",
							"type": "string",
							"default": "",
							"options": {
								"grid_columns": 4
							}
						},
						"context": {
							"title": "Context",
							"type": "string",
							"default": "public",
							"options": {
								"grid_columns": 2
							}
						},
						"method": {
							"title": "Route method",
							"type": "string",
							"enum": [
								"messenger",
								"chatplan"
							],
							"default": "messenger",
							"options": {
								"grid_columns": 2
							}
						},
						"subject": {
							"title": "Subject",
							"type": "string",
							"default": ""
						}
						// ,"properties": properties_scheme
					},
					"format": "grid",
					"required":[ "method" ]
				}
			}
		},
		"sip": {
			"title": "FreeSWITCH ESL Connection",
			"type": "object",
			"properties": {
				"host": {
					"title": "Host",
					"oneOf": [
						{
							"title": "Default",
							"type": "null"
						},
						{
							"title": "Address",
							"type": "string",
							"default": "127.0.0.1"
						}
					],
					"default": null
				},
				"port": {
					"title": "Port",
					"oneOf": [
						{
							"title": "Default",
							"type": "null"
						},
						{
							"title": "Number",
							"type": "string",
							"default": "127.0.0.1"
						}
					],
					"default": null
				},
				"auth": {
					"title": "Auth",
					"oneOf": [
						{
							"title": "Default",
							"type": "null"
						},
						{
							"title": "Password",
							"type": "string",
							"format": "password",
							"default": "ClueCon"
						}
					],
					"default": null
				},
				"properties": properties_scheme
			},
			"format": "grid"
		},
		"email": {
			"title": "SMTP Settings",
			"type": "object",
			"properties": {
				"from": {
					"title": "From",
					"type": "object",
					"properties": {
						"title": {
							"title": "Title",
							"type": "string",
							"default": ""
						},
						"address": {
							"title": "Address",
							"type": "string",
							"default": ""
						},
						"charset": {
							"title": "charset",
							"type": "string",
							"default": "utf-8"
						}
					},
					"format": "grid"
				},
				"server": {
					"title": "Server",
					"type": "object",
					"properties": {
						"address": {
							"title": "Address",
							"type": "string",
							"default": "127.0.0.1"
						},
						"user": {
							"title": "User name",
							"type": "string",
							"default": ""
						},
						"password": {
							"title": "Password",
							"type": "string",
							"format": "password",
							"default": ""
						},
						"ssl": {
							"title": "Secure",
							"oneOf": [
								{
									"title": "None",
									"type": "null"
								},
								{
									"title": "Basic",
									"type": "string",
									"enum": [
										"SSLv2",
										"SSLv3",
										"TLSv1",
										"TLSv1.0",
										"TLSv1.1",
										"TLSv1.2",
										"TLSv1.3"
									],
									"default": "TLSv1"
								},
								{
									"title": "Advenced",
									"type": "object",
									"properties": {
										"verify": {
											"title": "Verify",
											"type": ["array", "null"],
											"format": "select",
											"uniqueItems": true,
											"items": {
												"type": "string",
												"enum": [
													"none",
													"peer",
													"host"
												]
											},
											"default": ["none"]
										},
										"protocol": {
											"title": "Protocol",
											"oneOf": [
												{
													"title": "Default",
													"type": "null"
												},
												{
													"type": "string",
													"enum": [
														"SSLv2",
														"SSLv3",
														"TLSv1",
														"TLSv1.0",
														"TLSv1.1",
														"TLSv1.2",
														"TLSv1.3"
													],
													"default": "TLSv1"
												}
											],
											"default": null
										},
										"ciphers": {
											"title": "Cipher list",
											"oneOf": [
												{
													"title": "Default",
													"type": "null"
												},
												{
													"title": "Custom",
													"type": "string"
												}
											],
											"default": null
										},
										"certificate": {
											"title": "Certificate",
											"oneOf": [
												{
													"title": "Default",
													"type": "null"
												},
												{
													"title": "Custom",
													"type": "string"
												}
											],
											"default": null
										},
										"key": {
											"title": "Key",
											"oneOf": [
												{
													"title": "Default",
													"type": "null"
												},
												{
													"title": "Custom",
													"type": "string"
												}
											],
											"default": null
										}
									},
									"format": "grid"
								}
							],
							"default": null
						}
					},
					"format": "grid"
				}
			}
		}
	}

	var settings_textarea = $('textarea[name=messenger_channel_settings]');
	var transport_select = $('select[name=messenger_channel_transport]');
	var editor, values = {}
	var current_transport = transport_select.val();
	values[current_transport] = settings_textarea.val();

	function reload_form(new_transport){
		if (editor) {
			var value = editor.getValue()
			values[current_transport] = JSON.stringify(value)
			editor.destroy()
			editor = null
		}

		var schema = schemas[new_transport]
		if (schema) {
			var value = values[new_transport];
			if (value && value.length > 0) {
				value = JSON.parse(value)
			}
			else {
				value = null
			}

			editor = new JSONEditor(document.getElementById("channel_settings"),{
				schema: schema,
				disable_array_reorder: true,
				disable_collapse: true,
				disable_edit_json: true,
				disable_properties: true,
				no_additional_properties: true,
				remove_empty_properties: true,
			});

			editor.root.setValue(value, true);

			editor.on('change', sync_form2text);
		}
		else {
			var value = values[new_transport]
			settings_textarea.val(value);
		}
		current_transport = new_transport
	}

	function on_transport_select_change(){
		var new_transport = transport_select.val()
		if (new_transport !== current_transport) {
			reload_form(new_transport)
		}

		if (editor) {
			$('#tr_channel_settings_text').hide()
			$('#tr_channel_settings_form').show()
		}
		else {
			$('#tr_channel_settings_text').show()
			$('#tr_channel_settings_form').hide()
		}
	}

	function sync_form2text(){
		if(editor){
			var value = editor.getValue();
			settings_textarea.val(JSON.stringify(value));
		}
	}

	$(function() {
		// Set default options
		JSONEditor.defaults.options.theme = 'bootstrap3';

		transport_select.change(on_transport_select_change)
		reload_form(current_transport)
		on_transport_select_change()
	})

	$('form[name=frm]').submit(sync_form2text)

</script>

<?php
//include the footer
	require_once "resources/footer.php";
