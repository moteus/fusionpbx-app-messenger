<?php
require_once "root.php";
require_once "resources/require.php";
require_once "resources/check_auth.php";
require_once "resources/functions/messenger_utils.php";

if (permission_exists('messenger_channel_list')) {
	//access granted
}
else {
	echo "access denied";
	exit;
}

// unknown state
	$active_channels_list = false;

// messenger cli script
	$messenger_cli = MESSENGER_CLI_SCRIPT;

//add multi-lingual support
	$language = new text;
	$text = $language->get();

// current page name
	$current_page = 'messenger_channels.php';
	$domain_uuid = $_SESSION['domain_uuid'];

//start/stop/delete channel
	if ($_REQUEST['a']) {
		$message = array();

		$channel_uuid = $_REQUEST['channel'];
		if (!is_uuid($channel_uuid)){
			$message['mood']    = 'negative';
			$message['message'] = '<strong>' . $text['error-channel_action_failed'] . '</strong>';
		}
		else{
			$action = $_REQUEST['a'];
			if ($action == 'stop') {
				$cmd = "api lua $messenger_cli channel kill $channel_uuid";
			}
			if ($_GET["a"] == 'start') {
				$cmd = "api lua $messenger_cli channel start $channel_uuid";
			}

			if ($cmd) {
				if(!permission_exists('messenger_channel_star_stop')){
					$message['mood']    = 'negative';
					$message['message'] = '<strong>' . $text['error-channel_action_failed'] . '</strong>: ' . $text['error-access-denied'];
				}
				else{
					$fp = event_socket_create($_SESSION['event_socket_ip_address'], $_SESSION['event_socket_port'], $_SESSION['event_socket_password']);
					if ($fp) {
						$response = event_socket_request($fp, $cmd);
						if($response == '+OK') {
							$message['message'] = '<strong>' . $text['message-channel_' . $action] . '</strong>';
						}
						else{
							$message['mood']    = 'negative';
							$message['message'] = '<strong>' . $text['message-channel_'.$action.'_failed'] . ':</strong> '.escape($response);
						}
					}
					else {
						$message['mood']    = 'negative';
						$message['message'] = '<strong>' . $text['message-channel_'.$action.'_failed'] . '</strong>: ' . $text['error-event-socket'];
					}
				}
			}
			elseif($action == 'delete'){
				//! @todo try stop channel.
				// it has problem because what if user has no permission to start/stop but only for delete
				// also what if we have no access to FS ESL.
				// But now it possible create channel, start it and the remove. So channel will be invisible
				if(!permission_exists('messenger_channel_delete')){
					$message['mood']    = 'negative';
					$message['message'] = '<strong>' . $text['error-channel_action_failed'] . '</strong>: ' . $text['error-access-denied'];
				}
				else{
					$sql = 'delete from v_messenger_channels where messenger_channel_uuid = :messenger_channel_uuid ';
					if(!permission_exists('messenger_channel_all')){
						$sql .= 'and domain_uuid = :domain_uuid ';
					}
					$query = $db->prepare($sql);
					$query->bindParam(':messenger_channel_uuid',  $channel_uuid);
					$query->bindParam(':domain_uuid', $domain_uuid);
					$result = $query->execute();
					if($result){
						$message['message'] = '<strong>' . $text['label-delete-complete'] . '</strong>';
					}
					else{
						$message['mood'] = 'negative';
						$message['message'] = '<strong>' . $text['error-channel_action_failed'] . '</strong>: ' . $text['error-access-denied'];
					}

				}
			}
			else{
				$message['mood']    = 'negative';
				$message['message'] = '<strong>' . $text['error-channel_action_failed'] . '</strong>';
			}
		}

		$_SESSION['message_mood'] = $message['mood'];
		$_SESSION['message']      = $message['message'];
		$back_params = json_decode($_POST['back']);
		$back_url = build_url($current_page, $back_params);

		header("Location: $back_url");
		exit;
	}

	$fp = event_socket_create($_SESSION['event_socket_ip_address'], $_SESSION['event_socket_port'], $_SESSION['event_socket_password']);
	if ($fp) {
		$cmd = "api lua $messenger_cli channels list";
		$response = event_socket_request($fp, $cmd);
		fclose($fp);
		$active_channels_list = json_decode($response);
		if ($active_channels_list === null) {
			$active_channels_list = false;
		}
	}

	function is_channel_active($channel_uuid){
		global $active_channels_list;
		foreach($active_channels_list as $channel){
			if($channel[0] == $channel_uuid){
				return true;
			}
		}
		return false;
	}

//get variables used to control the order
	$order_by = $_GET['order_by'];
	$order = $_GET['order'];
	$page = $_GET['page'];
	$show_all = permission_exists('messenger_channel_all') && ($_GET['showall'] == 'true');
	$show_all_button = permission_exists('messenger_channel_all') && !$show_all;

//build current url
	$current_params = array(
		'order_by' => $order_by,
		'order'    => $order,
		'page'     => $page,
		'showall'  => $show_all ? 'true' : '',
	);
	$current_url = build_url($current_page, $current_params);
	if ($show_all_button) {
		$show_all_url = build_url($current_page, array_merge($current_params, array(
			'showall' => 'true',
		)));
	}
	else{
		$show_back_url = build_url($current_page, array_merge($current_params, array(
			'showall' => NULL,
		)));
	}

//additional includes
	$document['title'] = $text['title-messenger-channels'];

	require_once 'resources/header.php';
	require_once 'resources/paging.php';

//calculate number of channels
	$sql = 'select count(*) as num_rows from v_messenger_channels ';
	$sql .= $show_all ? ' ' : 'where (domain_uuid is NULL or domain_uuid = :domain_uuid) ';
	$query = $db->prepare($sql);
	if ($query) {
		$query->bindParam(':domain_uuid', $domain_uuid);
		$query->execute();
		$row = $query->fetch(PDO::FETCH_ASSOC);
		$num_rows = $row['num_rows'];
	}
	$num_rows = $num_rows ? $num_rows : 0;

//prepare to page the results
	$rows_per_page = ($_SESSION['domain']['paging']['numeric'] != '') ? $_SESSION['domain']['paging']['numeric'] : 50;
	if (strlen($page) == 0) { $page = 0; $_GET['page'] = 0; }
	list($paging_controls, $rows_per_page, $var3) = paging($num_rows, $param, $rows_per_page);
	$offset = $rows_per_page * $page;
	$param = $show_all ? '&showall=true' : '';

//get the list
	$known_order_cols = array('domain_name', 'messenger_channel_name', 'messenger_channel_transport');
	$known_orders = array('asc', 'desc');
	if(!in_array($order_by, $known_order_cols, true)){
		$order_by = 'messenger_channel_name';
	}
	if(!in_array($order, $known_orders, true)){
		$order = 'asc';
	}
	$sql = 'select c.* ';
	if ($show_all) {
		$sql .= ',d.domain_name ';
	}
	$sql .= 'from v_messenger_channels c ';
	if ($show_all) {
		$sql .= 'left outer join v_domains d on c.domain_uuid = d.domain_uuid ';
	} else {
		$sql .= 'where (c.domain_uuid is NULL or c.domain_uuid = :domain_uuid) ';
	}

	$sql .= 'order by '.$order_by." ".$order." ";
	$sql .= 'limit :limit_start offset :limit_offset ';
	$prep_statement = $db->prepare(check_sql($sql));
	$prep_statement->bindParam(':domain_uuid',  $domain_uuid);
	$prep_statement->bindParam(':limit_start',  $rows_per_page);
	$prep_statement->bindParam(':limit_offset', $offset);
	$prep_statement->execute();
	$result = $prep_statement->fetchAll(PDO::FETCH_NAMED);
	$result_count = count($result);
	unset ($prep_statement, $sql);

//show the content

	$show_all_text = $text['button-show_all'];
	$back_text = $text['button-back'];
	$refresh_button_text = $text['button-refresh'];
	$messenger_text = $text['header-messenger-channels'];
	$messenger_desc = $text['description-messenger-channels'];

	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo "	<tr>\n";
	echo "		<td width='50%' align='left' valign='top' nowrap='nowrap'>";
	echo "			<b>$messenger_text ($num_rows)</b>";
	echo "			<br /><br />";
	echo "			$messenger_desc";
	echo "		</td>\n";
	echo "		<td width='50%' align='right' valign='top'>\n";
	if ($show_all_button) {
		echo "		<input type='button' class='btn' value='$show_all_text' alt='$show_all_text' onclick=\"window.location='" . escape($show_all_url) . "'\">\n";
	}
	else {
		echo "		<input type='button' class='btn' value='$back_text' alt='$back_text' onclick=\"document.location.href='".escape($show_back_url)."'\";>";
	}
	echo "			<input type='button' class='btn' value='$refresh_button_text' alt='$refresh_button_text' onclick='document.location.reload()'>\n";
	echo "		</td>\n";
	echo "	</tr>\n";
	echo "</table>\n";
	echo "<br />\n";

	echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	if ($show_all) {
		echo th_order_by('domain_name', $text['label-domain-name'], $order_by, $order, null, null, $param);
	}
	echo th_order_by('messenger_channel_name',      $text['label-messenger_channel_name'],      $order_by, $order, null, null, $param);
	echo th_order_by('messenger_channel_transport', $text['label-messenger_channel_transport'], $order_by, $order, null, null, $param);
	echo "<th>".$text['label-enabled']."</th>\n";
	echo "<th>".$text['label-status']."</th>\n";
	if(permission_exists('messenger_channel_star_stop')){
		echo "<th>".$text['label-action']."</th>\n";
	}
	echo "<td class='list_control_icons'>";
	if (permission_exists('messenger_channel_add')) {
		$add_url = 'messenger_channel_edit.php';
		$add_text = $text['button-add'];
		echo "<a href='$add_url' alt='$add_text'>$v_link_label_add</a>";
	}
	echo "</td>\n";
	echo "</tr>\n";

	if ($result_count > 0) {
		$c = 0;
		$row_style = array(0 => 'row_style0', 1 => 'row_style1');
		foreach($result as $row) {
			$base_td = "	<td valign='top' class='".$row_style[$c];

			$channel_uuid = $row['messenger_channel_uuid'];

			if ($show_all) {
				echo $base_td ."'>".(($row['domain_name'] === NULL) ? '' : escape($row['domain_name']))."</td>\n";
			}

			echo $base_td ."'>".escape($row['messenger_channel_name'])     . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_channel_transport']). "</td>\n";
			echo $base_td ."'>".escape($row['messenger_channel_enabled'])  . "</td>\n";

			if ($active_channels_list === false) {
				$status = 'unknown';
			}
			elseif (is_channel_active($row['messenger_channel_uuid'])) {
				$status = 'running';
			}
			else {
				$status = 'stopped';
			}

			echo $base_td ."'>" .$text['label-status-' . $status] . "</td>\n";

			if(permission_exists('messenger_channel_star_stop')){
				if($status == 'unknown' || ($row['messenger_channel_enabled'] != 'true' && $status == 'stopped')){
					echo $base_td ."'></td>\n";
				}
				else{
					$action = ($status == 'running') ? 'stop' : 'start';
					$action_text = $text['label-action-' . $action];
					$url = build_url($current_page, array_merge($current_params, array(
						'channel' => $channel_uuid,
						'a' => $action,
					)));
					echo $base_td ."'>" . "<a href='$url' alt='$action_text' class='post_link' data-channel='$channel_uuid' data-action='$action'>$action_text</a></td>\n";
				}
			}

			echo "	<td class='list_control_icons'>";
			if (permission_exists('messenger_channel_edit')) {
				$edit_url = build_url('messenger_channel_edit.php', array(
					'id' => $channel_uuid,
				));
				$edit_text = $text['button-edit'];
				echo "		<a href='$edit_url' alt='$edit_text'>$v_link_label_edit</a>";
			}
			if (permission_exists('messenger_channel_delete')) {
				$action = 'delete';
				$action_text =$text['button-delete'];
				$url = build_url($current_page, array_merge($current_params, array(
					'channel' => $channel_uuid,
					'a' => $action,
				)));
				$confirm_text = $text['confirm-delete'];
				echo "		<a href='$url' alt='$action_text' class='post_link' data-channel='$channel_uuid' data-action='$action' data-confirm='$confirm_text'>$v_link_label_delete</a></td>\n";
			}
			echo "	</td>\n";
			echo "</tr>\n";
			$c = $c==0 ? 1 : 0;
		} //end foreach
		unset($sql, $result, $row_count);
	} //end if results

	echo "<tr>\n";
	echo "<td colspan='21' align='left'>\n";
	echo "	<table width='100%' cellpadding='0' cellspacing='0'>\n";
	echo "	<tr>\n";
	echo "		<td width='33.3%' nowrap='nowrap'>&nbsp;</td>\n";
	echo "		<td width='33.3%' align='center' nowrap='nowrap'>$paging_controls</td>\n";
	echo "		<td width='33.3%' nowrap='nowrap'>&nbsp;</td>\n";
	echo "	</tr>\n";
 	echo "	</table>\n";
	echo "</td>\n";
	echo "</tr>\n";

	echo "</table>";
	echo "<br /><br />";
?>

<form id='action_form' method='post'>
	<input type='hidden' name='a'/>
	<input type='hidden' name='channel'/>
	<input type='hidden' name='back' value=''/>
</form>"

<script type='text/javascript'>
$('.post_link').click(function(e){
	var action = $(this).attr('data-action');
	var channel = $(this).attr('data-channel');
	var confirm_text = $(this).attr('data-confirm');
	var href = $(this).attr('href');
	var back = <?php echo json_encode($current_params); ?>;

	e.preventDefault();

	if (confirm_text && !confirm(confirm_text)) {
		return;
	}

	$('#action_form > input[name=a]').val(action);
	$('#action_form > input[name=channel]').val(channel);
	$('#action_form > input[name=back]').val(JSON.stringify(back));
	$('#action_form').attr('action', href).submit();
});
</script>

<?php
//include the footer
	require_once "resources/footer.php";
