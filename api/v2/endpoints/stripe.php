<?php
include_once('assets/includes/stripe_config.php');

if (empty($_POST['type']) || !in_array($_POST['type'], ['createsession','success'])) {
	$error_code    = 4;
	$error_message = 'type can not be empty';
}
else{

	if ($_POST['type'] == 'createsession') {

		try {

			stripeCreateSessionValidation();

			$amount = $_POST['amount'] * 100;

			if (empty($_POST['payment_type'])) {
				$_POST['payment_type'] = '';
			}

			$payment_method_types = array('card');
			if ($wo['config']['alipay'] == 'yes' && $_POST['payment_type'] == 'alipay') {
				$payment_method_types = array('alipay');
			}
			$domain_url = $wo['config']['site_url'].'/requests.php';
		
			$checkout_session = \Stripe\Checkout\Session::create([
			    'payment_method_types' => [implode(',', $payment_method_types)],
			    'line_items' => [[
			      'price_data' => [
			        'currency' => $wo['config']['stripe_currency'],
			        'product_data' => [
			          'name' => 'wallet',
			        ],
			        'unit_amount' => $amount,
			      ],
			      'quantity' => 1,
			    ]],
			    'mode' => 'payment',
			    'success_url' => $domain_url . '?f=stripe&s=success&type=wallet',
			    'cancel_url' => $domain_url . '?f=stripe&s=cancel&type=wallet',
		    ]);
		    if (!empty($checkout_session) && !empty($checkout_session['id'])) {
		    	$_SESSION['stripe_session_payment_intent'] = $checkout_session['id'];

		    	$response_data = array(
			        'api_status' => 200,
			        'sessionId' => $checkout_session['id']
			    );
		    }
		    else{
		    	throw new Exception($wo['lang']['something_wrong']);
		    }
		}
		catch (Exception $e) {
	        $error_code    = 4;
			$error_message = $e->getMessage();
		}
	}
	elseif ($_POST['type'] == 'success') {

		if (empty($_POST['token'])) {
			$error_code    = 4;
		    $error_message = 'token can not be empty';
		}
		else{
			if (!empty($_POST['price']) && is_numeric($_POST['price']) && $_POST['price'] > 0) {
				try {
					$price = Wo_Secure($_POST['price']);
					cache($wo['user']['id'], 'users', 'delete');
					$customer = \Stripe\Customer::create(array(
		                'source' => $_POST['token']
		            ));
		            $charge   = \Stripe\Charge::create(array(
		                'customer' => $customer->id,
		                'amount' => $price * 100,
		                'currency' => $wo['config']['stripe_currency']
		            ));
		            if ($charge) {
		            	$result = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = `wallet` + " . $amount . " WHERE `user_id` = '" . $wo['user']['id'] . "'");
			            if ($result) {
							cache($wo['user']['id'], 'users', 'delete');
			                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['id'] . "', 'WALLET', '" . $amount . "', 'stripe')");
			            }

			            $user = Wo_UserData($wo['user']['user_id']);
			            
						$response_data = array(
			                                'api_status' => 200,
			                                'message' => 'payment successfully',
		                                    'wallet' => $user['wallet'],
		                                    'balance' => $user['balance'],
			                            );
						echo json_encode($response_data, JSON_PRETTY_PRINT);
						exit();

		            }
		            else{
		            	$error_code    = 5;
					    $error_message = 'something went wrong';
		            }
				} catch (Exception $e) {
					$error_code    = 8;
					$error_message = $e->getMessage();
				}
			}
			else{
				$error_code    = 4;
			    $error_message = 'price can not be empty';
			}
		}
	}
}


