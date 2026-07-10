<?php
use SecurionPay\SecurionPayGateway;
use SecurionPay\Exception\SecurionPayException;
use SecurionPay\Request\CheckoutRequestCharge;
use SecurionPay\Request\CheckoutRequest;

if ($_POST['type'] == 'create') {
	if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0) {
		require_once 'assets/libraries/securionpay/vendor/autoload.php';
		$price = Wo_Secure($_POST['amount']);
		$securionPay = new SecurionPayGateway($wo['config']['securionpay_secret_key']);

        $checkoutCharge = new CheckoutRequestCharge();
        $checkoutCharge->amount(($price * 100))->currency('USD')->metadata(array('user_key' => $wo['user']['user_id'],
                                                                                 'type' => 'Top Up Wallet'));

        $checkoutRequest = new CheckoutRequest();
        $checkoutRequest->charge($checkoutCharge);

        $signedCheckoutRequest = $securionPay->signCheckoutRequest($checkoutRequest);
        if (!empty($signedCheckoutRequest)) {
            $response_data = array(
                'api_status' => 200,
                'token' => $signedCheckoutRequest
            );
        }
        else{
        	$error_code    = 5;
    		$error_message = 'something went wrong';
        }
	}
	else{
		$error_code    = 4;
    	$error_message = 'amount can not be empty';
    }
}
elseif ($_POST['type'] == 'handle') {
	if (!empty($_POST) && !empty($_POST['charge_id'])) {
        $url = "https://api.securionpay.com/charges?limit=10";

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_USERPWD, $wo['config']['securionpay_secret_key'].":password");
        $resp = curl_exec($curl);
        curl_close($curl);
        $resp = json_decode($resp,true);
        if (!empty($resp) && !empty($resp['list'])) {
            foreach ($resp['list'] as $key => $value) {
                if ($value['id'] == $_POST['charge_id']) {
                    if (!empty($value['metadata']) && !empty($value['metadata']['user_key']) && !empty($value['amount'])) {
                        if ($wo['user']['user_id'] == $value['metadata']['user_key']) {
                        	$amount = intval(Wo_Secure($value['amount'])) / 100;
                        	if (Wo_ReplenishingUserBalance($amount)) {
	                            $create_payment_log             = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['id'] . "', 'WALLET', '" . $amount . "', 'securionpay')");

                                $user = Wo_UserData($wo['user']['user_id']);

	                            $response_data = array(
					                'api_status' => 200,
					                'message' => 'payment successfully done',
                                    'wallet' => $user['wallet'],
                                    'balance' => $user['balance'],
					            );
                        	}
                        }
                    }
                }
            }
        }
        else{
        	$error_code    = 5;
    		$error_message = 'something went wrong';
        }
    }
    else{
    	$error_code    = 4;
    	$error_message = 'please check details';
    }
}