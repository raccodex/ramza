<?php
if ($wo['config']['monetization'] == 0) {
    header("Location: " . $wo['config']['site_url']);
    exit();
}
$html = '';
if (!$wo['user'] || empty($wo['user'])) {
    header("Location: " . $wo['config']['site_url']);
    exit();
}

$wo['monetizations'] = [];
if (!isset($_GET['type']) ||  $_GET['type'] == 'monetizations') {
    $wo['subscribed_monetizations'] = $db
        ->where('user_id', $wo['user']['user_id'])
        ->where('status', 1, '=')
        ->get(T_MONETIZATION_SUBSCRIBTION);

    

    foreach ($wo['subscribed_monetizations'] as $subscribed_monetization) {
        $monetization = $db
            ->where('id', $subscribed_monetization->monetization_id)
            ->where('status', 1, '=')
            ->getOne(T_USER_MONETIZATION);

        if ($monetization) {
            $wo['monetizations'][$subscribed_monetization->id] = $monetization;
         }
    }
    $html = 'subscriptions/content';
}


$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['page'] = 'subscriptions';
$wo['title'] = $wo['lang']['subscriptions'];
$wo['content'] = Wo_LoadPage($html);

