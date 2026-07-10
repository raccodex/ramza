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
$response_data   = array(
    'api_status' => 400
);

$required_fields = array(
    'user_id',
    'code'
);
foreach ($required_fields as $key => $value) {
    if (empty($_POST[$value]) && empty($error_code)) {
        $error_code    = 3;
        $error_message = $value . ' (POST) is missing';
    }
}
if (empty($error_code)) {
	$user_id      = $_POST['user_id'];
	$user = $db->where('user_id', $user_id)->getOne(T_USERS);
    if (empty($user)) {
        $error_code    = 3;
        $error_message = 'User not found';
    }
    else{

        $confirm_code = 0;
        if ($user->two_factor_method == 'google' || $user->two_factor_method == 'authy') {
            $codes = $db->where('user_id',$user_id)->getOne(T_BACKUP_CODES);
            if (!empty($codes) && !empty($codes->codes)) {
                $backupCodes = json_decode($codes->codes,true);
                if (in_array($_POST['code'], $backupCodes)) {
                    $key = array_search($_POST['code'], $backupCodes);
                    $backupCodes[$key] = rand(111111,999999);
                    $db->where('user_id',$user_id)->update(T_BACKUP_CODES,[
                        'codes' => json_encode($backupCodes)
                    ]);
                    $confirm_code = 1;
                }
            }
        }

        if ($user->two_factor_method == 'two_factor' && $user->email_code == md5($_POST['code'])) {
            $confirm_code = 1;
        }
        else if ($user->two_factor_method == 'google' && !empty($user->google_secret) && $confirm_code == 0) {
            require_once 'assets/libraries/google_auth/vendor/autoload.php';
            try {
                $google2fa = new \PragmaRX\Google2FA\Google2FA();
                if ($google2fa->verifyKey($user->google_secret, $_POST['code'])) {
                    $confirm_code = 1;
                }
            } catch (Exception $e) {
                $errors = $e->getMessage();
            }
        }
        else if ($user->two_factor_method == 'authy' && !empty($user->authy_id) && $confirm_code == 0 && verifyAuthy($_POST['code'],$user->authy_id)) {
            $confirm_code = 1;
        }


        if (empty($confirm_code)) {
            $error_code    = 3;
            $error_message = 'Wrong confirmation code.';
        }
        else{
            $time           = time();
            $cookie         = '';
            $access_token   = sha1(rand(111111111, 999999999)) . md5(microtime()) . rand(11111111, 99999999) . md5(rand(5555, 9999));
            $timezone       = 'UTC';
            $create_session = mysqli_query($sqlConnect, "INSERT INTO " . T_APP_SESSIONS . " (`user_id`, `session_id`, `platform`, `time`) VALUES ('{$user_id}', '{$access_token}', 'phone', '{$time}')");
            if (!empty($_POST['timezone'])) {
                $timezone = Wo_Secure($_POST['timezone']);
            }
            $add_timezone = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `timezone` = '{$timezone}' WHERE `user_id` = {$user_id}");
            // if (!empty($_POST['device_id'])) {
            //     $device_id = Wo_Secure($_POST['device_id']);
            //     $update    = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `device_id` = '{$device_id}' WHERE `user_id` = '{$user_id}'");
            // }
            if (!empty($_POST['android_m_device_id'])) {
                $device_id  = Wo_Secure($_POST['android_m_device_id']);
                $update  = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `android_m_device_id` = '{$device_id}' WHERE `user_id` = '{$user_id}'");
            }
            if (!empty($_POST['ios_m_device_id'])) {
                $device_id  = Wo_Secure($_POST['ios_m_device_id']);
                $update  = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `ios_m_device_id` = '{$device_id}' WHERE `user_id` = '{$user_id}'");
            }
            if (!empty($_POST['android_n_device_id'])) {
                $device_id  = Wo_Secure($_POST['android_n_device_id']);
                $update  = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `android_n_device_id` = '{$device_id}' WHERE `user_id` = '{$user_id}'");
            }
            if (!empty($_POST['ios_n_device_id'])) {
                $device_id  = Wo_Secure($_POST['ios_n_device_id']);
                $update  = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `ios_n_device_id` = '{$device_id}' WHERE `user_id` = '{$user_id}'");
            }
            if ($create_session) {
                cache($user_id, 'users', 'delete');
                $response_data = array(
                    'api_status' => 200,
                    'timezone' => $timezone,
                    'access_token' => $access_token,
                    'user_id' => $user_id,
                );
            }
        }
    }
}