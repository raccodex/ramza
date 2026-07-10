<?php

if ($_POST['type'] == 'pay') {
    
    try {
        aamarpayPayValidation();
        
        $amount        = (int) Wo_Secure($_POST['amount']);
        $name          = Wo_Secure($_POST['name']);
        $email         = Wo_Secure($_POST['email']);
        $phone         = Wo_Secure($_POST['phone']);
        $base_url      = payUsingAamarpay($amount, $name, $email, $phone);
        $response_data = array(
            'api_status' => 200,
            'url' => $base_url
        );
        
    }
    catch (Exception $e) {
        $error_code    = 5;
        $error_message = $e->getMessage();
    }
} elseif ($_POST['type'] == 'success') {
    
    try {
        aamarpaySuccessValidation();
        
        $amount = (int) Wo_Secure($_POST['amount']);
        $db->where('user_id', $wo['user']['user_id'])->update(T_USERS, array(
            'wallet' => $db->inc($amount)
        ));
        
        cache($wo['user']['user_id'], 'users', 'delete');
        
        $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['user_id'] . "', 'WALLET', '" . $amount . "', 'aamarpay')");
        
        $user = Wo_UserData($wo['user']['user_id']);
        
        $response_data = array(
            'api_status' => 200,
            'message' => 'payment successfully done',
            'wallet' => $user['wallet'],
            'balance' => $user['balance']
        );
    }
    catch (Exception $e) {
        $error_code    = 5;
        $error_message = $e->getMessage();
    }
}