<?php
if ($_POST['type'] == 'create')
{
    try
    {
        yoomoneyCreateValidation();
        $amount = Wo_Secure($_POST['amount']);
        $order_id = uniqid();
        $receiver = $wo['config']['yoomoney_wallet_id'];
        $successURL = $wo['config']['site_url'] . "/requests.php?f=yoomoney&s=success";
        $form = '<form id="yoomoney_form" method="POST" action="https://yoomoney.ru/quickpay/confirm.xml">    
					<input type="hidden" name="receiver" value="' . $receiver . '"> 
					<input type="hidden" name="quickpay-form" value="donate"> 
					<input type="hidden" name="targets" value="transaction ' . $order_id . '">   
					<input type="hidden" name="paymentType" value="PC"> 
					<input type="hidden" name="sum" value="' . $amount . '" data-type="number"> 
					<input type="hidden" name="successURL" value="' . $successURL . '">
					<input type="hidden" name="label" value="' . $wo['user']['user_id'] . '">
				</form>';

        $response_data = array(
            'api_status' => 200,
            'html' => $form,
            'url' => 'https://yoomoney.ru/quickpay/confirm.xml',
            'receiver' => $receiver,
            'quickpay-form' => 'donate',
            'targets' => "transaction " . $order_id,
            'paymentType' => "PC",
            'sum' => $amount,
            'successURL' => $successURL,
            'label' => $wo['user']['user_id'],
        );

    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}
if ($_POST['type'] == 'success')
{
    try
    {
        yoomoneySuccessValidation();

        $amount = Wo_Secure($_POST['amount']);
        $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, array(
            'wallet' => $db->inc($amount)
        ));

        cache($wo['user']['user_id'], 'users', 'delete');

        $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['user_id'] . "', 'WALLET', '" . $amount . "', 'yoomoney')");

        $user = Wo_UserData($wo['user']['user_id']);

        $response_data = array(
            'api_status' => 200,
            'message' => 'payment successfully done',
            'wallet' => $user['wallet'],
            'balance' => $user['balance'],
        );

    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}

