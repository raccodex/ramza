<?php

if (!$wo['config']['directory_system']) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

if ($wo['config']['games'] != 1 || !$wo['config']['can_use_games']) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['page'] = 'games';
$wo['title'] = $wo['config']['siteTitle'];

$wo['content'] = Wo_LoadPage('directory/games');
