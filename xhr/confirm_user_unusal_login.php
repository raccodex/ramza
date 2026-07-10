<?php 
if ($f == 'confirm_user_unusal_login') { 
    if ($wo['config']['prevent_system'] == 1) {
        if (!WoCanLogin()) {
            header("Content-type: application/json");
            echo json_encode(array(
                'errors' => $error_icon . $wo['lang']['login_attempts']
            ));
            exit();
        }
    }
    if (!empty($_POST['confirm_code']) && !empty($_COOKIE['two_factor_username'])) {
        $confirm_code = $_POST['confirm_code'];
        if (empty($_POST['confirm_code'])) {
            $errors = $error_icon . $wo['lang']['please_check_details'];
        } else if (empty($_COOKIE['two_factor_username'])) {
            $errors = $error_icon . $wo['lang']['error_while_activating'];
        }
        $user = $db->where("username", Wo_Secure($_COOKIE['two_factor_username']))->getOne(T_USERS);
        if (empty($user)) {
            $errors = $error_icon . $wo['lang']['error_while_activating'];
        }
        $user_id = $user->user_id;

        $confirm_code = 0;
        if ($user->two_factor_method == 'google' || $user->two_factor_method == 'authy') {
            $codes = $db->where('user_id',$user_id)->getOne(T_BACKUP_CODES);
            if (!empty($codes) && !empty($codes->codes)) {
                $backupCodes = json_decode($codes->codes,true);
                if (in_array($_POST['confirm_code'], $backupCodes)) {
                    $key = array_search($_POST['confirm_code'], $backupCodes);
                    $backupCodes[$key] = rand(111111,999999);
                    $db->where('user_id',$user_id)->update(T_BACKUP_CODES,[
                        'codes' => json_encode($backupCodes)
                    ]);
                    $confirm_code = 1;
                }
            }
        }

        
        if ($user->two_factor_method == 'two_factor' && $user->email_code == md5($_POST['confirm_code'])) {
            $confirm_code = 1;
        }
        else if ($user->two_factor_method == 'google' && !empty($user->google_secret) && $confirm_code == 0) {
            require_once 'assets/libraries/google_auth/vendor/autoload.php';
            try {
                $google2fa = new \PragmaRX\Google2FA\Google2FA();
                if ($google2fa->verifyKey($user->google_secret, $_POST['confirm_code'])) {
                    $confirm_code = 1;
                }
            } catch (Exception $e) {
                $errors = $e->getMessage();
            }
        }
        else if ($user->two_factor_method == 'authy' && !empty($user->authy_id) && $confirm_code == 0 && verifyAuthy($_POST['confirm_code'],$user->authy_id)) {
            $confirm_code = 1;
        }

        if (empty($confirm_code)) {
            if ($wo['config']['prevent_system'] == 1) {
                WoAddBadLoginLog();
            }
            $errors = $error_icon . $wo['lang']['wrong_confirmation_code'];
        }

        if (empty($errors) && $confirm_code > 0) {
            unset($_SESSION['code_id']);
            if (!empty($_SESSION['last_login_data'])) {
                $update_user = $db->where('user_id', $user_id)->update(T_USERS, array('last_login_data' => json_encode($_SESSION['last_login_data'])));
            } else if (!empty(get_ip_address())) {
                $getIpInfo = fetchDataFromURL("http://ip-api.com/json/" .  get_ip_address());
                $getIpInfo = json_decode($getIpInfo, true);
                if ($getIpInfo['status'] == 'success' && !empty($getIpInfo['regionName']) && !empty($getIpInfo['countryCode']) && !empty($getIpInfo['timezone']) && !empty($getIpInfo['city'])) {
                    $update_user = $db->where('user_id', $user_id)->update(T_USERS, array('last_login_data' => json_encode($getIpInfo)));
                }
            }
            Wo_DeleteBadLogins();
            cache($user_id, 'users', 'delete');
            $session             = Wo_CreateLoginSession($user_id);
            $data                = array(
                'status' => 200
            );
            $_SESSION['user_id'] = $session;
            if (isset($_SESSION['last_login_data'])) {
                unset($_SESSION['last_login_data']);
            }
            setcookie("user_id", $session, time() + (10 * 365 * 24 * 60 * 60));
            if (!empty($_POST['last_url'])) {
                $data['location'] = $_POST['last_url'];
            } else {
                $data['location'] = $wo['config']['site_url'];
            }
            $user_data = Wo_UserData($user_id);
            if ($wo['config']['membership_system'] == 1 && $user_data['is_pro'] == 0) {
                $data['location'] = Wo_SeoLink('index.php?link1=go-pro');
            }
        }
    }
    header("Content-type: application/json");
    if (!empty($errors)) {
        echo json_encode(array(
            'errors' => $errors
        ));
    } else {
        echo json_encode($data);
    }
    exit();
}

