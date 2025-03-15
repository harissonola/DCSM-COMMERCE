<?php
namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class CoinPaymentsService
{
    private $publicKey;
    private $privateKey;
    private $ipnSecret;
    private $merchantId;

    public function __construct(ParameterBagInterface $params)
    {
        $this->publicKey = $params->get('COINPAYMENTS_PUBLIC_KEY');
        $this->privateKey = $params->get('COINPAYMENTS_PRIVATE_KEY');
        $this->ipnSecret = $params->get('COINPAYMENTS_IPN_SECRET');
        $this->merchantId = $params->get('COINPAYMENTS_MERCHANT_ID');
    }

    public function createTransaction($amount, $currency1, $currency2, $buyerEmail)
    {
        $fields = [
            'cmd' => 'create_transaction',
            'amount' => $amount,
            'currency1' => $currency1,
            'currency2' => $currency2,
            'buyer_email' => $buyerEmail,
            'ipn_url' => 'https://tonsite.com/ipn-coinpayments',
            'key' => $this->publicKey,
            'format' => 'json'
        ];

        return $this->apiRequest($fields);
    }

    private function apiRequest($fields)
    {
        $fields['version'] = 1;
        $fields['key'] = $this->publicKey;
        $fields['format'] = 'json';

        $postFields = http_build_query($fields, '', '&');

        $hmac = hash_hmac('sha512', $postFields, $this->privateKey);

        $ch = curl_init('https://www.coinpayments.net/api.php');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['HMAC: ' . $hmac]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);
    }
}