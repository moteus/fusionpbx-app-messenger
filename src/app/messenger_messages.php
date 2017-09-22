<?php
require_once "root.php";
require_once "resources/require.php";
require_once "resources/check_auth.php";
require_once "resources/functions/messenger_utils.php";

if (permission_exists('messenger_message_list')) {
	//access granted
}
else {
	echo "access denied";
	exit;
}

//add multi-lingual support
	$language = new text;
	$text = $language->get();
	$messenger_cli = MESSENGER_CLI_SCRIPT;

//get variables used to control the order
	$order_by = $_GET['order_by'];
	$order = $_GET['order'];
	$page = $_GET['page'];
	$show_all = permission_exists('messenger_channel_all') && ($_GET['showall'] == 'true');
	$show_all_button = permission_exists('messenger_channel_all') && !$show_all;

//build current url
	$current_page = 'messenger_messages.php';
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
	else {
		$show_back_url = build_url($current_page, array_merge($current_params, array(
			'showall' => NULL,
		)));
	}

//delete message
	if ($_REQUEST['a']) {
		$message = array();

		$messenger_message_uuid = $_REQUEST['msg'];
		if (!is_uuid($messenger_message_uuid)){
			$message['mood']    = 'negative';
			$message['message'] = '<strong>' . $text['error-message_action_failed'] . '</strong>';
		}
		else{
			$action = $_REQUEST['a'];

			if($action == 'delete'){
				if(!permission_exists('messenger_route_delete')){
					$message['mood']    = 'negative';
					$message['message'] = '<strong>' . $text['error-message_action_failed'] . '</strong>: ' . $text['error-access-denied'];
				}
				else{
					$sql = 'delete from v_messenger_messages where messenger_message_uuid = :messenger_message_uuid ';
					if(!permission_exists('messenger_message_all')){
						$sql .= 'and domain_uuid = :domain_uuid ';
					}
					$query = $db->prepare($sql);
					$query->bindParam(':messenger_message_uuid', $message_uuid);
					$query->bindParam(':domain_uuid', $domain_uuid);
					$result = $query->execute();
					if($result){
						$message['message'] = '<strong>' . $text['label-delete-complete'] . '</strong>';
					}
					else{
						$message['mood'] = 'negative';
						$message['message'] = '<strong>' . $text['error-message_action_failed'] . '</strong>: ' . $text['error-access-denied'];
					}
				}
			}
			elseif($action == 'resend'){
				$fp = event_socket_create($_SESSION['event_socket_ip_address'], $_SESSION['event_socket_port'], $_SESSION['event_socket_password']);
				if ($fp) {
					$cmd = "api lua $messenger_cli message resend $messenger_message_uuid";
					$response = event_socket_request($fp, $cmd);
					fclose($fp);

					if ($response != '+OK') {
						$message['mood']    = 'negative';
						$message['message'] = '<strong>' . $text['message-resend_failed'] . ':</strong> '.escape($response);
					}
					else {
						$message['message'] = '<strong>' . $text['message-message_resent'] . '</strong>';
					}
				}
				else{
					$message['mood']    = 'negative';
					$message['message'] = '<strong>' . $text['message-message_resent'] . '</strong>: ' . $text['error-event-socket'];
				}
			}
			else{
				$message['mood']    = 'negative';
				$message['message'] = '<strong>' . $text['error-message_action_failed'] . '</strong>';
			}
		}

		$_SESSION['message_mood'] = $message['mood'];
		$_SESSION['message']      = $message['message'];

		header("Location: $current_url");
		exit;
	}

//additional includes
	$document['title'] = $text['title-messenger-messages'];

	require_once 'resources/header.php';
	require_once 'resources/paging.php';

	$domain_uuid = $_SESSION['domain_uuid'];

//calculate number of messages
	$sql = 'select count(*) as num_rows from v_messenger_messages ';
	$sql .= $show_all ? '' : 'where domain_uuid = :domain_uuid ';
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
	$page = $_GET['page'];
	if (strlen($page) == 0) { $page = 0; $_GET['page'] = 0; }
	list($paging_controls, $rows_per_page, $var3) = paging($num_rows, $param, $rows_per_page);
	$offset = $rows_per_page * $page;
	$param = $show_all ? '&showall=true' : '';

//get the list
	$known_order_cols = array(
		'domain_name',
		'messenger_message_time',
		'messenger_channel_transport',
		'messenger_channel_name',
		'messenger_message_status',
		'messenger_message_status_time',
		'messenger_message_source',
		'messenger_message_source_destination',
		'messenger_message_destination',
	);
	$known_orders = array('asc', 'desc');
	if(!in_array($order_by, $known_order_cols, true)){
		$order_by = 'messenger_message_time';
		$order    = 'desc';
	}
	if(!in_array($order, $known_orders, true)){
		$order = 'asc';
	}

	$sql = 'select c.messenger_channel_name, c.messenger_channel_transport, m.* ';
	if ($show_all) {
		$sql .= ',d.domain_name ';
	}
	$sql .= 'from v_messenger_messages m left outer join v_messenger_channels c ';
	$sql .= 'on c.messenger_channel_uuid = m.messenger_channel_uuid ';
	if ($show_all) {
		$sql .= 'inner join v_domains d on m.domain_uuid = d.domain_uuid ';
	} else {
		$sql .= 'where m.domain_uuid = :domain_uuid ';
	}
	$sql .= "order by $order_by $order ";
	if($order_by != 'messenger_message_time'){
		$sql .= ',messenger_message_time desc ';
	}
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
	$refresh_text = $text['button-refresh'];

	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo "	<tr>\n";
	echo "		<td width='50%' align='left' valign='top' nowrap='nowrap'>";
	echo "			<b>".$text['header-messenger-messages']." ($num_rows)</b>";
	echo "			<br /><br />";
	echo "			".$text['description-messenger-messages'];
	echo "		</td>\n";
	echo "		<td width='50%' align='right' valign='top'>\n";
	if ($show_all_button) {
		echo "		<input type='button' class='btn' value='$show_all_text' alt='$show_all_text' onclick=\"window.location='".escape($show_all_url)."';\">\n";
	}
	else {
		echo "		<input type='button' class='btn' value='$back_text' alt='$back_text' onclick=\"document.location.href='".escape($show_back_url)."'\";>";
	}
	echo "			<input type='button' class='btn' value='$refresh_text' alt='$refresh_text' onclick='document.location.reload();'>\n";
	echo "		</td>\n";
	echo "	</tr>\n";
	echo "</table>\n";
	echo "<br />\n";

	echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	echo '<th>&nbsp;</th>';
	if ($show_all) {
		echo th_order_by('domain_name', $text['label-domain-name'], $order_by, $order, null, null, $param);
	}
	echo th_order_by('messenger_message_time',               $text['label-messenger_message_time'],               $order_by, $order, null, null, $param);
	echo th_order_by('messenger_message_source',             $text['label-messenger_message_source'],             $order_by, $order, null, null, $param);
	echo th_order_by('messenger_message_source_destination', $text['label-messenger_message_source_destination'], $order_by, $order, null, null, $param);
	echo th_order_by('messenger_message_destination',        $text['label-messenger_message_destination'],        $order_by, $order, null, null, $param);
	echo th_order_by('messenger_channel_transport',          $text['label-messenger_channel_transport'],          $order_by, $order, null, null, $param);
	echo th_order_by('messenger_channel_name',               $text['label-messenger_channel_name'],               $order_by, $order, null, null, $param);
	echo th_order_by('messenger_message_status',             $text['label-messenger_message_status'],             $order_by, $order, null, null, $param);
	echo th_order_by('messenger_message_status_time',        $text['label-messenger_message_status_time'],        $order_by, $order, null, null, $param);

	echo "<td class='list_control_icons'>";
	if (permission_exists('messenger_message_add')) {
		$url = build_url('messenger_message_edit.php', array());
		echo "<a href='$url' alt='".$text['button-add']."'>$v_link_label_add</a>";
	}
	echo "</td>\n";
	echo "</tr>\n";

	if ($result_count > 0) {
			$images = array(
				'inbound'  => array(
					'wait'    => if_ico_exists('waiting.png'),
					'sending' => if_ico_exists('question.png'),
					'success' => if_ico_exists('icon_cdr_inbound_answered.png'),
					'fail'    => if_ico_exists('icon_cdr_inbound_failed.png'),
				),
				'outbound' => array(
					'wait'    => if_ico_exists('waiting.png'),
					'sending' => if_ico_exists('question.png'),
					'success' => if_ico_exists('icon_cdr_outbound_answered.png'),
					'fail'    => if_ico_exists('icon_cdr_outbound_failed.png'),
				),
				'local'    => array(
					'wait'    => if_ico_exists('waiting.png'),
					'sending' => if_ico_exists('question.png'),
					'success' => if_ico_exists('icon_cdr_local_answered.png'),
					'fail'    => if_ico_exists('icon_cdr_local_failed.png'),
				),
			);

		$c = 0;
		$row_style = array(0 => 'row_style0', 1 => 'row_style1');
		foreach($result as $row) {
			$base_td = "	<td valign='top' class='".$row_style[$c];
			$message_uuid = $row['messenger_message_uuid'];

			echo $base_td ."'>";
			$direction = $row['messenger_message_direction'];
			$status    = $row['messenger_message_status'];
			$image = $images[$direction][$status];
			if ($image) {
				echo "<img src='$image' width='16' style='border: none; cursor: help;' ";
				echo "title='".$text["label-$direction"].": ".$text["label-$status"]."'>\n";
			}
			else { echo '&nbsp;'; }
			echo "</td>\n";

			if ($show_all) {
				echo $base_td ."'>".escape($row['domain_name'])."</td>\n";
			}

			echo $base_td ."'>".format_datetime($row['messenger_message_time'])        . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_message_source'])               . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_message_source_destination'])   . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_message_destination'])          . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_channel_transport'])            . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_channel_name'])                 . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_message_status'])               . "</td>\n";
			echo $base_td ."'>".format_datetime($row['messenger_message_status_time']) . "</td>\n";

			echo "	<td class='list_control_icons'>";
			if (permission_exists('messenger_message_view')) {
				$url = build_url('messenger_message_view.php', array(
					'id' => $message_uuid,
				));
				echo "		<a href='$url' alt='".$text['label-message_view']."'>$v_link_label_view</a>";
			}
			if (permission_exists('messenger_message_delete')) {
				$action = 'delete';
				$action_text =$text['button-delete'];
				$url = build_url($current_page, array_merge($current_params, array(
					'msg' => $message_uuid,
					'a' => $action,
				)));
				$confirm_text = $text['confirm-delete'];
				echo "		<a href='$url' alt='$action_text' class='post_link' data-message='$message_uuid' data-action='$action' data-confirm='$confirm_text'>$v_link_label_delete</a></td>\n";
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
	<input type='hidden' name='msg'/>
	<input type='hidden' name='back' value=''/>
</form>"

<script type='text/javascript'>
$('.post_link').click(function(e){
	var action = $(this).attr('data-action');
	var message = $(this).attr('data-message');
	var confirm_text = $(this).attr('data-confirm');
	var href = $(this).attr('href');
	var back = <?php echo json_encode($current_params); ?>;

	e.preventDefault();

	if (confirm_text && !confirm(confirm_text)) {
		return;
	}

	$('#action_form > input[name=a]').val(action);
	$('#action_form > input[name=msg]').val(message);
	$('#action_form > input[name=back]').val(JSON.stringify(back));
	$('#action_form').attr('action', href).submit();
});
</script>

<?php
//include the footer
	require_once "resources/footer.php";
