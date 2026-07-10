<?php
$response_data = array(
    'api_status' => 400
);

try
{

    admobAddValidation();

    $a = Wo_RegisterPoint(1, 'admob', '+', $wo['ad_user']->user_id);

    $response_data = array(
        'api_status' => 200,
        'message' => 'Point added successfully'
    );

}
catch(Exception $e)
{
    $error_code = 5;
    $error_message = $e->getMessage();
}

