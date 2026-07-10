<?php 
$site_url_login = $wo['config']['site_url'];
if(substr($site_url_login, -1) == '/') {
    $site_url_login = substr($site_url_login, 0, -1);
}
$callback = $site_url_login . '/login-with.php?provider=' . $provider;
if ($provider == 'Vkontakte') {
	$callback = $site_url_login . '/vkontakte_callback';
}
$LoginWithConfig = array(
    'callback' => $callback,

    'providers' => array(
        "Google" => array(
			"enabled" => true,
			"keys" => array("id" => $wo['config']['googleAppId'], "secret" => $wo['config']['googleAppKey']),
		),
		"Facebook" => array(
			"enabled" => true,
			"keys" => array("id" => $wo['config']['facebookAppId'], "secret" => $wo['config']['facebookAppKey']),
			"scope" => "email",
			"trustForwarded" => false
		),
		"Twitter" => array(
			"enabled" => true,
			"keys" => array("key" => $wo['config']['twitterAppId'], "secret" => $wo['config']['twitterAppKey']),
			"includeEmail" => true
		),
		"LinkedIn" => array(
			"enabled" => true,
			"keys" => array("key" => $wo['config']['linkedinAppId'], "secret" => $wo['config']['linkedinAppKey'])
		),
		"Vkontakte" => array(
			"enabled" => true,
			"keys" => array("id" => $wo['config']['VkontakteAppId'], "secret" => $wo['config']['VkontakteAppKey'])
		),
		"Instagram" => array(
			"enabled" => true,
			"keys" => array("id" => $wo['config']['instagramAppId'], "secret" => $wo['config']['instagramAppkey'])
		),
		"QQ" => array(
			"enabled" => true,
			"keys" => array("id" => $wo['config']['qqAppId'], "secret" => $wo['config']['qqAppkey'])
		),
		"WeChat" => array(
			"enabled" => true,
			"keys" => array("id" => $wo['config']['WeChatAppId'], "secret" => $wo['config']['WeChatAppkey'])
		),
		"Discord" => array(
			"enabled" => true,
			"keys" => array("id" => $wo['config']['DiscordAppId'], "secret" => $wo['config']['DiscordAppkey'])
		),
		"Mailru" => array(
			"enabled" => true,
			"keys" => array("id" => $wo['config']['MailruAppId'], "secret" => $wo['config']['MailruAppkey'])
		),
        "WordPress" => array(
            "enabled" => true,
            "keys" => array("id" => $wo['config']['WordPressAppId'], "secret" => $wo['config']['WordPressAppkey'])
        ),
    ),
);
?>