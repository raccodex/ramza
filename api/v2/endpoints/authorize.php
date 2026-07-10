<?php
use net\authorize\api\contract\v1 as AnetAPI;
use net\authorize\api\controller as AnetController;

if ($_POST['type'] == 'pay')
{
    try
    {
        authorizePayValidation();

        require_once 'assets/libraries/authorize/vendor/autoload.php';
        $amount = Wo_Secure($_POST['amount']);
        $APILoginId = $wo['config']['authorize_login_id'];
        $APIKey = $wo['config']['authorize_transaction_key'];
        $refId = 'ref' . time();
        define("AUTHORIZE_MODE", $wo['config']['authorize_test_mode']);

        $merchantAuthentication = new AnetAPI\MerchantAuthenticationType();
        $merchantAuthentication->setName($APILoginId);
        $merchantAuthentication->setTransactionKey($APIKey);

        $creditCard = new AnetAPI\CreditCardType();
        $creditCard->setCardNumber($_POST['card_number']);
        $creditCard->setExpirationDate($_POST['card_year'] . "-" . $_POST['card_month']);
        $creditCard->setCardCode($_POST['card_cvc']);

        $paymentType = new AnetAPI\PaymentType();
        $paymentType->setCreditCard($creditCard);

        $transactionRequestType = new AnetAPI\TransactionRequestType();
        $transactionRequestType->setTransactionType("authCaptureTransaction");
        $transactionRequestType->setAmount($amount);
        $transactionRequestType->setPayment($paymentType);

        $request = new AnetAPI\CreateTransactionRequest();
        $request->setMerchantAuthentication($merchantAuthentication);
        $request->setRefId($refId);
        $request->setTransactionRequest($transactionRequestType);
        $controller = new AnetController\CreateTransactionController($request);
        if ($wo['config']['authorize_test_mode'] == 'SANDBOX')
        {
            $Aresponse = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::SANDBOX);
        }
        else
        {
            $Aresponse = $controller->executeWithApiResponse(\net\authorize\api\constants\ANetEnvironment::PRODUCTION);
        }

        if ($Aresponse != null)
        {
            if ($Aresponse->getMessages()
                ->getResultCode() == 'Ok')
            {
                $trans = $Aresponse->getTransactionResponse();
                if ($trans != null && $trans->getMessages() != null)
                {
                    if (Wo_ReplenishingUserBalance($amount))
                    {
                        $create_payment_log = mysqli_query($sqlConnect, "INSERT INTO " . T_PAYMENT_TRANSACTIONS . " (`userid`, `kind`, `amount`, `notes`) VALUES ('" . $wo['user']['id'] . "', 'WALLET', '" . $amount . "', 'authorize')");

                        $user = Wo_UserData($wo['user']['user_id']);

                        $response_data = array(
                            'api_status' => 200,
                            'url' => $url,
                            'wallet' => $user['wallet'],
                            'balance' => $user['balance'],
                        );
                    }
                }
                else
                {
                    $error_code = 6;
                    $error_message = 'something went wrong';
                }
            }
            else
            {
                $error_code = 6;
                $error_message = 'something went wrong';
            }
        }
        else
        {
            $error_code = 6;
            $error_message = 'something went wrong';
        }
    }
    catch(Exception $e)
    {
        $error_code = 5;
        $error_message = $e->getMessage();
    }
}

