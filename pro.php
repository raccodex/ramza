<?php 
require_once('assets/init.php');
if (!empty($_GET['first']) && !empty($_GET['action']) && $_GET['first'] == 'notify' && $_GET['action'] == 'notify') {
    if (empty($_POST['payment_status']) || empty($_POST['item_name'])) {
        exit();
    }

    // Verify IPN notification with PayPal to prevent unauthenticated spoofing
    $raw_post_data = file_get_contents('php://input');
    $raw_post_array = explode('&', $raw_post_data);
    $myPost = array();
    foreach ($raw_post_array as $keyval) {
        $keyval = explode('=', $keyval);
        if (count($keyval) == 2) {
            $myPost[$keyval[0]] = urldecode($keyval[1]);
        }
    }
    $req = 'cmd=_notify-validate';
    foreach ($myPost as $key => $value) {
        $req .= '&' . $key . '=' . urlencode($value);
    }
    $paypal_mode = !empty($wo['config']['paypal_mode']) ? $wo['config']['paypal_mode'] : 'live';
    $paypal_url = ($paypal_mode == 'sandbox') ? 'https://ipnpb.sandbox.paypal.com/cgi-bin/webscr' : 'https://ipnpb.paypal.com/cgi-bin/webscr';
    $ch = curl_init($paypal_url);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));
    $res = curl_exec($ch);
    curl_close($ch);

    if (strcmp(trim((string)$res), "VERIFIED") !== 0) {
        exit("IPN verification failed");
    }

	if (($_POST['payment_status'] == 'Completed' || $_POST['payment_status'] == 'Processed' || $_POST['payment_status'] == 'In-Progress' || $_POST['payment_status'] == 'Pending') && strpos($_POST['item_name'], 'user') !== false) {
		$user_id = (int) substr($_POST['item_name'], strpos($_POST['item_name'], 'user') + 4);
		$user = Wo_UserData($user_id);
		if (!empty($user)) {
			$amount1  = floatval($_POST['mc_gross']);
			$pro_type = (int) $user['pro_type'];

			$update_array = array(
                'is_pro' => 1,
                'pro_time' => time(),
                'pro_' => 1,
                'pro_type' => $pro_type
            );
            if (in_array($pro_type, array_keys($wo['pro_packages'])) && $wo['pro_packages'][$pro_type]['verified_badge'] == 1) {
                $update_array['verified'] = 1;
            }
            $mysqli = Wo_UpdateUserData($user['user_id'], $update_array);

            global $sqlConnect;
            $img = $wo['lang']['star'];
            if ($pro_type == 1) {
                $img = $wo['lang']['star'];
            } else if ($pro_type == 2) {
                $img = $wo['lang']['hot'];
            } else if ($pro_type == 3) {
                $img = $wo['lang']['ultima'];
            } else if ($pro_type == 4) {
                $img = $wo['lang']['vip'];
            }
            $notes = Wo_Secure($wo['lang']['upgrade_to_pro'] . " " . $img . " : PayPal");
            $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('{$user['user_id']}', 'PRO', '{$amount1}', '{$notes}')");
            $create_payment = Wo_CreatePayment($pro_type);
		}
	}
	elseif (($_POST['payment_status'] == 'Declined' || $_POST['payment_status'] == 'Expired' || $_POST['payment_status'] == 'Failed' || $_POST['payment_status'] == 'Refunded' || $_POST['payment_status'] == 'Reversed') && strpos($_POST['item_name'], 'user') !== false) {
		$user_id = (int) substr($_POST['item_name'], strpos($_POST['item_name'], 'user') + 4);
		$user = Wo_UserData($user_id);
		if (!empty($user)) {
			$update = Wo_UpdateUserData($user_id, array(
	            'is_pro' => 0
	        ));
	        $mysql_query = mysqli_query($sqlConnect, "UPDATE " . T_PAGES . " SET `boosted` = '0' WHERE `user_id` = {$user_id}");
	        $mysql_query = mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET `boosted` = '0' WHERE `user_id` = {$user_id}");
	        $mysql_query = mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET `boosted` = '0' WHERE `page_id` IN (SELECT `page_id` FROM " . T_PAGES . " WHERE `user_id` = {$user_id})");
		}
	}
}


?>

