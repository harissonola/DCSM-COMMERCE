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

    public function __construct(HttpClientInterface $client)
    {
        $this->client = $client;
        // Récupération directe des variables d'environnement depuis .env
        $this->apiKey = $_ENV['COINBASE_API_KEY'] ?? '';
        $this->accountId = $_ENV['COINBASE_ACCOUNT_ID'] ?? '';

        if (empty($this->apiKey) || empty($this->accountId)) {
            throw new \RuntimeException('COINBASE_API_KEY et COINBASE_ACCOUNT_ID doivent être définis dans .env');
        }
    }

    /**
     * Envoie une transaction (payout) vers un portefeuille externe
     *
     * @param string $currency Code de la crypto-monnaie (ex: 'BTC', 'USDT')
     * @param float  $amount   Montant à envoyer
     * @param string $address  Adresse du portefeuille destinataire
     * @param string $idem     Identifiant idempotent unique (par ex. ID de transaction interne)
     *
     * @return array Données de la transaction Coinbase
     *
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