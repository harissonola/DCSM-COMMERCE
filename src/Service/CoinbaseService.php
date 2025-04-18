<?php
// src/Service/CoinbaseService.php
namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class CoinbaseService
{
    private $client;
    private $apiKey;
    private string $apiUrl = 'https://api.commerce.coinbase.com';

    public function __construct(HttpClientInterface $client, string $coinbaseApiKey)
    {
        $this->client = $client;
        $this->apiKey = $coinbaseApiKey;
    }

    public function createCharge(
        string $name,
        string $description,
        float $amount,
        string $currency = 'USD',
        string $redirectUrl = '',
        string $cancelUrl = '',
        string $customerEmail = null
    ): array {
        $payload = [
            'name' => $name,
            'description' => $description,
            'pricing_type' => 'fixed_price',
            'local_price' => [
                'amount' => $amount,
                'currency' => $currency
            ],
            'redirect_url' => $redirectUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'custom_id' => uniqid(),
            ],
        ];

        if ($customerEmail) {
            $payload['metadata']['customer_email'] = $customerEmail;
        }

        $response = $this->client->request('POST', $this->apiUrl . '/charges', [
            'headers' => [
                'X-CC-Api-Key' => $this->apiKey,
                'X-CC-Version' => '2018-03-22',
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        return $response->toArray();
    }
}