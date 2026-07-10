<?php

require_once 'assets/libraries/payfastSDK/vendor/autoload.php';

use PayFast\PayFastPayment;

$response_data = array(
    'api_status' => 400
);

$required_fields =  array(
                        'load',
                    );
if (!empty($_POST['type']) && in_array($_POST['type'], $required_fields)) {

	if ($_POST['type'] == 'load') {

		try {

			payFastLoadValidation();

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

			$data['api_status'] = 200;
	        $data['html'] = $htmlForm;

			$response_data = $data;

		} catch (Exception $e) {
			$error_code    = 5;
	    	$error_message = $e->getMessage();
		}
	}
	elseif ($_POST['type'] == 'wallet') {
		try {

			payFastLoadValidation();

			$amount = Wo_Secure($_POST['amount']);


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
		        $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, array(
                    'wallet' => $db->inc($amount)
                ));

				cache($wo['user']['user_id'], 'users', 'delete');

                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['user_id'] . "', 'WALLET', '" . $amount . "', 'payfast')");

                $user = Wo_UserData($wo['user']['user_id']);

			    $response_data = array(
			        'api_status' => 200,
			        'message' => 'payment successfully done',
			        'wallet' => $user['wallet'],
		            'balance' => $user['balance'],
			    );

		    } else {
		        throw new Exception("something went wrong");
		    }

		} catch (Exception $e) {
			$error_code    = 5;
	    	$error_message = $e->getMessage();
		}
	}
}
else{
    $error_code    = 4;
    $error_message = 'type can not be empty';
}