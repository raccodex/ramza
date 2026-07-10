<?php

if (!$wo['config']['directory_system'] || $wo['config']['pages'] != 1) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['page'] = 'page';
$wo['title'] = $wo['config']['siteTitle'];

$wo['content'] = Wo_LoadPage('directory/pages');
