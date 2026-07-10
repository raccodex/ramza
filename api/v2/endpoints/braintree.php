<?php
if ($_POST['type'] == 'checkout')
{
    try
    {
        if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0 && !empty($_POST['nonce']))
        {
            require_once 'assets/libraries/braintree/vendor/autoload.php';

            $gateway = new Braintree\Gateway(['environment' => $wo['config']['braintree_mode'], 'merchantId' => $wo['config']['braintree_merchant_id'], 'publicKey' => $wo['config']['braintree_public_key'], 'privateKey' => $wo['config']['braintree_private_key']]);

            $amount = $_POST["amount"];
            $nonce = $_POST["nonce"];

            $result = $gateway->transaction()
                ->sale(['amount' => $amount, 'paymentMethodNonce' => $nonce, 'options' => ['submitForSettlement' => true]]);

            if ($result->success || !is_null($result->transaction))
            {
                $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, array(
                    'wallet' => $db->inc($amount)
                ));

                cache($wo['user']['user_id'], 'users', 'delete');

                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['user_id'] . "', 'WALLET', '" . $amount . "', 'braintree')");

                $user = Wo_UserData($wo['user']['user_id']);

                $response_data = array(
                    'api_status' => 200,
                    'message' => 'payment successfully done',
                    'wallet' => $user['wallet'],
                    'balance' => $user['balance'],
                );

            }
            else
            {
                $errorString = "";

                foreach ($result
                    ->errors
                    ->deepAll() as $error)
                {
                    $errorString .= 'Error: ' . $error->code . ": " . $error->message . "\n";
                }

                throw new Exception($errorString);
            }
        }
        else
        {
            throw new Exception('amount , nonce can not be empty');
        }
    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}

