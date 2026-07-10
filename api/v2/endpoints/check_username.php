<?php

try {

	checkUsernameValidation();

	//if ($_POST['type'] == 'user' || $_POST['type'] == 'page' || $_POST['type'] == 'group') {
		if (in_array(true, Wo_IsNameExist(Wo_Secure($_POST['username']), 0))) {
			throw new Exception('Username is already taken');
		}
	//}

	$response_data = array(
        'api_status' => 200,
        'message' => 'Username is available'
    );

} catch (Exception $e) {
	$error_code    = 5;
	$error_message = $e->getMessage();
}