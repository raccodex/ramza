<?php

if ($_POST['type'] == 'pay') {
	if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0) {
		$token = GetNgeniusToken();
		if (!empty($token->message)) {
			$error_code    = 6;
    		$error_message = $token->message;
		}
		elseif (!empty($token->errors) && !empty($token->errors[0]) && !empty($token->errors[0]->message)) {
			$error_code    = 7;
    		$error_message = $token->errors[0]->message;
		}
		else{
			$amount = (int) Wo_Secure($_POST['amount']);
			$postData = new StdClass();
		    $postData->action = "SALE";
		    $postData->amount = new StdClass();
		    $postData->amount->currencyCode = "AED";
		    $postData->amount->value = $amount;
		    $postData->merchantAttributes = new \stdClass();
	        $postData->merchantAttributes->redirectUrl = $wo['config']['site_url'] . "/requests.php?f=ngenius&s=success_ngenius&user_id=".$wo['user']['user_id'];
	        //$postData->merchantAttributes->redirectUrl = "http://192.168.1.108/ramza/requests.php?f=ngenius&s=success_ngenius&user_id=".$wo['user']['user_id'];
		    $order = CreateNgeniusOrder($token->access_token,$postData);
		    if (!empty($order->message)) {
		    	$error_code    = 8;
    			$error_message = $order->message;
    		}
    		elseif (!empty($order->errors) && !empty($order->errors[0]) && !empty($order->errors[0]->message)) {
    			$error_code    = 9;
    			$error_message = $order->errors[0]->message;
    		}
    		else{
    			$response_data = array(
	                'api_status' => 200,
	                'url' => $order->_links->payment->href
	            );
    		}
		}
	}
	else{
		$error_code    = 5;
    	$error_message = 'amount can not be empty';
	}
}
elseif ($_POST['type'] == 'success_ngenius') {
	if (!empty($_POST['ref']) && !empty($_POST['user_id'])) {
		$user = $db->objectBuilder()->where('user_id',Wo_Secure($_POST['user_id']))->getOne(T_USERS);
		if (!empty($user)) {
			$token = GetNgeniusToken();
    		if (!empty($token->message)) {
    			$error_code    = 7;
    			$error_message = $token->message;
    		}
    		elseif (!empty($token->errors) && !empty($token->errors[0]) && !empty($token->errors[0]->message)) {
    			$error_code    = 8;
    			$error_message = $token->errors[0]->message;
    		}
    		else{
    			$order = NgeniusCheckOrder($token->access_token,$_POST['ref']);
    			if (!empty($order->message)) {
    				$error_code    = 9;
    				$error_message = $order->message;
	    		}
	    		elseif (!empty($order->errors) && !empty($order->errors[0]) && !empty($order->errors[0]->message)) {
	    			$error_code    = 10;
    				$error_message = $order->errors[0]->message;
	    		}
	    		else{
	    			if ($order->_embedded->payment[0]->state == "CAPTURED") {
						$amount = Wo_Secure($order->amount->value);
						$db->where('user_id', $wo['user']['user_id'])->update(T_USERS, array(
		                    'wallet' => $db->inc($amount)
		                ));

						cache($wo['user']['user_id'], 'users', 'delete');

		                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['user_id'] . "', 'WALLET', '" . $amount . "', 'ngenius')");

		                $user = Wo_UserData($wo['user']['user_id']);

		                $response_data = array(
			                'api_status' => 200,
			                'message' => 'payment successfully done',
				            'wallet' => $user['wallet'],
				            'balance' => $user['balance'],
			            );
	    			}
	    			else{
	    				$error_code    = 11;
    					$error_message = 'something went wrong';
	    			}
	    		}
    		}
		}
		else{
			$error_code    = 6;
    		$error_message = 'user not found';
		}
	}
	else{
		$error_code    = 5;
    	$error_message = 'ref , user_id can not be empty';
	}
}