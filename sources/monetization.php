<?php
if ($wo['config']['monetization'] == 0) {
    header("Location: " . $wo['config']['site_url']);
    exit();
}

if (!$wo['user'] || empty($wo['user'])) {
    header("Location: " . $wo['config']['site_url']);
    exit();
}
$wo['monetizations_user'] = [];
if ($_GET['user']) {
    $user_id  = Wo_UserIdFromUsername($_GET['user']);
    $wo['monetizations_user'] = Wo_UserData($user_id);
    $wo['monetizations'] = $db->where('user_id',$user_id)
        ->where('status',1,'=')
        ->get(T_USER_MONETIZATION);

    foreach ($wo['monetizations'] as $monetization) {
        $subscribed_monetization = $db
            ->where('user_id',$wo['user']['user_id'])
            ->where('status',1,'=')
            ->where('monetization_id',$monetization->id)
            ->get(T_MONETIZATION_SUBSCRIBTION);

        if($subscribed_monetization) {
            header("Location: " . $wo['config']['site_url']);
            exit();
        }
    }

}


$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['page'] = 'monetization';
$wo['title'] = $wo['lang']['monetization'];
$wo['content'] = Wo_LoadPage('monetization/content');

