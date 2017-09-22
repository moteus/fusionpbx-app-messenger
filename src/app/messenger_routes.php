<?php
require_once "root.php";
require_once "resources/require.php";
require_once "resources/check_auth.php";
require_once "resources/functions/messenger_utils.php";

if (permission_exists('messenger_route_list')) {
	//access granted
}
else {
	echo "access denied";
	exit;
}

//add multi-lingual support
	$language = new text;
	$text = $language->get();

//get variables used to control the order
	$order_by = $_GET["order_by"];
	$order = $_GET["order"];

//additional includes
	$document['title'] = $text['title-messenger-route'];

// current page name
	$current_page = 'messenger_routes.php';
	$domain_uuid = $_SESSION['domain_uuid'];
	$messenger_cli = MESSENGER_CLI_SCRIPT;

	require_once 'resources/header.php';
	require_once 'resources/paging.php';

	$show_all = permission_exists('messenger_route_all') && $_REQUEST['showall'] == 'true';
	$show_all_button = permission_exists('messenger_route_all') && !$show_all;

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

//delete route
	if ($_REQUEST['a']) {
		$message = array();
		$action = $_REQUEST['a'];

		if($action == 'delete'){
			$route_uuid = $_REQUEST['route'];
			if (!is_uuid($route_uuid)){
				$message['mood']    = 'negative';
				$message['message'] = '<strong>' . $text['error-route_action_failed'] . '</strong>: invalid route';
			}
			elseif(!permission_exists('messenger_route_delete')){
				$message['mood']    = 'negative';
				$message['message'] = '<strong>' . $text['error-route_action_failed'] . '</strong>: ' . $text['error-access-denied'];
			}
			else{
				$sql = 'delete from v_messenger_routes where messenger_route_uuid = :messenger_route_uuid ';
				if(!permission_exists('messenger_route_all')){
					$sql .= 'and domain_uuid = :domain_uuid ';
				}
				$query = $db->prepare($sql);
				$query->bindParam(':messenger_route_uuid', $route_uuid);
				$query->bindParam(':domain_uuid', $domain_uuid);
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
		elseif($action == 'reload'){
			if(!permission_exists('messenger_route_edit')){
					$message['mood']    = 'negative';
					$message['message'] = '<strong>' . $text['message-route_'.$action.'_failed'] . ':</strong> Access denied';
			}
			else{
				$cmd = "api lua $messenger_cli routes reload";
				$fp = event_socket_create($_SESSION['event_socket_ip_address'], $_SESSION['event_socket_port'], $_SESSION['event_socket_password']);
				if ($fp) {
					$response = event_socket_request($fp, $cmd);
					if($response == '+OK') {
						$message['message'] = '<strong>' . $text['message-route_' . $action] . '</strong>';
					}
					else{
						$message['mood']    = 'negative';
						$message['message'] = '<strong>' . $text['message-route_'.$action.'_failed'] . ':</strong> '.escape($response);
					}
				}
				else {
					$message['mood']    = 'negative';
					$message['message'] = '<strong>' . $text['message-route_'.$action.'_failed'] . '</strong>: ' . $text['error-event-socket'];
				}
			}
		}
		else{
			$message['mood']    = 'negative';
			$message['message'] = '<strong>' . $text['error-route_action_failed'] . '</strong>';
		}

		$_SESSION['message_mood'] = $message['mood'];
		$_SESSION['message']      = $message['message'];
		$back_params = json_decode($_POST['back']);
		$back_url = build_url($current_page, $back_params);

		header("Location: $back_url");
		exit;
	}

//calculate number of routes
	$sql = 'select count(*) as num_rows from v_messenger_routes ';
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
		'messenger_route_name',
		'messenger_route_type',
		'messenger_route_context',
		'messenger_route_destination',
		'messenger_route_description',
	);
	$known_orders = array('asc', 'desc');
	if(!in_array($order_by, $known_order_cols, true)){
		$order_by = 'messenger_route_destination';
	}
	if(!in_array($order, $known_orders, true)){
		$order = 'asc';
	}

	$sql = 'select r.* ';
	if ($show_all) {
		$sql .= ',d.domain_name ';
	}
	$sql .= 'from v_messenger_routes r ';
	if ($show_all) {
		$sql .= 'inner join v_domains d on r.domain_uuid = d.domain_uuid ';
	} else {
		$sql .= 'where r.domain_uuid = :domain_uuid ';
	}
	$sql .= "order by $order_by $order ";
	if($order_by != 'messenger_route_destination'){
		$sql .= ',messenger_route_destination asc ';
	}
	$sql .= 'limit :limit_start offset :limit_offset ';

	$prep_statement = $db->prepare(check_sql($sql));
	$prep_statement->bindParam(':domain_uuid',  $domain_uuid);
	$prep_statement->bindParam(':limit_start',  $rows_per_page);
	$prep_statement->bindParam(':limit_offset', $offset);
	$prep_statement->execute();
	$result = $prep_statement->fetchAll(PDO::FETCH_NAMED);
	$result_count = count($result);
	// var_dump($sql);die();
	unset ($prep_statement, $sql);

//show the content

	$routes_text = $text['header-messenger-routes'];
	$routes_desc = $text['description-messenger-routes'];
	$showall_text = $text['button-show_all'];
	$refresh_text = $text['button-refresh'];

	echo "<table width='100%' border='0' cellpadding='0' cellspacing='0'>\n";
	echo "	<tr>\n";
	echo "		<td width='50%' align='left' valign='top' nowrap='nowrap'>";
	echo "			<b>$routes_text ($num_rows)</b>";
	echo "			<br /><br />$routes_desc";
	echo "		</td>\n";
	echo "		<td width='50%' align='right' valign='top'>\n";
	if ($show_all_button) {
		echo "		<input type='button' class='btn' value='$showall_text' alt='$showall_text' onclick=\"window.location='" . escape($show_all_url) . "';\">\n";
	}
	if(permission_exists('messenger_route_edit')){
		$action = 'reload';
		$action_text = $text['button-reload'];
		$url = build_url($current_page, array_merge($current_params, array(
			'a' => $action,
		)));
		$confirm_text = $text['confirm-reload'];
		echo "<input type='button' class='btn post_link' data-action='$action' value='$action_text' alt='$action_text' onclick=\"window.location='" . escape($url) . "';\">\n";
	}
	echo "			<input type='button' class='btn' value='$refresh_text' alt='$refresh_text' onclick='document.location.reload();' >\n";
	echo "		</td>\n";
	echo "	</tr>\n";
	echo "</table>\n";
	echo "<br />\n";

	echo "<table class='tr_hover' width='100%' border='0' cellpadding='0' cellspacing='0'>\n";

	echo "<tr>\n";
	if ($show_all) {
		echo th_order_by('domain_name', $text['label-domain-name'], $order_by, $order, null, null, $param);
	}
	echo th_order_by('messenger_route_name',        $text['label-messenger_route_name'],        $order_by, $order, null, null, $param);
	echo th_order_by('messenger_route_context',     $text['label-messenger_route_context'],     $order_by, $order, null, null, $param);
	echo th_order_by('messenger_route_type',        $text['label-messenger_route_type'],        $order_by, $order, null, null, $param);
	echo th_order_by('messenger_route_destination', $text['label-messenger_route_destination'], $order_by, $order, null, null, $param);
	echo '<th>'; echo $text['label-messenger_route_description']; echo '</th>';

	echo "<td class='list_control_icons'>";
	if (permission_exists('messenger_route_add')) {
		$url = build_url('messenger_route_edit.php');
		$action_text = $text['button-add'];
		echo "<a href='$url' alt='$action_text'>$v_link_label_add</a>";
	}
	echo "</td>\n";
	echo "</tr>\n";

	if ($result_count > 0) {
		$c = 0;
		$row_style = array(0 => 'row_style0', 1 => 'row_style1');
		foreach($result as $row) {
			$base_td = "	<td valign='top' class='".$row_style[$c];
			$route_uuid = $row['messenger_route_uuid'];

			if ($show_all) {
				echo $base_td ."'>".(($row['domain_name'] === NULL) ? '' : escape($row['domain_name']))."</td>\n";
			}

			echo $base_td ."'>".escape($row['messenger_route_name'])        . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_route_context'])     . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_route_type'])        . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_route_destination']) . "</td>\n";
			echo $base_td ."'>".escape($row['messenger_route_description']) . "</td>\n";

			echo "	<td class='list_control_icons'>";
			if (permission_exists('messenger_route_edit')) {
				$url = build_url('messenger_route_edit.php', array(
					'id' => $route_uuid,
				));
				$action_text = $text['label-message_view'];
				echo "		<a href='$url' alt='$action_text'>$v_link_label_edit</a>";
			}
			if (permission_exists('messenger_route_delete')) {
				$action = 'delete';
				$action_text =$text['button-delete'];
				$url = build_url($current_page, array_merge($current_params, array(
					'route' => $route_uuid,
					'a' => $action,
				)));
				$confirm_text = $text['confirm-delete'];
				echo "		<a href='$url' alt='$action_text' class='post_link' data-route='$route_uuid' data-action='$action' data-confirm='$confirm_text'>$v_link_label_delete</a></td>\n";
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
	<input type='hidden' name='route'/>
	<input type='hidden' name='back' value=''/>
</form>"

<script type='text/javascript'>
$('.post_link').click(function(e){
	var action = $(this).attr('data-action');
	var route = $(this).attr('data-route');
	var confirm_text = $(this).attr('data-confirm');
	var href = $(this).attr('href');
	var back = <?php echo json_encode($current_params); ?>;

	e.preventDefault();

	if (confirm_text && !confirm(confirm_text)) {
		return;
	}

	$('#action_form > input[name=a]').val(action);
	$('#action_form > input[name=route]').val(route);
	$('#action_form > input[name=back]').val(JSON.stringify(back));
	$('#action_form').attr('action', href).submit();
});
</script>

<?php
//include the footer
	require_once "resources/footer.php";
