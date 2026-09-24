<?php
if (empty($wo['config']['reels_upload'])) {
    header("Location: " . $wo['config']['site_url']);
    exit();
}
$wo['config']['second_post_button'] = 'disabled';
$wo['watched_reels'] = array();
$html = '';
$main_reel = 0;

$reelOwnerName = null;
$getPosts = false;
$postsData = array(
    'limit' => 4,
    'filter_by' => 'local_video',
    'order' => 'rand',
    'is_reel' => 'only'
);

if (!empty($_GET['user'])) {
    $reelOwnerName = Wo_Secure($_GET['user']);
    $reelOwnerId = Wo_UserIdFromUsername(Wo_Secure($_GET['user']));
    $postsData['publisher_id'] = $reelOwnerId;
    $getPosts = true;
}

if (empty($_GET['id'])) {
    $getPosts = true;
}

if (!empty($wo['watched_reels']) && empty($_GET['id'])) {
    $postsData['not_in'] = $wo['watched_reels'];
}

$reels = array();
$id = 0;

if (!empty($_GET['id'])) {
    $requestedId = (int) Wo_Secure($_GET['id']);
    $requestedReel = $requestedId > 0 ? Wo_PostData($requestedId) : array();
    if (!empty($requestedReel) && !empty($requestedReel['is_reel'])) {
        $reels[] = $requestedReel;
        $id = (int) $requestedReel['id'];
        setcookie('watched_reels', json_encode(array()), time() + (60 * 60 * 24), '/');
        $wo['watched_reels'] = array($id);
        $postsData['not_in'] = $wo['watched_reels'];
        $nextReels = Wo_GetPosts($postsData);
        if (!empty($nextReels)) {
            $reels = array_merge($reels, $nextReels);
        }
    } else {
        $getPosts = true;
    }
}

if ($getPosts && empty($reels)) {
    $reels = Wo_GetPosts($postsData);
    if (empty($reels)) {
        setcookie('watched_reels', json_encode(array()), time()+(60 * 60 * 24),'/');
        $wo['watched_reels'] = array();
        $postsData['not_in'] = $wo['watched_reels'];
        $reels = Wo_GetPosts($postsData);
    }
    if (!empty($reels)) {
        $id = (int) $reels[0]['id'];
    }
}

$wo['page_url'] = rtrim($wo['config']['site_url'], '/') . ($id > 0 ? "/reels/" . $id : '/reels');
$wo['reelOwnerName'] = $reelOwnerName;
$wo['page'] = 'reels';


foreach ($reels as $key => $wo['story']) {
    if (!in_array($wo['story']['id'], $wo['watched_reels'])) {
        $wo['watched_reels'][] = $wo['story']['id'];
    }
    $wo['story']['likeCount'] = Wo_CountLikes($wo['story']['id']);
    $wo['story']['commentCount'] = Wo_CountPostComment($wo['story']['id']);

    $media = array(
        'type' => 'post',
        'storyId' => $wo['story']['id'],
        'filename' => $wo['story']['postFile'],
        'name' => $wo['story']['postFileName'],
        'postFileThumb' => $wo['story']['postFileThumb'],
    );
    $video = Wo_DisplaySharedFile($media, '', $wo['story']['cache']);

    $html .= loadHTMLPage('reels/list',[
        'ID' => $wo['story']['id'],
        'URL' => $wo['config']['site_url']. "/reels/" . $wo['story']['id'],
        'CLASS' => ($main_reel == 0 ? '' : 'hidden'),
        'STORY_ARRAY' => $wo['story'],
        'PUBLISHER_ARRAY' => $wo['story']['publisher'],
        'VIDEO' => $video,
    ]);

    $main_reel = 1;
}

if ($html === '') {
    $html = '<section class="ramza-reels-empty" role="status"><div><strong>No reels yet</strong><span>Uploaded reels will appear here.</span></div></section>';
}

if (!empty($wo['watched_reels'])) {
    setcookie('watched_reels', json_encode($wo['watched_reels']), time()+(60 * 60 * 24),'/');
}


$wo['description'] = $wo['config']['siteDesc'];
$wo['keywords'] = $wo['config']['siteKeywords'];
$wo['title'] = $wo['lang']['reels'];
$wo['content'] = $html;
$wo['content'] = loadHTMLPage('reels/content',[
    'html' => $html,
    //'main_reel_html' => $main_reel_html,
]);
