<?php

if (!$wo['config']['directory_system'] || $wo['config']['classified'] != 1) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['page'] = 'market';
$wo['title'] = $wo['config']['siteTitle'];

$wo['content'] = Wo_LoadPage('directory/market');
