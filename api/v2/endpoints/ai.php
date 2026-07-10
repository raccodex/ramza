<?php
$response_data = array(
    'api_status' => 400
);

$required_fields = array(
    'openai_post',
    'generate_blog',
    'openai_image',
    'midjeourny_image',
    'convert_image',
    'midjeourny_check',
    'chat_suggestions',
);
if (!empty($_POST['type']) && in_array($_POST['type'], $required_fields))
{

    if ($_POST['type'] == 'openai_post')
    {

        try
        {

            aiOpenaiPostValidation();

            $data = getOpenAiText($_POST['text'], $_POST['count']);

            $response_data = array(
                'api_status' => 200,
                'data' => $data['output'],
                'credits' => $data['credits'],
            );

        }
        catch(Exception $e)
        {
            $error_code = 5;
            $error_message = $e->getMessage();
        }
    }
    else if ($_POST['type'] == 'generate_blog')
    {

        try
        {

            aiGenerateBlogValidation();

            $thumbnail = false;
            if (!empty($_POST['thumbnail']) && $_POST['thumbnail'] == 'on')
            {
                $thumbnail = true;
            }
            $data = getOpenAiBlog($_POST['text'], $_POST['count'], $thumbnail);

            $response_data = array(
                'api_status' => 200,
                'title' => $data['title'],
                'description' => $data['description'],
                'content' => $data['content'],
                'output' => $data['output'],
                'tags' => $data['tags'],
                'credits' => $data['credits'],
            );

        }
        catch(Exception $e)
        {
            $error_code = 5;
            $error_message = $e->getMessage();
        }
    }
    else if ($_POST['type'] == 'openai_image')
    {

        try
        {

            aiOpenaiImageValidation();

            $result = getOpenAiImage($_POST['text'], $_POST['size'], $_POST['num_outputs']);
            if (!empty($result['data']))
            {
                $urls = array_map(function ($img)
                {
                    return loadImageContent($img->url);
                }
                , $result['data']);
            }
            else
            {
                throw new Exception("something went wrong");
            }

            $response_data = array(
                'api_status' => 200,
                'output' => $urls,
                'credits' => $db->where('user_id', $wo['user']['id'])->getValue(T_USERS, 'credits') ,
            );

        }
        catch(Exception $e)
        {
            $error_code = 5;
            $error_message = $e->getMessage();
        }
    }
    else if ($_POST['type'] == 'midjeourny_image')
    {

        try
        {

            aiMidJeournyImageValidation();

            $size = $_POST['size'];
            if ($wo['config']['midjeourny_model'] != 'stability-ai-stable-diffusion')
            {
                $size = getAISize($_POST['size']);
            }

            $data = getMidJeournyImage($_POST['text'], $size, $_POST['num_outputs']);

            $response_data = array(
                'api_status' => 200,
                'id' => $data['id'],
                'status_text' => $data['status_text'],
            );

        }
        catch(Exception $e)
        {
            $error_code = 5;
            $error_message = $e->getMessage();
        }
    }
    else if ($_POST['type'] == 'convert_image')
    {

        try
        {

            aiMidJeournyConvertImageValidation();

            $type = 'avatar';
            if (!empty($_POST['convert_type']) && in_array($_POST['convert_type'], ['avatar', 'cover']))
            {
                $type = Wo_Secure($_POST['convert_type']);
            }
            $data = getMidJeournyUser($_POST['text'], $type);

            $response_data = array(
                'api_status' => 200,
                'id' => $data['id'],
                'status_text' => $data['status_text'],
            );

        }
        catch(Exception $e)
        {
            $error_code = 5;
            $error_message = $e->getMessage();
        }
    }
    else if ($_POST['type'] == 'midjeourny_check')
    {

        try
        {

            aiMidJeournyCheckValidation();

            $data = checkMidJeourny($_POST['id']);

            $response_data = array(
                'api_status' => 200,
                'id' => $data['id'],
                'status_text' => $data['status_text'],
            );

        }
        catch(Exception $e)
        {
            $error_code = 5;
            $error_message = $e->getMessage();
        }
    }
    elseif ($_POST['type'] == 'chat_suggestions')
    {
        try
        {

            aiOpenaiPostValidation();

            $data = getOpenAiText($_POST['text'], $_POST['count']);

            $response_data = array(
                'api_status' => 200,
                'data' => $data['output'],
                'credits' => $data['credits'],
            );

        }
        catch(Exception $e)
        {
            $error_code = 5;
            $error_message = $e->getMessage();
        }
    }
}
else
{
    $error_code = 4;
    $error_message = 'type can not be empty';
}

