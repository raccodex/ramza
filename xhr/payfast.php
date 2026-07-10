<?php

require_once 'assets/libraries/payfastSDK/vendor/autoload.php';

use PayFast\PayFastPayment;

if ($f == "payfast") {
	if ($s == 'load') {
		$data['status'] = 400;

		try {

			if (empty($_POST['amount']) || !is_numeric($_POST['amount'])) {
				throw new Exception("amount can not be empty");
			}

			$amount = Wo_Secure($_POST['amount']);

			$payfast = new PayFastPayment(
			    [
			        'merchantId' => $wo['config']['payfast_merchant_id'],
			        'merchantKey' => $wo['config']['payfast_merchant_key'],
			        'passPhrase' => '',
			        'testMode' => ($wo['config']['payfast_mode'] == 'sandbox') ? true : false
			    ]
			);

			$callback_url = $wo['config']['site_url'] . "/requests.php?f=payfast&s=wallet&amount=".$amount."&user_id=".$wo['user']['user_id'];

			$data = [
			    // Merchant details
			    'return_url' => $callback_url,
			    'cancel_url' => $callback_url,
			    'notify_url' => $callback_url,
			    'amount' => $amount,
			    'item_name' => 'Wallet'
			];

			$htmlForm = $payfast->custom->createFormFields($data, ['value' => 'PLEASE PAY', 'class' => 'button-cta']);

			$data['status'] = 200;
	        $data['html'] = $htmlForm;

		} catch (Exception $e) {
			$data['message'] = $e->getMessage();
		}

        header("Content-type: application/json");
        echo json_encode($data);
        exit();


	}
	elseif ($s == 'wallet') {
		if (empty($_GET['amount']) || empty($_GET['user_id'])) {
			header("Location: " . Wo_SeoLink('index.php?link1=oops'));
	        exit();
		}

		$amount = Wo_Secure($_GET['amount']);

		try {
			$user = Wo_UserData(Wo_Secure($_GET['user_id']));
			if (!empty($user)) {
			    $payfast = new PayFastPayment(
				    [
				        'merchantId' => $wo['config']['payfast_merchant_id'],
				        'merchantKey' => $wo['config']['payfast_merchant_key'],
				        'passPhrase' => '',
				        'testMode' => ($wo['config']['payfast_mode'] == 'sandbox') ? true : false
				    ]
				);

			    $notification = $payfast->notification->isValidNotification($_POST, ['amount_gross' => $amount]);
			    if($notification === true) {
			        $db->where('user_id', $user['user_id'])->update(T_USERS, array(
	                    'wallet' => $db->inc($amount)
	                ));

					cache($user['user_id'], 'users', 'delete');

	                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $user['user_id'] . "', 'WALLET', '" . $amount . "', 'payfast')");

	                $_SESSION['replenished_amount'] = $amount;
	                if (!empty($_COOKIE['redirect_page'])) {
	                	$redirect_page = preg_replace('/on[^<>=]+=[^<>]*/m', '', $_COOKIE['redirect_page']);
					    $url = preg_replace('/\((.*?)\)/m', '', $redirect_page);
	                }
	                else{
	                	$url = Wo_SeoLink('index.php?link1=wallet');
	                }

					header("Location: " . $url);
		            exit();

			    } else {
			        header("Location: " . Wo_SeoLink('index.php?link1=oops'));
		            exit();
			    }
			}
			else{
				header("Location: " . Wo_SeoLink('index.php?link1=oops'));
		        exit();
			}
		} catch(Exception $e) {
		    header("Location: " . Wo_SeoLink('index.php?link1=oops'));
	        exit();
		}
	}
}