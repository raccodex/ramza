<?php
// +------------------------------------------------------------------------+
// | @author RacCodex (RacCodex)
// | @author_url 1: http://www.ramza.com
// | @author_url 2: http://codecanyon.net/user/RacCodex
// | @author_email: ramzasocial@gmail.com   
// +------------------------------------------------------------------------+
// | ramza - The Ultimate Social Networking Platform
// | Copyright (c) 2018 ramza. All rights reserved.
// +------------------------------------------------------------------------+

$response_data = array(
    'api_status' => 400
);

$required_fields =  array(
                        'send',
                        'top_up',
                        'pay',
                    );



if (!empty($_POST['type']) && in_array($_POST['type'], $required_fields)) {

    if ($_POST['type'] == 'send') {

        $user_id  = (!empty($_POST['user_id']) && is_numeric($_POST['user_id'])) ? $_POST['user_id'] : 0;
        $amount   = (!empty($_POST['amount']) && is_numeric($_POST['amount'])) ? $_POST['amount'] : 0;
        $userdata = $db->where('user_id', $user_id)->where('active', '1')->getOne(T_USERS);
        $wallet   = $wo['user']['wallet'];
        if (empty($user_id) || empty($amount) || empty($userdata) || empty(floatval($wallet)) || $amount < 0) {
            $error_code    = 5;
            $error_message = 'Please check your details.';
        } else if ($wallet < $amount) {
            $error_code    = 6;
            $error_message = 'The amount exceded your current wallet!';
        } else {
            $amount          = ($amount <= $wallet) ? $amount : $wallet;
            $up_data1        = array(
                'wallet' => sprintf('%.2f', $userdata->wallet + $amount)
            );
            $up_data2        = array(
                'wallet' => sprintf('%.2f', $wallet - $amount)
            );
            $currency        = Wo_GetCurrency($wo['config']['ads_currency']);
            $notif_msg       = $wo['lang']['sent_you'];
            $db->where('user_id', $user_id)->update(T_USERS, $up_data1);
            $db->where('user_id', $wo['user']['id'])->update(T_USERS, $up_data2);
            cache($wo['user']['id'], 'users', 'delete');
            cache($user_id, 'users', 'delete');
            $notification_data_array = array(
                'recipient_id' => $user_id,
                'type' => 'sent_u_money',
                'user_id' => $wo['user']['id'],
                'text' => "$notif_msg $amount$currency!",
                'url' => 'index.php?link1=wallet'
            );
            Wo_RegisterNotification($notification_data_array);
            $response_data = array(
                                    'api_status' => 200,
                                    'message' => "Money successfully sent."
                                );
        }


    }

    if ($_POST['type'] == 'top_up') {
        if (!empty($_POST['user_id']) && is_numeric($_POST['user_id']) && $_POST['user_id'] > 0 && !empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0) {
            $user   = Wo_UserData(Wo_Secure($_POST['user_id']));
            $amount = Wo_Secure($_POST['amount']);
            if (!empty($user)) {
                //encrease wallet value with posted amount
                $result = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = `wallet` + " . $amount . " WHERE `user_id` = '" . $user['id'] . "'");
                if ($result) {
                    cache($user['id'], 'users', 'delete');
                    $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $user['id'] . "', 'WALLET', '" . $amount . "', 'paypal')");
                }
                $user = Wo_UserData(Wo_Secure($_POST['user_id']));
                $response_data = array(
                                    'api_status' => 200,
                                    'message' => "The money successfully added to your wallet.",
                                    'wallet' => $user['wallet'],
                                    'balance' => $user['balance'],
                                );
            }
            else{
                $error_code    = 7;
                $error_message = 'user not found';
            }
        }
        else{
            $error_code    = 5;
            $error_message = 'Please check your details.';
        }

    }
    if ($_POST['type'] == 'pay') {
        try {
            payValidation();

            if ($_POST['pay_type'] == 'pro') {
                $img = $wo["pro_packages"][$_POST['pro_type']]['name'];
                $price = $wo["pro_packages"][$_POST['pro_type']]['price'];
                $pro_type        = $_POST['pro_type'];
                
                $update_array = array(
                    'is_pro' => 1,
                    'pro_time' => time(),
                    'pro_' => 1,
                    'pro_type' => $pro_type
                );
                if (in_array($pro_type, array_keys($wo['pro_packages'])) && $wo["pro_packages"][$pro_type]['verified_badge'] == 1) {
                    $update_array['verified'] = 1;
                }
                $mysqli             = Wo_UpdateUserData($wo['user']['user_id'], $update_array);

                $notes = json_encode([
                    'pro_type' => $pro_type,
                    'method_type' => 'wallet'
                ]);

                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$wo['user']['user_id']}, 'PRO', {$price}, '{$notes}')");
                $create_payment     = Wo_CreatePayment($pro_type);

                if ((!empty($_SESSION['ref']) || !empty($wo['user']['ref_user_id'])) && $wo['config']['affiliate_type'] == 1 && $wo['user']['referrer'] == 0) {
                    affiliateRef($price);
                }
                updatePoints($price);
                
                cache($wo['user']['id'], 'users', 'delete');

                $response_data = array(
                    'api_status' => 200,
                    'message' => "upgraded to pro"
                );
            }
            elseif ($_POST['pay_type'] == 'fund') {
                $fund_id = Wo_Secure($_POST['fund_id']);
                $price   = Wo_Secure($_POST['price']);
                $amount             = $price;
                $notes              = mb_substr($wo['fund']->title, 0, 100, "UTF-8");
                $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ({$wo['user']['user_id']}, 'DONATE', {$amount}, '{$notes}')");
                $wallet_amount      = ($wo["user"]['wallet'] - $price);
                $query_one          = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `wallet` = '{$wallet_amount}' WHERE `user_id` = {$wo['user']['user_id']} ");
                cache($wo['user']['id'], 'users', 'delete');
                $admin_com          = 0;
                if (!empty($wo['config']['donate_percentage']) && is_numeric($wo['config']['donate_percentage']) && $wo['config']['donate_percentage'] > 0) {
                    $admin_com = ($wo['config']['donate_percentage'] * $amount) / 100;
                    $amount    = $amount - $admin_com;
                }
                $user_data = Wo_UserData($wo['fund']->user_id);
                $db->where('user_id', $wo['fund']->user_id)->update(T_USERS, array(
                    'balance' => $user_data['balance'] + $amount
                ));
                cache($wo['fund']->user_id, 'users', 'delete');
                $fund_raise_id           = $db->insert(T_FUNDING_RAISE, array(
                    'user_id' => $wo['user']['user_id'],
                    'funding_id' => $fund_id,
                    'amount' => $amount,
                    'time' => time()
                ));
                $post_data               = array(
                    'user_id' => Wo_Secure($wo['user']['user_id']),
                    'fund_raise_id' => $fund_raise_id,
                    'time' => time(),
                    'multi_image_post' => 0
                );
                $id                      = Wo_RegisterPost($post_data);
                $notification_data_array = array(
                    'recipient_id' => $wo['fund']->user_id,
                    'type' => 'fund_donate',
                    'url' => 'index.php?link1=show_fund&id=' . $wo['fund']->hashed_id
                );
                Wo_RegisterNotification($notification_data_array);
                $response_data = array(
                    'api_status' => 200,
                    'message' => "Payment successfully done"
                );
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