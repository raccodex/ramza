<?php

if (!$wo['config']['directory_system'] || $wo['config']['events'] != 1) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$html = '<div class="wow_content"><div class="empty_state"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M15.5,12C18,12 20,14 20,16.5C20,17.38 19.75,18.21 19.31,18.9L22.39,22L21,23.39L17.88,20.32C17.19,20.75 16.37,21 15.5,21C13,21 11,19 11,16.5C11,14 13,12 15.5,12M15.5,14A2.5,2.5 0 0,0 13,16.5A2.5,2.5 0 0,0 15.5,19A2.5,2.5 0 0,0 18,16.5A2.5,2.5 0 0,0 15.5,14M7,15V17H9C9.14,18.55 9.8,19.94 10.81,21H5C3.89,21 3,20.1 3,19V5C3,3.89 3.89,3 5,3H19A2,2 0 0,1 21,5V13.03C19.85,11.21 17.82,10 15.5,10C14.23,10 13.04,10.37 12.04,11H7V13H10C9.64,13.6 9.34,14.28 9.17,15H7M17,9V7H7V9H17Z" /></svg> ' . $wo["lang"]["no_result"] . '</div></div>';
$pagination = '';

$wo['page_id'] = isset($_GET['page-id']) ? $_GET['page-id'] : 1;
$db->pageLimit = 20;

$wo['events']    = $db->objectbuilder()->orderBy('id', 'DESC')->paginate(T_EVENTS, $wo['page_id']);
$wo['totalPages'] = $db->totalPages;

if (count($wo['events']) != 0) {
    $pagination = loadHTMLPage('directory/includes/pagination',[
        'link' => 'directory/events',
        'ajax_link' => '?link1=directory-events',
    ]);

    $html = '';
    foreach ($wo['events'] as $wo['event']) {
        $wo['event'] = (array) $wo['event'];
        $wo['event'] = Wo_EventData($wo['event']['id']);

        $html .= Wo_LoadPage('events/includes/events-list');
    }
}

$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['page'] = 'events';
$wo['title'] = $wo['config']['siteTitle'];

$wo['content'] = loadHTMLPage('directory/events',[
    'html' => $html,
    'pagination' => $pagination,
    'sidebar' => Wo_LoadPage("directory/left-sidebar"),
]);
