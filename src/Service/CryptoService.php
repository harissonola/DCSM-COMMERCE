<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

class CryptoService
{
    private HttpClientInterface $client;
    private string $apiKey;
    private string $merchantId;
    private const API_URL = 'https://www.coinpayments.net/api.php';
    private const API_VERSION = 1;

    public function __construct(HttpClientInterface $client, string $apiKey, string $merchantId)
    {
        $this->client = $client;
        $this->apiKey = $apiKey;
        $this->merchantId = $merchantId;
    }

    private function sendRequest(array $params): array
    {
        // Ajout des infos obligatoires à chaque requête
        $params = array_merge($params, [
            'version' => self::API_VERSION,
            'key' => $this->apiKey,
            'format' => 'json',
            'merchant' => $this->merchantId,
        ]);

        try {
            $response = $this->client->request('POST', self::API_URL, [
                'json' => $params, // Correction ici
            ]);

            $data = $response->toArray();

            // Vérification de la réponse de CoinPayments
            if (isset($data['error']) && $data['error'] !== 'ok') {
                return ['error' => $data['error']];
            }

            return $data;
        } catch (HttpExceptionInterface $e) {
            return ['error' => 'HTTP error: ' . $e->getMessage()];
        } catch (TransportExceptionInterface $e) {
            return ['error' => 'Network error: ' . $e->getMessage()];
        } catch (\Exception $e) {
            return ['error' => 'Error: ' . $e->getMessage()];
        }
    }

    public function createDepositAddress(string $currency = 'TRX'): array
    {
        return $this->sendRequest([
            'cmd' => 'get_callback_address',
            'currency' => $currency,
        ]);
    }

    public function verifyDeposit(string $txnId): array
    {
        return $this->sendRequest([
            'cmd' => 'get_tx_info',
            'txid' => $txnId,
        ]);
    }

    public function withdraw(float $amount, string $recipient, string $currency = 'TRX'): array
    {
        if ($amount <= 0) {
            return ['error' => 'Invalid amount. Must be greater than 0.'];
        }

        // Vérification de l'adresse (BTC, ETH, TRX, email pour PayPal)
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) &&
            !preg_match('/^(0x[a-fA-F0-9]{40}|[1-9A-HJ-NP-Za-km-z]{26,42})$/', $recipient)) {
            return ['error' => 'Invalid recipient address.'];
        }

        return $this->sendRequest([
            'cmd' => 'create_withdrawal',
            'amount' => $amount,
            'currency' => $currency,
            'address' => $recipient,
            'auto_confirm' => 1,
        ]);
    }
}