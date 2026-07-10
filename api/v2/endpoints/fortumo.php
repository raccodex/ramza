<?php
if ($_POST['type'] == 'pay')
{
    $response_data = array(
        'api_status' => 200,
        'url' => 'https://pay.fortumo.com/mobile_payments/' . $wo['config']['fortumo_service_id'] . '?cuid=' . $wo['user']['user_id']
    );
}
elseif ($_POST['type'] == 'success_fortumo')
{

    try
    {
        fortumoSuccessValidation();

        $amount = (int)Wo_Secure($_POST['price']);

        $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, array(
            'wallet' => $db->inc($amount)
        ));

        cache($wo['user']['user_id'], 'users', 'delete');

        $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['user_id'] . "', 'WALLET', '" . $amount . "', 'fortumo')");

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

