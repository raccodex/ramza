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

$required_fields = array(
    'user_id',
    'code'
);
foreach ($required_fields as $key => $value)
{
    if (empty($_POST[$value]) && empty($error_code))
    {
        $error_code = 3;
        $error_message = $value . ' (POST) is missing';
    }
}
if (empty($error_code))
{
    $confirm_code = $_POST['code'];
    $user_id = $_POST['user_id'];
    $user = $db->where("(`sms_code` = '{$confirm_code}' OR `email_code` = '{$confirm_code}')")->getOne(T_USERS);

    if (empty($user))
    {
        $error_code = 3;
        $error_message = 'Wrong confirmation code.';
    }
    else
    {
        $db->where('user_id', $user->user_id)
            ->update(T_USERS, ['sms_code' => '', 'email_code' => '', 'active' => '1', ]);
        $time = time();
        $cookie = '';
        $access_token = sha1(rand(111111111, 999999999)) . md5(microtime()) . rand(11111111, 99999999) . md5(rand(5555, 9999));
        $timezone = 'UTC';
        $create_session = mysqli_query($sqlConnect, "INSERT INTO " . T_APP_SESSIONS . " (`user_id`, `session_id`, `platform`, `time`) VALUES ('{$user_id}', '{$access_token}', 'phone', '{$time}')");
        if (!empty($_POST['timezone']))
        {
            $timezone = Wo_Secure($_POST['timezone']);
        }
        $add_timezone = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `timezone` = '{$timezone}',`active` = '1' WHERE `user_id` = {$user_id}");
        // if (!empty($_POST['device_id'])) {
        //     $device_id = Wo_Secure($_POST['device_id']);
        //     $update    = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `device_id` = '{$device_id}' WHERE `user_id` = '{$user_id}'");
        // }
        if (!empty($_POST['android_m_device_id']))
        {
            $device_id = Wo_Secure($_POST['android_m_device_id']);
            $update = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `android_m_device_id` = '{$device_id}' WHERE `user_id` = '{$user_id}'");
        }
        if (!empty($_POST['ios_m_device_id']))
        {
            $device_id = Wo_Secure($_POST['ios_m_device_id']);
            $update = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `ios_m_device_id` = '{$device_id}' WHERE `user_id` = '{$user_id}'");
        }
        if (!empty($_POST['android_n_device_id']))
        {
            $device_id = Wo_Secure($_POST['android_n_device_id']);
            $update = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `android_n_device_id` = '{$device_id}' WHERE `user_id` = '{$user_id}'");
        }
        if (!empty($_POST['ios_n_device_id']))
        {
            $device_id = Wo_Secure($_POST['ios_n_device_id']);
            $update = mysqli_query($sqlConnect, "UPDATE " . T_USERS . " SET `ios_n_device_id` = '{$device_id}' WHERE `user_id` = '{$user_id}'");
        }
        if ($create_session)
        {
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

