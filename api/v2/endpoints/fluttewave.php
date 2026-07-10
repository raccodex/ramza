<?php

if ($_POST['type'] == 'pay')
{
    try
    {
        fluttewavePayValidation();
        $email = $_POST['email'];
        $amount = $_POST['amount'];
        $res = fluttewavePay($amount, $email);
        if (!empty($res) && $res->status == 'success')
        {
            $response_data = array(
                'api_status' => 200,
                'url' => $res
                    ->data
                    ->link
            );
        }
        else
        {
            throw new Exception("something went wrong");
        }
    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}
elseif ($_POST['type'] == 'success')
{
    try
    {
        fluttewaveSuccessValidation();

        $res = fluttewaveVerify($_POST['transaction_id']);
        if ($res->status && !empty($res->data))
        {
            $amount = $res
                ->data->charged_amount;
            $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, array(
                'wallet' => $db->inc($amount)
            ));

            cache($wo['user']['user_id'], 'users', 'delete');

            $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['user_id'] . "', 'WALLET', '" . $amount . "', 'fluttewave')");

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
            throw new Exception("something went wrong");
        }

    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}

