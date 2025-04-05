<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Transactions;
use App\Entity\User;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;

class PaymentController extends AbstractController
{
    #[Route('/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amountUsd = (float)$request->request->get('amount');
        $currency = strtoupper(trim($request->request->get('currency')));
        $address = trim($request->request->get('recipient'));

        // Validation de base
        if ($amountUsd <= 0 || $amountUsd > $user->getBalance()) {
            $this->addFlash('danger', 'Montant invalide ou solde insuffisant');
            return $this->redirectToRoute('app_profile');
        }
        if (empty($address)) {
            $this->addFlash('danger', 'Adresse de portefeuille requise');
            return $this->redirectToRoute('app_profile');
        }

        // Création de la transaction en statut "pending"
        $transaction = (new Transactions())
            ->setUser($user)
            ->setType('withdrawal')
            ->setAmount($amountUsd)
            ->setMethod("Crypto ($currency)")
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($transaction);
        $em->flush();

        try {
            // Envoi de la demande de retrait à CoinPayments
            $params = [
                'amount'    => $amountUsd,       // Montant en USD
                'currency'  => $currency,        // Devise cible (crypto)
                'currency2' => 'USD',            // Devise source
                'address'   => $address,
                'auto_confirm' => 1,
                'ipn_url'   => $this->generateUrl('coinpayments_withdrawal_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'custom'    => $transaction->getId()
            ];
            $response = $this->coinPaymentsApiCall('create_withdrawal', $params);
            if ($response['error'] !== 'ok') {
                throw new \Exception($response['error'] ?? 'Erreur inconnue de CoinPayments');
            }
            $this->addFlash('success', sprintf(
                'Demande de retrait de %.2f USD envoyée. La conversion en %s sera gérée par CoinPayments.',
                $amountUsd,
                $currency
            ));
        } catch (\Exception $e) {
            $transaction->setStatus('failed');
            $em->flush();
            $this->addFlash('danger', "Échec de la demande de retrait : " . $e->getMessage());
        }
        return $this->redirectToRoute('app_profile');
    }

    // --- Dépôts ---
    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }
        $amount = (float)$request->request->get('amount');
        $paymentMethod = $request->request->get('paymentMethod');
        $cryptoType = strtoupper(trim($request->request->get('cryptoType')));

        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }
        switch ($paymentMethod) {
            case 'paypal':
                return $this->redirectToRoute('app_paypal_redirect', ['amount' => $amount]);
            case 'crypto':
                // Redirection vers la route dédiée pour le dépôt en crypto
                return $this->redirectToRoute('app_crypto_redirect', ['amount' => $amount]);
            default:
                $this->addFlash('danger', 'Méthode de paiement invalide.');
                return $this->redirectToRoute('app_profile');
        }
    }

    /**
     * Route de dépôt en crypto.
     * - En GET : Affiche le formulaire (template crypto_deposit.html.twig) avec le montant.
     * - En POST : Traite le formulaire, crée une transaction en statut "pending"
     *           et redirige l'utilisateur vers l'URL de paiement renvoyée par CoinPayments.
     */
    #[Route('/deposit/redirect', name: 'app_crypto_redirect', methods: ['GET', 'POST'])]
    public function cryptoRedirect(Request $request, EntityManagerInterface $em): Response
    {
        // Récupération du montant depuis la query string
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }
        // Si la méthode est GET, afficher le formulaire
        if ($request->isMethod('GET')) {
            return $this->render('payment/crypto_deposit.html.twig', ['amount' => $amount]);
        }
        // En POST, traiter le formulaire
        $cryptoType    = strtoupper(trim($request->request->get('cryptoType')));
        $walletAddress = trim($request->request->get('walletAddress'));
        if (!$cryptoType || !$walletAddress) {
            $this->addFlash('danger', 'Le type de crypto et l’adresse du portefeuille sont requis.');
            return $this->redirectToRoute('app_profile');
        }
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }
        // Création de la transaction en statut "pending"
        $transaction = new Transactions();
        $transaction->setUser($user)
            ->setAmount($amount)
            ->setMethod("crypto_deposit ($cryptoType)")
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($transaction);
        $em->flush();

        // Préparation des paramètres pour CoinPayments
        $params = [
            'amount'      => $amount,
            'currency1'   => 'USD', // Devise de l'utilisateur
            'currency2'   => $cryptoType, // Crypto sélectionnée
            'buyer_email' => $user->getEmail(),
            'item_name'   => 'Dépôt sur ' . $this->getParameter('app.site_name'),
            'ipn_url'     => $this->generateUrl('coinpayments_deposit_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'success_url' => $this->generateUrl('app_profile', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'cancel_url'  => $this->generateUrl('app_profile', [], UrlGeneratorInterface::ABSOLUTE_URL),
            'custom'      => $transaction->getId(),
            // 'address' => $walletAddress, // À activer si CoinPayments requiert l'adresse
        ];
        try {
            $response = $this->coinPaymentsApiCall('create_transaction', $params);
            if ($response['error'] !== 'ok') {
                throw new \Exception($response['error'] ?? 'Erreur inconnue de CoinPayments');
            }
            return $this->redirect($response['result']['checkout_url']);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur CoinPayments: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/coinpayments/deposit-ipn', name: 'coinpayments_deposit_ipn', methods: ['POST'])]
    public function coinpaymentsDepositIpn(Request $request, EntityManagerInterface $em): Response
    {
        

        $data = json_decode($request->getContent(), true);
        if ($data === null) {
            return new Response('Données invalides', 400);
        }

        $transactionId = $data['custom'] ?? null;
        $status = (int)($data['status'] ?? 0);

        if (!$transactionId) {
            return new Response('Données manquantes', 400);
        }

        $transaction = $em->getRepository(Transactions::class)->find($transactionId);
        if (!$transaction) {
            return new Response('Transaction non trouvée', 404);
        }

        $user = $transaction->getUser();

        // Si le paiement est complété (status >= 100 pour CoinPayments)
        if ($status >= 100) {
            $transaction->setStatus('completed');
            // Crédit du compte utilisateur
            $user->setBalance($user->getBalance() + $transaction->getAmount()); // ✅ Add this line
            $em->flush();
        } elseif ($status < 0) {
            $transaction->setStatus('failed');
            $em->flush();
        }

        return new Response('OK');
    }

    #[Route('/paypal/redirect', name: 'app_paypal_redirect')]
    public function paypalRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Montant invalide');
            return $this->redirectToRoute('app_profile');
        }
        $client = $this->getPayPalClient();
        $paypalRequest = new OrdersCreateRequest();
        $paypalRequest->prefer('return=representation');
        $paypalRequest->body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => 'USD',
                    'value' => number_format($amount, 2, '.', '')
                ]
            ]],
            'application_context' => [
                'return_url' => $this->generateUrl('paypal_return', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'cancel_url' => $this->generateUrl('paypal_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'brand_name' => $this->getParameter('app.site_name'),
                'user_action' => 'PAY_NOW',
            ]
        ];
        try {
            $response = $client->execute($paypalRequest);
            $approveUrl = null;
            foreach ($response->result->links as $link) {
                if ($link->rel === 'approve') {
                    $approveUrl = $link->href;
                    break;
                }
            }
            if (!$approveUrl) {
                throw new \Exception('URL PayPal introuvable');
            }
            $request->getSession()->set('paypal_order_id', $response->result->id);
            $request->getSession()->set('paypal_amount', $amount);
            return $this->redirect($approveUrl);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur PayPal: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/return', name: 'paypal_return')]
    public function paypalReturn(Request $request, EntityManagerInterface $em): Response
    {
        $orderId = $request->query->get('token') ?? $request->getSession()->get('paypal_order_id');
        $amount = $request->getSession()->get('paypal_amount');
        if (!$orderId || !$amount) {
            $this->addFlash('danger', 'Commande PayPal introuvable');
            return $this->redirectToRoute('app_profile');
        }
        $user = $this->getUser();
        $client = $this->getPayPalClient();
        $captureRequest = new OrdersCaptureRequest($orderId);
        try {
            $response = $client->execute($captureRequest);
            if ($response->result->status !== 'COMPLETED') {
                throw new \Exception('Paiement non complété');
            }
            $transaction = (new Transactions())
                ->setUser($user)
                ->setAmount($amount)
                ->setMethod('paypal')
                ->setStatus('completed')
                ->setExternalId($orderId)
                ->setCreatedAt(new \DateTimeImmutable());
            $user->setBalance($user->getBalance() + $amount);
            $em->persist($transaction);
            $em->persist($user);
            $em->flush();
            $request->getSession()->remove('paypal_order_id');
            $request->getSession()->remove('paypal_amount');
            $this->addFlash('success', sprintf('Dépôt de %.2f USD effectué', $amount));
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur: ' . $e->getMessage());
        }
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/paypal/cancel', name: 'paypal_cancel')]
    public function paypalCancel(Request $request): Response
    {
        $request->getSession()->remove('paypal_order_id');
        $request->getSession()->remove('paypal_amount');
        $this->addFlash('warning', 'Paiement annulé');
        return $this->redirectToRoute('app_profile');
    }

    private function validateCoinpaymentsIpn(Request $request): bool
    {
        $hmacHeader = $request->headers->get('HMAC');
        $hmacCalculated = hash_hmac('sha512', $request->getContent(), $_ENV['COINPAYMENTS_API_SECRET']);
        return $hmacHeader === $hmacCalculated;
    }

    private function coinPaymentsApiCall(string $cmd, array $params = []): array
    {
        $privateKey = $_ENV['COINPAYMENTS_API_SECRET'];
        $publicKey  = $_ENV['COINPAYMENTS_API_KEY'];
        $params += [
            'version' => 1,
            'cmd'     => $cmd,
            'key'     => $publicKey,
            'format'  => 'json'
        ];
        $postData = http_build_query($params, '', '&');
        $hmac = hash_hmac('sha512', $postData, $privateKey);
        $ch = curl_init('https://www.coinpayments.net/api.php');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => ["hmac: $hmac"],
            CURLOPT_POSTFIELDS     => $postData
        ]);
        $data = curl_exec($ch);
        if ($data === false) {
            throw new \Exception("Erreur cURL: " . curl_error($ch));
        }
        $result = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Réponse API invalide");
        }
        if (!isset($result['error']) || $result['error'] !== 'ok') {
            throw new \Exception($result['error'] ?? 'Erreur inconnue');
        }
        return $result;
    }

    private function getPayPalClient(): PayPalHttpClient
    {
        $clientId = $_ENV['PAYPAL_CLIENT_ID'];
        $clientSecret = $_ENV['PAYPAL_CLIENT_SECRET'];
        $environment = $_ENV['APP_ENV'] === 'prod'
            ? new ProductionEnvironment($clientId, $clientSecret)
            : new SandboxEnvironment($clientId, $clientSecret);
        return new PayPalHttpClient($environment);
    }

    // --- Méthodes de paiement non implémentées ---
    private function processCardPayment(User $user, float $amount): bool
    {
        return false;
    }
    private function processMobileMoney(User $user, float $amount): bool
    {
        return false;
    }
}
