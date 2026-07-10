<?php

if (!$wo['config']['directory_system']) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

if ($wo['config']['movies'] != 1 || !$wo['config']['can_use_movies']) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['page'] = 'movies';
$wo['title'] = $wo['config']['siteTitle'];

$wo['content'] = Wo_LoadPage('directory/movies');
