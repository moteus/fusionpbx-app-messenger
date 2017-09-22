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
	Portions created by the Initial Developer are Copyright (C) 2016
	the Initial Developer. All Rights Reserved.
	Contributor(s):
	Mark J Crane <markjcrane@fusionpbx.com>
*/
//includes
	require_once "root.php";
	require_once "resources/require.php";
	require_once "resources/functions/messenger_utils.php";

//check permissions
	require_once "resources/check_auth.php";
	if (permission_exists('messenger_route_edit') || permission_exists('messenger_route_add')) {
		//access granted
	}
	else {
		echo "access denied";
		exit;
	}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//action add or update
	if (isset($_GET['id'])) {
		$action = 'update';
		$messenger_route_uuid = $_GET['id'];
	}
	else {
		$action = 'add';
	}

	$back_url = 'messenger_routes.php';

	$domain_uuid = $_SESSION['domain_uuid'];
	$domain_name = $_SESSION['domain_name'];
	$current_page = 'messenger_route_edit.php';
	$current_params = array('id' => $messenger_route_uuid);

//delete route
	if ($_REQUEST['a']) {
		$message = array();

		$route_detail_uuid = $_REQUEST['detail'];
		if (!is_uuid($route_detail_uuid)){
			$message['mood']    = 'negative';
			$message['message'] = '<strong>' . $text['error-route_action_failed'] . '</strong>';
		}
		else{
			$action = $_REQUEST['a'];

			if($action == 'delete'){
				if(!permission_exists('messenger_route_edit')){
					$message['mood']    = 'negative';
					$message['message'] = '<strong>' . $text['error-route_action_failed'] . '</strong>: ' . $text['error-access-denied'];
				}
				else{
					//! @todo allow remove route detail only for current route
					$sql = 'delete from v_messenger_route_details where messenger_route_detail_uuid = :messenger_route_detail_uuid ';
					$query = $db->prepare($sql);
					$query->bindParam(':messenger_route_detail_uuid', $route_detail_uuid);
					$result = $query->execute();
					if($result){
						$message['message'] = '<strong>' . $text['label-delete-complete'] . '</strong>';
					}
					else{
						$message['mood'] = 'negative';
						$message['message'] = '<strong>' . $text['error-route_action_failed'] . '</strong>: ' . $text['error-access-denied'];
					}
				}
			}
			else{
				$message['mood']    = 'negative';
				$message['message'] = '<strong>' . $text['error-route_action_failed'] . '</strong>';
			}
		}

		$_SESSION['message_mood'] = $message['mood'];
		$_SESSION['message']      = $message['message'];
		$back_url = build_url($current_page, $current_params);

		header("Location: $back_url");
		exit;
	}

	$is_post_form = !empty($_POST['submit']);
	$is_persist   = 'true' === $_POST['persistformvar'];

//get http post variables and set them to php variables

	if ($is_post_form) {
		$messenger_route_context     = $_POST['messenger_route_context'];
		$messenger_route_name        = $_POST['messenger_route_name'];
		$messenger_route_type        = $_POST['messenger_route_type'];
		$messenger_route_destination = $_POST['messenger_route_destination'];
		$messenger_route_enabled     = $_POST['messenger_route_enabled'];
		$messenger_route_description = $_POST['messenger_route_description'];
		$messenger_route_details     = $_POST['messenger_route_details'];

		if (action == 'update') {
			if ($messenger_route_uuid != $_POST['messenger_route_uuid']) {
				//! they have to match
				echo 'access denied';
				exit;
			}
		}
	}

//process the user data and save it to the database
	if ($is_post_form && !$is_persist) {
		//get the uuid from the POST
			if ($action == 'add') {
				$messenger_route_uuid = uuid();
			}

		//check for all required data
			$msg = '';
			if (empty($messenger_route_context)) { $msg .= $text['message-required']." ".$text['label-messenger_route_context']."<br>\n"; }
			if (empty($messenger_route_name))    { $msg .= $text['message-required']." ".$text['label-messenger_route_name']   ."<br>\n"; }

			foreach($messenger_route_details as $row){
				$settings = $row['messenger_route_detail_settings'];
				if (!empty($settings)) {
					if (null === json_decode($settings)) {
						$msg .= 'Invald JSON value at route #'.$row['messenger_route_detail_order']."<br>\n";
					}
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

			$insert_routes_sql = <<<EOF
		INSERT INTO v_messenger_routes(
			messenger_route_uuid, domain_uuid, messenger_route_name,
			messenger_route_context, messenger_route_type,
			messenger_route_destination, messenger_route_description,
			messenger_route_enabled
		)
		VALUES (:messenger_route_uuid, :domain_uuid, :messenger_route_name,
			:messenger_route_context, :messenger_route_type, 
			:messenger_route_destination, :messenger_route_description,
			:messenger_route_enabled
		);
EOF;

			$update_routes_sql = <<<EOF
		UPDATE v_messenger_routes
			SET messenger_route_context=:messenger_route_context,
				messenger_route_name=:messenger_route_name,
				messenger_route_type=:messenger_route_type,
				messenger_route_destination=:messenger_route_destination,
				messenger_route_description=:messenger_route_description,
				messenger_route_enabled=:messenger_route_enabled
			WHERE messenger_route_uuid=:messenger_route_uuid;
EOF;

			$insert_details_sql = <<<EOF
		INSERT INTO v_messenger_route_details(
			messenger_route_detail_uuid, messenger_route_uuid, 
			messenger_route_detail_order, messenger_channel_uuid, 
			messenger_route_detail_settings, messenger_route_detail_enabled
		)
		VALUES (
			:messenger_route_detail_uuid, :messenger_route_uuid, 
			:messenger_route_detail_order, :messenger_channel_uuid, 
			:messenger_route_detail_settings, :messenger_route_detail_enabled
		);
EOF;

			$update_details_sql = <<<EOF
		UPDATE v_messenger_route_details
			SET messenger_route_detail_order=:messenger_route_detail_order,
				messenger_channel_uuid=:messenger_channel_uuid,
				messenger_route_detail_settings=:messenger_route_detail_settings,
				messenger_route_detail_enabled=:messenger_route_detail_enabled
		WHERE messenger_route_detail_uuid=:messenger_route_detail_uuid;
EOF;

			$sql = ($action == 'add') ? $insert_routes_sql : $update_routes_sql;
			$query = $db->prepare($sql);
			$query->bindParam(':domain_uuid',                 $domain_uuid);
			$query->bindParam(':messenger_route_uuid',        $messenger_route_uuid);
			$query->bindParam(':messenger_route_context',     $messenger_route_context);
			$query->bindParam(':messenger_route_name',        $messenger_route_name);
			$query->bindParam(':messenger_route_type',        $messenger_route_type);
			$query->bindParam(':messenger_route_destination', $messenger_route_destination);
			$query->bindParam(':messenger_route_description', $messenger_route_description);
			$query->bindParam(':messenger_route_enabled',     $messenger_route_enabled);

			// $db->beginTransaction();
			$result = $query->execute();
			unset($result, $query);

			$iquery = $db->prepare($insert_details_sql);
			$uquery = $db->prepare($update_details_sql);

			foreach($messenger_route_details as $row){
				if(empty($row['messenger_route_detail_uuid'])){
					$row['messenger_route_detail_uuid'] = uuid();
					if(empty($row['messenger_channel_uuid'])){
						continue;
					}
					$query = $iquery;
				}
				else {
					$query = $uquery;
				}
				if (empty($row['messenger_channel_uuid'])) {
					$row['messenger_channel_uuid'] = null;
				}
				$query->bindParam(':messenger_route_uuid',               $messenger_route_uuid);
				$query->bindParam(':messenger_route_detail_uuid',        $row['messenger_route_detail_uuid']);
				$query->bindParam(':messenger_route_detail_order',       $row['messenger_route_detail_order']);
				$query->bindParam(':messenger_channel_uuid',             $row['messenger_channel_uuid']);
				$query->bindParam(':messenger_route_detail_settings',    $row['messenger_route_detail_settings']);
				$query->bindParam(':messenger_route_detail_enabled',     $row['messenger_route_detail_enabled']);
				$query->execute();
			}

			// $db->commit();

		//redirect the user
			if ($action == 'add') {
				$_SESSION['message'] = $text['message-add'];
			}
			if ($action == 'update') {
					$_SESSION['message'] = $text['message-update'];
			}
			header('Location: ' . build_url($current_page, array('id' => $messenger_route_uuid)));
			exit;
	}

//pre-populate the form
	if (!$is_post_form) {
		if ($action == 'add'){
			$messenger_route_context     = $domain_name;
			$messenger_route_type        = 'sms';
			$messenger_route_enabled     = 'true';
			$messenger_route_details     = array();
		}
		else {
			$sql = 'select * from v_messenger_routes ';
			$sql .= 'where messenger_route_uuid = :messenger_route_uuid ';

			$query = $db->prepare($sql);
			$query->bindParam(':messenger_route_uuid', $messenger_route_uuid);
			$query->execute();
			$result = $query->fetch(PDO::FETCH_NAMED);

			$messenger_route_context     = $result['messenger_route_context'];
			$messenger_route_name        = $result['messenger_route_name'];
			$messenger_route_type        = $result['messenger_route_type'];
			$messenger_route_destination = $result['messenger_route_destination'];
			$messenger_route_enabled     = $result['messenger_route_enabled'];
			$messenger_route_description = $result['messenger_route_description'];

			$sql = 'select * from v_messenger_route_details ';
			$sql .= 'where messenger_route_uuid = :messenger_route_uuid ';
			$sql .= 'order by messenger_route_detail_order asc ';

			$query = $db->prepare($sql);
			$query->bindParam(':messenger_route_uuid', $messenger_route_uuid);
			$query->execute();
			$messenger_route_details = $query->fetchAll(PDO::FETCH_NAMED);

			$max_order = $messenger_route_details[count($messenger_route_details)-1]['messenger_route_detail_order'] + 10;
		}
		$row = array();
		$row['messenger_route_detail_uuid']     = NULL;
		$row['messenger_route_uuid']            = $messenger_route_uuid;
		$row['messenger_route_detail_order']    = "$max_order";
		$row['messenger_channel_uuid']          = NULL;
		$row['messenger_route_detail_settings'] = NULL;
		$row['messenger_route_detail_enabled']  = 'true';
		$messenger_route_details[] = $row;
	}

	$sql = 'select * from v_messenger_channels ';
	$sql .= 'where (domain_uuid is NULL or domain_uuid = :domain_uuid) ';

	$query = $db->prepare($sql);
	$query->bindParam(':domain_uuid', $domain_uuid);
	$query->execute();
	$result = $query->fetchAll(PDO::FETCH_NAMED);
	$messenger_channels = array(NULL => '');
	foreach($result as $row){
		$messenger_channels[ $row['messenger_channel_uuid'] ] = $row['messenger_channel_name'];
	}

	$messenger_orders = array();
	for($i = 0; $i <= 999; $i++) {
		$messenger_orders["$i"] = sprintf('%03d', $i);
	}

	$enabled_select = array(
		NULL    => '',
		'true'  => $text['label-true'],
		'false' => $text['label-false'],
	);

	$route_type_select = array(
		NULL    => '',
		'sip'   => 'SIP',
		'sms'   => 'SMS',
		'email' => 'EMail',
		'ussd'  => 'USSD',
	);

//show the header
	require_once "resources/header.php";

//javascript to change select to input and back again
	?><script language="javascript">
		function label_to_form(label_id, form_id) {
			if (document.getElementById(label_id) != null) {
				label = document.getElementById(label_id);
				label.parentNode.removeChild(label);
			}
			document.getElementById(form_id).style.display='';
		}
	</script>
<?php

//show the content
	echo "<form name='frm' id='frm' method='post' action=''>\n";
	echo "<table width='100%'  border='0' cellpadding='0' cellspacing='0'>\n";

	$route_title = $text['title-messenger_route'];
	$back_text = $text['button-back'];
	$save_text = $text['button-save'];

	echo "<tr>\n";
	echo "	<td width='10%' align='left'  valign='top' nowrap='nowrap'><b>$route_title</b><br><br></td>\n";
	echo "	<td width='90%' align='right' valign='top'>\n";
	echo "		<input type='button' class='btn' value='$back_text' alt='$back_text' onclick=\"window.location='" . escape($back_url) . "'\" >";
	echo "		<input type='submit' class='btn' name='submit' value='$save_text' alt='$save_text'>";
	echo "	</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "	<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo $text['label-messenger_route_context']."\n";
	echo "	</td>\n";
	echo "	<td class='vtable' align='left'>\n";
	echo "		<input class='formfld' type='text' name='messenger_route_context' maxlength='255' value='".escape($messenger_route_context)."'>\n";
	echo "		<br />\n";
	echo $text['description-messenger_route_context']."\n";
	echo "	</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "	<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo $text['label-messenger_route_name']."\n";
	echo "	</td>\n";
	echo "	<td class='vtable' align='left'>\n";
	echo "		<input class='formfld' type='text' name='messenger_route_name' maxlength='255' value='".escape($messenger_route_name)."'>\n";
	echo "		<br />\n";
	echo $text['description-messenger_route_name']."\n";
	echo "	</td>\n";
	echo "</tr>\n";

	echo "<tr>\n";
	echo "<td class='vncell' valign='top' align='left' nowrap='nowrap'>\n";
	echo $text['label-messenger_route_destination']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "		<select class='formfld' name='messenger_route_type'>\n";
	echo build_select_list($messenger_route_type, $route_type_select, NULL);
	echo "		</select>\n";
	echo "		<input class='formfld' type='text' name='messenger_route_destination' value='".escape($messenger_route_destination)."'>\n";
	echo "<br />\n";
	echo $text['description-messenger_route_destination']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	function echo_input_cell($row, $x, $table, $name, $style = ''){
		$value = escape($row[$name]);
		$class = 'vtablerow';
		echo "						<td class='$class' onclick=\"label_to_form('label_${name}_$x','${name}_$x');\" >\n";
		echo "							<label id='label_${name}_$x' style='margin-bottom: 0px'>$value</label>\n";
		echo "							<textarea id='${name}_$x' name='${table}[$x][$name]' class='formfld' style='width: 100%; max-width: 100%; display: none;'>$value</textarea>\n";
		echo "						</td>\n";
	}

	function echo_select_cell($row, $x, $table, $name, $array, $style = ''){
		$value = $row[$name];
		$display_value = $value;
		foreach($array as $k=>$v){
			if ($display_value === "$k") {
				$display_value = $v;
				break;
			}
		}
		$value = escape($value);
		$display_value = escape($display_value);
		$class = 'vtablerow';
		echo "						<td class='$class' onclick=\"label_to_form('label_${name}_$x','${name}_$x');\" >\n";
		echo "							<label id='label_${name}_$x' style='margin-bottom: 0px'>$display_value</label>\n";
		echo "							<select id='${name}_$x' name='${table}[$x][$name]' class='formfld' type='text' style='$style display: none;' placeholder='' value='$value' maxlength='255' >\n";
		foreach($array as $k=>$v){
			$k = escape("$k");
			$v = escape("$v");
			$selected = ($k === $value)?"selected='selected'":'';
			echo "								<option value='$k' $selected>$v</option>\n";
		}
		echo "							</select>\n";
		echo "						</td>\n";
	}

	echo "	<tr>\n";
	echo "		<td class='vncell' align='left'>\n";
	echo "			".$text['label-sip_profile_settings']."\n";
	echo "		</td>\n";
	echo "		<td class='vtable' align='left'>\n";
	echo "			<table class='tr_hover' style='margin-top: 0px;' width='100%' border='0' cellpadding='0' cellspacing='0'>\n\n";
	echo "				<tbody>\n";
	echo "					<tr>\n";
	echo "						<th class='vncellcolreq'>".$text['label-messenger_route_detail_order']    ."</th>\n";
	echo "						<th class='vncellcolreq'>".$text['label-messenger_channel_name']          ."</th>\n";
	echo "						<th class='vncellcolreq'>".$text['label-messenger_channel_enabled']       ."</th>\n";
	echo "						<th class='vncellcol'   >".$text['label-messenger_route_detail_settings'] ."</th>\n";
	echo "						<th>&nbsp;</th>\n";
	echo "					</tr>\n";
	$x = 0;
	foreach($messenger_route_details as $row) {
		$messenger_route_detail_uuid = $row['messenger_route_detail_uuid'];
		echo "					<tr>\n";
		echo "						<input type='hidden' name='messenger_route_details[$x][messenger_route_detail_uuid]' maxlength='255' value='".escape($row['messenger_route_detail_uuid'])."'>\n";
		echo "						<input type='hidden' name='messenger_route_details[$x][messenger_route_uuid]' maxlength='255' value='".escape($row['messenger_route_uuid'])."'>\n";
		echo_select_cell($row, $x, 'messenger_route_details', 'messenger_route_detail_order', $messenger_orders,   'width: 97px;');
		echo_select_cell($row, $x, 'messenger_route_details', 'messenger_channel_uuid',       $messenger_channels, 'width: auto;');
		echo_select_cell($row, $x, 'messenger_route_details', 'messenger_route_detail_enabled', array(
			''=>'', 'true' => $text['label-true'], 'false' => $text['label-false']
		), 'width: 97px;');
		echo_input_cell($row, $x, 'messenger_route_details', 'messenger_route_detail_settings', 'width: auto;');

		if(!empty($row['messenger_route_detail_uuid'])){
			$action = 'delete';
			$action_text =$text['button-delete'];
			$url = build_url($current_page, array_merge($current_params, array(
				'detail' => $messenger_route_detail_uuid,
				'a' => $action,
			)));
			$confirm_text = $text['confirm-delete'];
			echo "						<td class='list_control_icons' style='width: 25px;'>\n";
			echo "							<a href='$url' alt='$action_text' class='post_link' data-route='$messenger_route_detail_uuid' data-action='$action' data-confirm='$confirm_text'>$v_link_label_delete</a></td>\n";
			echo "						</td>\n";
		}
		echo "					</tr>\n";
		++$x;
	}
	echo "				</tbody>\n";
	echo "			</table>\n";
	echo "		</td>\n";
	echo "	</tr>\n";
	echo "<tr>\n";


	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-messenger_route_enabled']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "	<select class='formfld' name='messenger_route_enabled'>\n";
	echo build_select_list($messenger_route_enabled, $enabled_select, NULL);
	echo "	</select>\n";
	echo "<br />\n";
	echo $text['description-messenger_route_enabled']."\n";
	echo "</td>\n";
	echo "</tr>\n";
	echo "<tr>\n";

	echo "<td class='vncellreq' valign='top' align='left' nowrap='nowrap'>\n";
	echo "	".$text['label-messenger_route_description']."\n";
	echo "</td>\n";
	echo "<td class='vtable' align='left'>\n";
	echo "  <textarea class='formfld' type='text' name='messenger_route_description'>".escape($messenger_route_description)."</textarea>\n";
	echo "<br />\n";
	echo $text['description-messenger_route_description']."\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "	<tr>\n";
	echo "		<td colspan='2' align='right'>\n";
	if ($action == "update") {
		echo "				<input type='hidden' name='messenger_route_uuid' value='".escape($messenger_route_uuid)."'>\n";
	}
	echo "				<input type='submit' name='submit' class='btn' value='".$text['button-save']."'>\n";
	echo "		</td>\n";
	echo "	</tr>";

	echo "</table>";
	echo "</form>";
	echo "<br /><br />";
?>

<form id='action_form' method='post'>
	<input type='hidden' name='a'/>
	<input type='hidden' name='detail'/>
</form>"

<script type='text/javascript'>

var props_scheme = {
  "title": "Properties",
  "type": "array",
  "items": {
    "title": "Parameters",
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
            ]
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

</script>

<script type='text/javascript'>
$('.post_link').click(function(e){
	var action = $(this).attr('data-action');
	var route = $(this).attr('data-route');
	var confirm_text = $(this).attr('data-confirm');
	var href = $(this).attr('href');

	e.preventDefault();

	if (confirm_text && !confirm(confirm_text)) {
		return;
	}

	$('#action_form > input[name=a]').val(action);
	$('#action_form > input[name=detail]').val(route);
	$('#action_form').attr('action', href).submit();
});
</script>

<?php
//include the footer
	require_once "resources/footer.php";
