<?php

if (!$wo['config']['directory_system'] || $wo['config']['blogs'] != 1) {
    header("Location: " . Wo_SeoLink('index.php?link1=welcome'));
    exit();
}

$html = '<div class="col-md-12"><div class="wow_content"><div class="empty_state"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M20,11H4V8H20M20,15H13V13H20M20,19H13V17H20M11,19H4V13H11M20.33,4.67L18.67,3L17,4.67L15.33,3L13.67,4.67L12,3L10.33,4.67L8.67,3L7,4.67L5.33,3L3.67,4.67L2,3V19A2,2 0 0,0 4,21H20A2,2 0 0,0 22,19V3L20.33,4.67Z" /></svg>' . $wo['lang']['no_blogs_found'] . '</div></div></div>';

$pagination = '';

$wo['page_id'] = isset($_GET['page-id']) ? $_GET['page-id'] : 1;
$db->pageLimit = 20;
$wo['blogs'] = $db->objectbuilder()->orderBy('id', 'DESC')->paginate(T_BLOG, $wo['page_id']);
$wo['totalPages'] = $db->totalPages;

if (count($wo['blogs']) > 0) {
    $pagination = loadHTMLPage('directory/includes/pagination',[
        'link' => 'directory/blogs',
        'ajax_link' => '?link1=directory-blogs',
    ]);

    $html = '';
    foreach ($wo['blogs'] as $key => $wo['article']){
        $wo['article'] = (array) $wo['article'];
        $wo['article'] = Wo_GetArticle($wo['article']['id']);
        $html .= Wo_LoadPage('blog/includes/card-list');
    }
}



$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['page'] = 'blog';
$wo['title'] = $wo['config']['siteTitle'];

$wo['content'] = loadHTMLPage('directory/blogs',[
    'html' => $html,
    'pagination' => $pagination,
    'sidebar' => Wo_LoadPage("directory/left-sidebar"),
]);
