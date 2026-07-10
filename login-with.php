<?php
// +------------------------------------------------------------------------+
// | @author RacCodex (RacCodex)
// | @author_url 1: http://www.ramza.com
// | @author_url 2: http://codecanyon.net/user/RacCodex
// | @author_email: ramzasocial@gmail.com   
// +------------------------------------------------------------------------+
// | ramza - The Ultimate Social Networking Platform
// | Copyright (c) 2017 ramza. All rights reserved.
// +------------------------------------------------------------------------+
require_once('assets/init.php');
decryptConfigData();
$provider = "";
$types = array(
    'Google',
    'Facebook',
    'Twitter',
    'LinkedIn',
    'Vkontakte',
    'Instagram',
    'QQ',
    'WeChat',
    'Discord',
    'Mailru',
    'OkRu',
    'TikTok',
    'WordPress'
);

if (!empty($_GET['state']) && $_GET['state'] == 'OkRu' && !empty($_GET['code'])) {
    $_GET['provider'] = 'OkRu';
}

if (isset($_GET['provider']) && in_array($_GET['provider'], $types)) {
    $provider = Wo_Secure($_GET['provider']);
}

if (!empty($provider)) {

    if (!empty($_COOKIE['provider'])) {
        $_COOKIE['provider'] = '';
        unset($_COOKIE['provider']);
        setcookie('provider', '', -1);
        setcookie('provider', '', -1, '/');
    }
    setcookie('provider', $provider, time() + (60 * 60), '/');
}
else if(!empty($_COOKIE['provider']) && in_array($_COOKIE['provider'], $types)){
    
    $provider = Wo_Secure($_COOKIE['provider']);
}
if (!empty($provider) && $provider != 'OkRu') {
    require_once('assets/libraries/social-login/config.php');
    require_once('assets/libraries/social-login/vendor/autoload.php');
}

else if($provider == 'OkRu'){
    if (empty($_GET['code'])) {
        header("Location: https://connect.ok.ru/oauth/authorize?client_id=".$wo['config']['OkAppId']."&scope=VALUABLE_ACCESS&response_type=code&redirect_uri=".$wo['config']['site_url']."/login-with.php&layout=w&state=OkRu");
        exit();
    }
    require_once('assets/libraries/odnoklassniki_sdk.php');
}

use Hybridauth\Hybridauth;
use Hybridauth\HttpClient;
if (isset($provider) && in_array($provider, $types)) {
    try {
        if ($provider == 'OkRu') {
            OdnoklassnikiSDK::SetOkInfo();
            if (!is_null(OdnoklassnikiSDK::getCode())){
                if(OdnoklassnikiSDK::changeCodeToToken(OdnoklassnikiSDK::getCode())){
                    $current_user = OdnoklassnikiSDK::makeRequest("users.getCurrentUser", null);
                    if (!empty($current_user)) {
                        $user_profile = ToObject($current_user);
                        $user_profile->identifier = $user_profile->uid;
                        $user_profile->lastName = $user_profile->last_name;
                        if (!empty($user_profile->pic_3)) {
                            $user_profile->photoURL = $user_profile->pic_3;
                        }
                        else if (!empty($user_profile->pic_2)) {
                            $user_profile->photoURL = $user_profile->pic_2;
                        }
                        else if (!empty($user_profile->pic_1)) {
                            $user_profile->photoURL = $user_profile->pic_1;
                        }
                    }
                    else{
                        echo " <b><a href='" . Wo_SeoLink('index.php?link1=welcome') . "'>Try again<a></b>";
                        exit();
                    }
                }
                else{
                    echo " <b><a href='" . Wo_SeoLink('index.php?link1=welcome') . "'>Try again<a></b>";
                    exit();
                }
            }
            else{
                echo " <b><a href='" . Wo_SeoLink('index.php?link1=welcome') . "'>Try again<a></b>";
                exit();
            }
        }
        else if ($provider == 'TikTok') {
            require_once('./assets/libraries/tiktok/src/Connector.php');
            $callback = $site_url_login . '/tiktok_callback';
            $_TK = new Connector($wo['config']['tiktok_client_key'], $wo['config']['tiktok_client_secret'], $callback);
            if (Connector::receivingResponse()) { 
                try {
                    $token = $_TK->verifyCode($_GET[Connector::CODE_PARAM]);
                    // Your logic to store the access token
                    $user_profile = $_TK->getUser();
                    $user_profile->identifier = $user_profile->union_id;
                    $user_profile->displayName = $user_profile->display_name;
                    $user_profile->firstName = $user_profile->display_name;
                    $user_profile->email = '';
                    $user_profile->profileURL = '';
                    $user_profile->lastName = '';
                    $user_profile->photoURL = $user_profile->avatar_larger;
                    $user_profile->description = '';
                    $user_profile->gender = '';
                    // Your logic to manage the User info
                    //$videos = $_TK->getUserVideoPages();
                    // Your logic to manage the Video info
                } catch (Exception $e) {
                    echo "Error: ".$e->getMessage();
                    echo '<br /><a href="'.$_TK->getRedirect().'">Retry</a>';
                    exit();
                }
            } else {
                header("Location: " . $_TK->getRedirect());
                exit();
            }
        }
        else{
            $hybridauth = new Hybridauth( $LoginWithConfig );


            $authProvider = $hybridauth->authenticate($provider);
            $tokens = $authProvider->getAccessToken();
            $user_profile = $authProvider->getUserProfile();


        }

        if ($user_profile && isset($user_profile->identifier)) {
            $name = $user_profile->firstName;
            if ($provider == 'Google') {
                $notfound_email     = 'go_';
                $notfound_email_com = '@google.com';
            } else if ($provider == 'Facebook') {
                $notfound_email     = 'fa_';
                $notfound_email_com = '@facebook.com';
            } else if ($provider == 'Twitter') {
                $notfound_email     = 'tw_';
                $notfound_email_com = '@twitter.com';
            } else if ($provider == 'LinkedIn') {
                $notfound_email     = 'li_';
                $notfound_email_com = '@linkedIn.com';
            } else if ($provider == 'Vkontakte') {
                $notfound_email     = 'vk_';
                $notfound_email_com = '@vk.com';
            } else if ($provider == 'Instagram') {
                $notfound_email     = 'in_';
                $notfound_email_com = '@instagram.com';
                $name = $user_profile->displayName;
            } else if ($provider == 'QQ') {
                $notfound_email     = 'qq_';
                $notfound_email_com = '@qq.com';
                $name = $user_profile->displayName;
            } else if ($provider == 'WeChat') {
                $notfound_email     = 'wechat_';
                $notfound_email_com = '@wechat.com';
                $name = $user_profile->displayName;
            } else if ($provider == 'Discord') {
                $notfound_email     = 'discord_';
                $notfound_email_com = '@discord.com';
                $name = $user_profile->displayName;
            } else if ($provider == 'Mailru') {
                $notfound_email     = 'mailru_';
                $notfound_email_com = '@mailru.com';
                $name = $user_profile->displayName;
            } else if ($provider == 'OkRu') {
                $notfound_email     = 'okru_';
                $notfound_email_com = '@okru.com';
                $name = $user_profile->first_name;
            }
            $user_name  = $notfound_email . $user_profile->identifier;
            $user_email = $user_name . $notfound_email_com;
            if (!empty($user_profile->email)) {
                $user_email = $user_profile->email;
                if(empty($user_profile->emailVerified) && $provider == 'Discord') {
                    exit("Your E-mail is not verfied on Discord.");
                }
            }
            if (Wo_IsBanned($user_email)) {
                exit($wo['lang']['email_is_banned']);
            }
            if (Wo_EmailExists($user_email) === true) {
                Wo_SetLoginWithSession($user_email);
                header("Location: " . $config['site_url']);
                exit();
            } else {
                $str          = md5(microtime());
                $id           = substr($str, 0, 9);
                $user_uniq_id = (Wo_UserExists($id) === false) ? $id : 'u_' . $id;
                $social_url   = substr($user_profile->profileURL, strrpos($user_profile->profileURL, '/') + 1);
                $imported_image = Wo_ImportImageFromLogin($user_profile->photoURL, 1);
                if (empty($imported_image)) {
                    $imported_image = $wo['userDefaultAvatar'];
                }
                $password = rand(1111, 9999);
                $re_data      = array(
                    'username' => Wo_Secure($user_uniq_id, 0),
                    'email' => Wo_Secure($user_email, 0),
                    'password' => Wo_Secure(md5($password), 0),
                    'email_code' => Wo_Secure(md5(rand(1111, 9999) . time()), 0),
                    'first_name' => Wo_Secure($name),
                    'last_name' => Wo_Secure($user_profile->lastName),
                    'avatar' => Wo_Secure($imported_image),
                    'src' => Wo_Secure($provider),
                    'startup_image' => 1,
                    'lastseen' => time(),
                    'social_login' => 1,
                    'active' => '1'
                );
                if ($provider == 'Google') {
                    $re_data['about']  = Wo_Secure($user_profile->description);
                    $re_data['google'] = Wo_Secure($social_url);
                }
                if ($provider == 'Facebook') {
                    $fa_social_url       = @explode('/', $user_profile->profileURL);
                    if (!empty($fa_social_url[4])) {
                        $re_data['facebook'] = Wo_Secure($fa_social_url[4]);
                    }
                    $re_data['gender'] = 'male';
                    if (!empty($user_profile->gender)) {
                        if ($user_profile->gender == 'male') {
                            $re_data['gender'] = 'male';
                        } else if ($user_profile->gender == 'female') {
                            $re_data['gender'] = 'female';
                        }
                    }
                }
                if ($provider == 'Twitter') {
                    $re_data['twitter'] = Wo_Secure($social_url);
                }
                if ($provider == 'LinkedIn') {
                    $re_data['about']    = Wo_Secure($user_profile->description);
                    $re_data['linkedIn'] = Wo_Secure($social_url);
                }
                if ($provider == 'Vkontakte') {
                    $re_data['about'] = Wo_Secure($user_profile->description);
                    $re_data['vk']    = Wo_Secure($social_url);
                }
                if ($provider == 'Instagram') {
                    $re_data['instagram']   = Wo_Secure($user_profile->username);
                }
                if ($provider == 'QQ') {
                    $re_data['qq']   = Wo_Secure($social_url);
                }
                if ($provider == 'WeChat') {
                    $re_data['wechat']   = Wo_Secure($social_url);
                }
                if ($provider == 'Discord') {
                    $re_data['discord']   = Wo_Secure($social_url);
                }
                if ($provider == 'Mailru') {
                    $re_data['mailru']   = Wo_Secure($social_url);
                }
                if ($provider == 'OkRu') {
                    $re_data['okru']   = Wo_Secure($user_profile->uid);
                }
                if (!empty($_SESSION['ref']) && $wo['config']['affiliate_type'] == 0) {
                    $ref_user_id = Wo_UserIdFromUsername($_SESSION['ref']);
                    if (!empty($ref_user_id) && is_numeric($ref_user_id)) {
                        $re_data['referrer'] = Wo_Secure($ref_user_id);
                        $re_data['src']      = Wo_Secure('Referrer');
                        if ($wo['config']['affiliate_level'] < 2) {
                            $update_balance      = Wo_UpdateBalance($ref_user_id, $wo['config']['amount_ref']);
                        }
                        unset($_SESSION['ref']);
                    }
                }
                $wo['config']['user_registration'] = 1;
                if (preg_match_all('~@(.*?)(.*)~', $user_email, $matches) && !empty($matches[2]) && !empty($matches[2][0]) && Wo_IsBanned($matches[2][0])) {
                    die($wo['lang']['email_provider_banned']);
                }
                if (Wo_RegisterUser($re_data) === true) {
                                        Wo_SetLoginWithSession($user_email);
                    $user_id = Wo_UserIdFromEmail($user_email);
                    if (!empty($re_data['referrer']) && is_numeric($wo['config']['affiliate_level']) && $wo['config']['affiliate_level'] > 1) {
                        AddNewRef($re_data['referrer'],$user_id,$wo['config']['amount_ref']);
                    }
                    if (!empty($wo['config']['auto_friend_users'])) {
                        $autoFollow = Wo_AutoFollow($user_id);
                    }
                    if (!empty($wo['config']['auto_page_like'])) {
                        Wo_AutoPageLike($user_id);
                    }
                    if (!empty($wo['config']['auto_group_join'])) {
                        Wo_AutoGroupJoin($user_id);
                    }
                    if (!empty($user_profile->photoURL) && $imported_image != $wo['userDefaultAvatar'] && $imported_image != $wo['userDefaultFAvatar']) {
                        $explode2  = @end(explode('.', $imported_image));
                        $explode3  = @explode('.', $imported_image);
                        $last_file = $explode3[0] . '_full.' . $explode2;
                        $compress = Wo_CompressImage($imported_image, $last_file, $wo['config']['images_quality']);
                        if ($compress) {
                            $upload_s3 = Wo_UploadToS3($last_file);
                            $query = mysqli_query($sqlConnect, "INSERT INTO " . T_POSTS . " (`user_id`, `postFile`, `time`, `postType`, `postPrivacy`) VALUES ('$user_id', '" . Wo_Secure($last_file) . "', '" . Wo_Secure(time()) . "', 'profile_picture_deleted', '0')");
                            $sql_id = mysqli_insert_id($sqlConnect);
                            $sql_id = Wo_Secure($sql_id);
                            $update_query = mysqli_query($sqlConnect, "UPDATE " . T_POSTS . " SET `post_id` = '$sql_id' WHERE `id` = '$sql_id'");
                            Wo_Resize_Crop_Image($wo['profile_picture_width_crop'], $wo['profile_picture_height_crop'], $imported_image, $imported_image, $wo['profile_picture_image_quality']);
                            $upload_s3 = Wo_UploadToS3($imported_image);
                        }
                    }
                    $wo['user'] = $re_data;
                    $wo['pass'] = $password;
                    $body       = Wo_LoadPage('emails/login-with');
                    $headers    = "From: " . $config['siteName'] . " <" . $config['siteEmail'] . ">\r\n";
                    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                    @mail($re_data['email'], 'Thank you for your registration.', $body, $headers);
                    header("Location: " . Wo_SeoLink('index.php?link1=start-up'));
                    exit();
                }
            }
        }
    }
    catch (Exception $e) {
        echo $e->getMessage();
        echo " <b><a href='" . Wo_SeoLink('index.php?link1=welcome') . "'>Try again<a></b>";
    }
} else {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}