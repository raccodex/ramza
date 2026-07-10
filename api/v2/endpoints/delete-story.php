<?php
// +------------------------------------------------------------------------+
// | @author RacCodex (RacCodex)
// | @author_url 1: http://www.ramza.com
// | @author_url 2: http://codecanyon.net/user/RacCodex
// | @author_email: ramzasocial@gmail.com
// +------------------------------------------------------------------------+
// | ramza - The Ultimate Social Networking Platform
// | Copyright (c) 2018 ramza. All rights reserved.
// +------------------------------------------------------------------------+
$response_data = array(
    'api_status' => 400
);
if (empty($_POST['story_id'])) {
    $error_code    = 4;
    $error_message = 'story_id (POST) is missing';
}
if (empty($error_code)) {
    $story_id         = Wo_Secure($_POST['story_id']);
    $delete     = Wo_DeleteStatus($story_id);
    if ($delete) {
        $response_data = array(
            'api_status' => 200,
            'message' => "Story #$story_id successfully deleted."
        );
    }
}