<?php
if ($_POST['type'] == 'checkout')
{
    try
    {
        if (!empty($_POST['amount']) && is_numeric($_POST['amount']) && $_POST['amount'] > 0)
        {
            $price = Wo_Secure($_POST['amount']);
            require_once 'assets/libraries/iyzipay/samples/config.php';
            $callback_url = $wo['config']['site_url'] . "/requests.php?f=iyzipay&s=success&amount=" . $price . '&user_id=' . $wo['user']['user_id'] . '&ConversationId=' . $ConversationId;
            $request->setPrice($price);
            $request->setPaidPrice($price);
            $request->setCallbackUrl($callback_url);

            $basketItems = array();
            $firstBasketItem = new \Iyzipay\Model\BasketItem();
            $firstBasketItem->setId("BI" . rand(11111111, 99999999));
            $firstBasketItem->setName('Top Up Wallet');
            $firstBasketItem->setCategory1('Top Up Wallet');
            $firstBasketItem->setItemType(\Iyzipay\Model\BasketItemType::PHYSICAL);
            $firstBasketItem->setPrice($price);
            $basketItems[0] = $firstBasketItem;
            $request->setBasketItems($basketItems);
            $checkoutFormInitialize = \Iyzipay\Model\CheckoutFormInitialize::create($request, IyzipayConfig::options());
            $content = $checkoutFormInitialize->getCheckoutFormContent();
            if (!empty($content))
            {
                $response_data = array(
                    'api_status' => 200,
                    'html' => $content
                );
            }
            else
            {
                throw new Exception('something went wrong');
            }
        }
        else
        {
            throw new Exception('amount can not be empty');
        }
    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}
elseif ($_POST['type'] == 'success')
{
    if (!empty($_POST['ConversationId']) && !empty($_POST['token']))
    {

        require_once 'assets/libraries/iyzipay/samples/config.php';
        # create request class
        $request = new \Iyzipay\Request\RetrieveCheckoutFormRequest();
        $request->setLocale(\Iyzipay\Model\Locale::TR);
        $request->setConversationId($_POST['ConversationId']);
        $request->setToken($_POST['token']);

        # make request
        $checkoutForm = \Iyzipay\Model\CheckoutForm::retrieve($request, IyzipayConfig::options());

        # print result
        if ($checkoutForm->getPaymentStatus() == 'SUCCESS')
        {
            $amount = Wo_Secure($_POST['amount']);
            $_POST['user_id'] = Wo_Secure($_POST['user_id']);
            $db->where('user_id', $_POST['user_id'])->update(T_USERS, array(
                'wallet' => $db->inc($amount)
            ));
            cache($_POST['user_id'], 'users', 'delete');
            $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $_POST['user_id'] . "', 'WALLET', '" . $amount . "', 'iyzipay')");

            $user = Wo_UserData($_POST['user_id']);

            $response_data = array(
                'api_status' => 200,
                'message' => 'payment successfully done',
                'wallet' => $user['wallet'],
                'balance' => $user['balance'],
            );
        }
        else
        {
            $error_code = 6;
            $error_message = 'something went wrong';
        }
    }
    else
    {
        $error_code = 5;
        $error_message = 'ConversationId , token can not be empty';
    }
}

