<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class CoinbasePayoutService
{
    private HttpClientInterface $client;
    private string $apiKey;
    private string $accountId;
    private string $apiUrl = 'https://api.coinbase.com/v2/accounts';

    public function __construct(HttpClientInterface $client, string $coinbaseApiKey, string $coinbaseAccountId)
    {
        $this->client = $client;
        $this->apiKey = $coinbaseApiKey;
        $this->accountId = $coinbaseAccountId;
    }

    /**
     * @throws TransportExceptionInterface
     * @throws ClientExceptionInterface
     * @throws RedirectionExceptionInterface
     * @throws ServerExceptionInterface
     */
    public function createPayout(string $currency, float $amount, string $address, string $idem): array
    {
        $url = sprintf('%s/%s/transactions', $this->apiUrl, $this->accountId);
        $response = $this->client->request('POST', $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'CB-VERSION'    => '2021-09-01',
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'type'     => 'send',
                'to'       => $address,
                'amount'   => number_format($amount, 8, '.', ''),
                'currency' => $currency,
                'idem'     => $idem,
            ],
        ]);

        $data = $response->toArray(true);
        return $data['data'];
    }
}