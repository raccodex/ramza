<?php
if ($_POST['type'] == 'add')
{
    try
    {

        addressAddValidation();

        $db->insert(T_USER_ADDRESS, array(
            'name' => Wo_Secure($_POST['name']) ,
            'phone' => Wo_Secure($_POST['phone']) ,
            'city' => Wo_Secure($_POST['city']) ,
            'zip' => Wo_Secure($_POST['zip']) ,
            'address' => Wo_Secure($_POST['address']) ,
            'user_id' => $wo['user']['user_id'],
            'time' => time() ,
            'country' => Wo_Secure($_POST['country'])
        ));
        $response_data = array(
            'api_status' => 200,
            'message' => 'address successfully added'
        );

    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}
elseif ($_POST['type'] == 'delete')
{
    try
    {

        addressDeleteValidation();

        $db->where('id', $wo['address']->id)
            ->delete(T_USER_ADDRESS);
        $response_data = array(
            'api_status' => 200,
            'message' => 'address successfully deleted'
        );

    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}
elseif ($_POST['type'] == 'edit')
{
    try
    {
        addressEditValidation();

        $db->where('id', $wo['address']->id)
            ->update(T_USER_ADDRESS, array(
            'name' => Wo_Secure($_POST['name']) ,
            'phone' => Wo_Secure($_POST['phone']) ,
            'city' => Wo_Secure($_POST['city']) ,
            'zip' => Wo_Secure($_POST['zip']) ,
            'address' => Wo_Secure($_POST['address']) ,
            'country' => Wo_Secure($_POST['country'])
        ));
        $response_data = array(
            'api_status' => 200,
            'message' => 'address successfully edited'
        );

    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}
elseif ($_POST['type'] == 'get')
{

    $offset = (!empty($_POST['offset']) && is_numeric($_POST['offset']) && $_POST['offset'] > 0 ? Wo_Secure($_POST['offset']) : 0);
    $limit = (!empty($_POST['limit']) && is_numeric($_POST['limit']) && $_POST['limit'] > 0 && $_POST['limit'] <= 50 ? Wo_Secure($_POST['limit']) : 20);

    if (!empty($offset))
    {
        $db->where('id', $offset, '<');
    }

    $wo['addresses'] = $db->where('user_id', $wo['user']['user_id'])->orderBy('id', 'DESC')
        ->get(T_USER_ADDRESS, $limit);

    $response_data = array(
        'api_status' => 200,
        'data' => $wo['addresses']
    );
}
elseif ($_POST['type'] == 'get_by_id')
{

    if (!empty($_POST['id']) && is_numeric($_POST['id']) && $_POST['id'] > 0)
    {
        $address = $db->where('user_id', $wo['user']['user_id'])->where('id', Wo_Secure($_POST['id']))->getOne(T_USER_ADDRESS);
        if (!empty($address))
        {
            $response_data = array(
                'api_status' => 200,
                'data' => $address
            );
        }
        else
        {
            $error_code = 6;
            $error_message = 'address not found';
        }
    }
    else
    {
        $error_code = 5;
        $error_message = 'id can not be empty';
    }
}

