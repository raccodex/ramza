<?php
if ($_POST['type'] == 'pay')
{
    if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0)
    {
        $amount = (int)Wo_Secure($_POST['amount']);
        if (empty($wo['config']['coinpayments_coin']))
        {
            $wo['config']['coinpayments_coin'] = 'BTC';
        }
        $result = coinpayments_api_call(array(
            'key' => $wo['config']['coinpayments_public_key'],
            'version' => '1',
            'format' => 'json',
            'cmd' => 'create_transaction',
            'amount' => $amount,
            'currency1' => $wo['config']['currency'],
            'currency2' => $wo['config']['coinpayments_coin'],
            'custom' => $amount,
            'success_url' => $wo['config']['site_url'] . '/wallet',
            'cancel_url' => $wo['config']['site_url'] . '/requests.php?f=pay_with_bitcoin&s=cancel_coinpayments',
            'buyer_email' => $wo['user']['email']
        ));

        if (!empty($result) && $result['status'] == 200)
        {
            $db->insert(T_PENDING_PAYMENTS, array(
                'user_id' => $wo['user']['user_id'],
                'payment_data' => $result['data']['txn_id'],
                'method_name' => 'coinpayments',
                'time' => time()
            ));
            $response_data = array(
                'api_status' => 200,
                'url' => $result['data']['checkout_url']
            );
        }
        else
        {
            $error_code = 4;
            $error_message = $result['message'];
        }
    }
    else
    {
        $error_code = 4;
        $error_message = 'amount can not be empty';
    }
}
elseif ($_POST['type'] == 'callback')
{
    try
    {
        coinpaymentsCallbackValidation();

        include_once ('assets/libraries/coinpayments.php');
        $CP = new \MineSQL\CoinPayments();
        $CP->setMerchantId($wo['config']['coinpayments_id']);
        $CP->setSecretKey($wo['config']['coinpayments_secret']);
        if ($CP->listen($_POST, $_SERVER))
        {
            // The payment is successful and passed all security measures
            $user_id = $_POST['user_id'];
            $txn_id = $_POST['txn_id'];
            $item_name = $_POST['item_name'];
            $amount1 = floatval($_POST['amount1']); //    The total amount of the payment in your original currency/coin.
            $amount2 = floatval($_POST['amount2']); //  The total amount of the payment in the buyer's selected coin.
            $status = intval($_POST['status']);
            //encrease wallet value with posted amount
            $result = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = `wallet` + " . $amount1 . " WHERE `user_id` = '$user_id'");
            if ($result)
            {
                cache($user_id, 'users', 'delete');
                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$user_id}, 'WALLET', {$amount1}, 'coinpayments')");
            }

            $user = Wo_UserData($user_id);

            $response_data = array(
                'api_status' => 200,
                'message' => 'payment successfully done',
                'wallet' => $user['wallet'],
                'balance' => $user['balance'],
            );
        }
        else
        {
            $error_code = 6;
            $error_message = 'the payment is pending.';
        }
    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}

