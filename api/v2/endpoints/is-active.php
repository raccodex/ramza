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
$response_data   = array(
    'api_status' => 400
);


if (empty($_POST['user_id'])) {
    $error_code    = 3;
    $error_message = 'user_id (POST) is missing';
}
else{

	$user_id = Wo_Secure($_POST['user_id']);
	$user = Wo_UserData($user_id);
	if (!empty($user)) {
		if ($user['active'] == 1) {
			$response_data = array(
                        'api_status' => 200,
                        'message' => 'The user is active.'
                    );
		}
		else{
			$error_code    = 5;
		    $error_message = 'The user not active';
		}
	}
	else{
		$error_code    = 4;
	    $error_message = 'User not found';
	}
}