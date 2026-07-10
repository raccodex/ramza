<?php
if ($f == 'open_lightbox') {
    $redirect = null;
    $html = '';
    if (!empty($_GET['post_id'])) {
        $wo['story'] = Wo_PostData($_GET['post_id']);
        if (!empty($wo['story'])) {
            if ($wo['story']['postPrivacy'] == 6 && $wo['story']['publisher']['user_id'] !== $wo['user']['user_id']) {
                if (!Wo_IsSubscriptionPaidForPublisher($wo['story']['publisher']['user_id'])) {
                    setcookie("redirect_back_after_subscription", $_GET['post_id']);
                    $redirect = $wo['config']['site_url'] . '/monetization/' . $wo['story']['publisher']['username'];
                } else {
                    $html = Wo_LoadPage('lightbox/content');
                }
            } else {
                $html = Wo_LoadPage('lightbox/content');
            }
        }
    }
    $data = array(
        'status' => 200,
        'html' => $html,
        'redirect' => $redirect,
    );
    header("Content-type: application/json");
    echo json_encode($data);
    exit();
}
