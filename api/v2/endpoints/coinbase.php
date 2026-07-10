<?php
if ($_POST['type'] == 'create')
{
    try
    {
        if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0)
        {
            $amount = Wo_Secure($_POST['amount']);

            $postdata = array(
                'name' => 'Top Up Wallet',
                'description' => 'Top Up Wallet',
                'pricing_type' => 'fixed_price',
                'local_price' => array(
                    'amount' => $amount,
                    'currency' => $wo['config']['currency']
                ) ,
                'metadata' => array(
                    'user_id' => $wo['user']['user_id'],
                    'amount' => $amount
                ) ,
                "redirect_url" => $wo['config']['site_url'] . "/requests.php?f=coinbase&s=coinbase_handle&user_id=" . $wo['user']['user_id'],
                'cancel_url' => $wo['config']['site_url'] . "/requests.php?f=coinbase&s=coinbase_cancel&user_id=" . $wo['user']['user_id']
            );

            $result = createCoinbase($postdata);

            if (!empty($result) && !empty($result['data']) && !empty($result['data']['hosted_url']) && !empty($result['data']['id']) && !empty($result['data']['code']))
            {
                $db->insert(T_PENDING_PAYMENTS, array(
                    'user_id' => $wo['user']['user_id'],
                    'payment_data' => $result['data']['code'],
                    'method_name' => 'coinbase',
                    'time' => time()
                ));
                $response_data = array(
                    'api_status' => 200,
                    'url' => $result['data']['hosted_url']
                );
            }
            else
            {
                throw new Exception('something went wrong');
            }
        }
        else
        {
            throw new Exception('amount can not be empty');
        }
    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}
elseif ($_POST['type'] == 'coinbase_handle')
{
    try
    {
        if (!empty($_POST['user_id']) && is_numeric($_POST['user_id']))
        {

            $user_data = '';
            $coinbase_code = '';
            $user_id = Wo_Secure($_POST['user_id']);
            $payment_data = $db->objectBuilder()
                ->where('user_id', $user_id)->where('method_name', 'coinbase')
                ->orderBy('id', 'DESC')
                ->getOne(T_PENDING_PAYMENTS);
            if (!empty($payment_data))
            {
                $user_data = $db->objectBuilder()
                    ->where('id', $user_id)->getOne(T_USERS);
                $coinbase_code = $payment_data->payment_data;
            }

            if (!empty($user_data))
            {

                $result = chargeCoinbase($coinbase_code);

                if (!empty($result) && !empty($result['data']) && !empty($result['data']['pricing']) && !empty($result['data']['pricing']['local']) && !empty($result['data']['pricing']['local']['amount']) && !empty($result['data']['payments']) && !empty($result['data']['payments'][0]['status']) && $result['data']['payments'][0]['status'] == 'CONFIRMED')
                {
                    $amount = (int)$result['data']['pricing']['local']['amount'];
                    if (Wo_ReplenishingUserBalance($amount))
                    {
                        $db->where('user_id', $pt
                            ->user
                            ->id)
                            ->where('payment_data', $coinbase_code)->delete(T_PENDING_PAYMENTS);
                        $amount = Wo_Secure($amount);
                        $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['id'] . "', 'WALLET', '" . $amount . "', 'Coinbase')");

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
                        throw new Exception('something went wrong');
                    }
                }
                else
                {
                    throw new Exception('user not found');
                }
            }
            else
            {
                throw new Exception('user not found');
            }
        }
        else
        {
            throw new Exception('user_id can not be empty');
        }

    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}
elseif ($_POST['type'] == 'coinbase_cancel')
{
    if (!empty($_POST['user_id']) && is_numeric($_POST['user_id']))
    {
        $user_id = Wo_Secure($_POST['user_id']);
        $user = $db->where('user_id', $user_id)->getOne(T_USERS);
        if (!empty($user))
        {
            $db->where('user_id', $user->user_id)
                ->where('method_name', 'coinbase')
                ->delete(T_PENDING_PAYMENTS);
            $response_data = array(
                'api_status' => 200,
                'message' => 'payment canceled'
            );
        }
        else
        {
            $error_code = 6;
            $error_message = 'user not found';
        }
    }
    else
    {
        $error_code = 5;
        $error_message = 'user_id can not be empty';
    }
}

