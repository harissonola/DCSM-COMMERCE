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

// PayPal SDK imports using the newer version
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;

class PaymentController extends AbstractController
{
    #[Route('/withdraw', name: 'app_withdraw')]
    public function withdraw(): Response
    {
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }
        // Logique de retrait...
        dd('withdraw');
    }

    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request, EntityManagerInterface $em): Response
    {
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amount = (float) $request->request->get('amount');
        $paymentMethod = $request->request->get('paymentMethod');

        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }

        switch ($paymentMethod) {
            case 'carte':
                if (!$this->processCardPayment($user, $amount)) {
                    $this->addFlash('danger', 'Erreur de paiement par carte.');
                    return $this->redirectToRoute('app_profile');
                }
                break;
            case 'mobilemoney':
                if (!$this->processMobileMoney($user, $amount)) {
                    $this->addFlash('danger', 'Erreur avec Mobile Money.');
                    return $this->redirectToRoute('app_profile');
                }
                break;
            case 'paypal':
                return $this->redirectToRoute('app_paypal_redirect', ['amount' => $amount]);
            case 'crypto':
                // Rediriger vers la page de saisie pour le dépôt crypto
                return $this->redirectToRoute('app_crypto_redirect', ['amount' => $amount]);
            default:
                $this->addFlash('danger', 'Méthode de paiement invalide.');
                return $this->redirectToRoute('app_profile');
        }

        $this->addFlash('info', 'Traitement du dépôt en cours.');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/crypto/redirect', name: 'app_crypto_redirect', methods: ['GET', 'POST'])]
    public function cryptoRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }

        if ($request->isMethod('POST')) {
            $cryptoType = $request->request->get('cryptoType');
            $walletAddress = $request->request->get('walletAddress');

            if (!$cryptoType || !$walletAddress) {
                $this->addFlash('danger', 'Le type de crypto et l\'adresse du portefeuille sont requis.');
                return $this->redirectToRoute('app_profile');
            }

            // Récupérer l'utilisateur connecté
            $user = $this->getUser();
            if (!$user) {
                return $this->redirectToRoute('app_login');
            }

            // Préparation des paramètres pour l'appel à CoinPayments
            $params = [
                'amount'      => $amount,
                'currency1'   => 'USDT',  // devise d'origine
                'currency2'   => $cryptoType, // crypto choisie par l'utilisateur
                'buyer_email' => $user->getEmail(),
                'item_name'   => 'Dépôt sur le site',
                'ipn_url'     => $this->generateUrl('coinpayments_ipn', [], UrlGeneratorInterface::ABSOLUTE_URL)
            ];

            try {
                $response = $this->coinPaymentsApiCall('create_transaction', $params);
                $paymentUrl = $response['result']['checkout_url'];

                // Rediriger l'utilisateur vers la page de paiement CoinPayments
                return $this->redirect($paymentUrl);
            } catch (\Exception $e) {
                $this->addFlash('danger', 'Erreur CoinPayments: ' . $e->getMessage());
                return $this->redirectToRoute('app_profile');
            }
        }

        // Si méthode GET, afficher le formulaire de dépôt crypto
        return $this->render('payment/crypto_deposit.html.twig', [
            'amount' => $amount,
        ]);
    }

    #[Route('/paypal/redirect', name: 'app_paypal_redirect', methods: ['GET'])]
    public function paypalRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }

        $returnUrl = $this->generateUrl('paypal_return', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl = $this->generateUrl('paypal_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $client = $this->getPayPalClient();
        
        try {
            $paypalRequest = new OrdersCreateRequest();
            $paypalRequest->prefer('return=representation');
            
            $paypalRequest->body = [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => 'USD',
                        'value' => number_format($amount, 2, '.', '')
                    ],
                    'description' => 'Dépôt sur le site'
                ]],
                'application_context' => [
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                    'brand_name' => $this->getParameter('app.site_name') ?? 'Votre Site',
                    'user_action' => 'PAY_NOW',
                ]
            ];
            
            $response = $client->execute($paypalRequest);
            
            if ($response->statusCode !== 201) {
                throw new \Exception('Échec de la création de l\'ordre PayPal: ' . $response->statusCode);
            }
            
            $request->getSession()->set('paypal_order_id', $response->result->id);
            
            $approvalLink = null;
            foreach ($response->result->links as $link) {
                if ($link->rel === 'approve') {
                    $approvalLink = $link->href;
                    break;
                }
            }
            
            if (!$approvalLink) {
                throw new \Exception('Lien d\'approbation PayPal non trouvé');
            }
            
            return $this->redirect($approvalLink);
            
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur PayPal: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/return', name: 'paypal_return', methods: ['GET'])]
    public function paypalReturn(Request $request, EntityManagerInterface $em): Response
    {
        $orderId = $request->query->get('token') ?? $request->getSession()->get('paypal_order_id');
        
        if (!$orderId) {
            $this->addFlash('danger', 'Informations de commande PayPal manquantes.');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $client = $this->getPayPalClient();
            $captureRequest = new OrdersCaptureRequest($orderId);
            $captureRequest->prefer('return=representation');
            
            $response = $client->execute($captureRequest);
            
            if ($response->result->status !== 'COMPLETED') {
                throw new \Exception('La capture de paiement n\'a pas été complétée. Statut: ' . $response->result->status);
            }
            
            $amount = 0;
            foreach ($response->result->purchase_units as $unit) {
                if (isset($unit->payments->captures[0]->amount->value)) {
                    $amount = (float)$unit->payments->captures[0]->amount->value;
                    break;
                }
            }
            
            if ($amount <= 0) {
                throw new \Exception('Montant de paiement invalide');
            }
            
            $transaction = new Transactions();
            $transaction->setUser($user);
            $transaction->setAmount($amount);
            $transaction->setMethod('paypal');
            $transaction->setCreatedAt(new \DateTimeImmutable());
            $em->persist($transaction);
            
            $user->setBalance($user->getBalance() + $amount);
            $em->persist($user);
            $em->flush();
            
            $this->addFlash('success', "Dépôt de $amount USD réussi via PayPal !");
            return $this->redirectToRoute('app_profile');
            
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur lors du traitement du paiement PayPal: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/cancel', name: 'paypal_cancel', methods: ['GET'])]
    public function paypalCancel(): Response
    {
        $this->addFlash('warning', 'Paiement PayPal annulé.');
        return $this->redirectToRoute('app_profile');
    }

    /**
     * Crée le client PayPal en fonction de l'environnement
     */
    private function getPayPalClient(): PayPalHttpClient
    {
        $clientId = $_ENV["PAYPAL_CLIENT_ID"];
        $clientSecret = $_ENV["PAYPAL_CLIENT_SECRET"];
        
        $isProduction = $_ENV["APP_ENV"] === 'prod';
        
        if ($isProduction) {
            $environment = new ProductionEnvironment($clientId, $clientSecret);
        } else {
            $environment = new SandboxEnvironment($clientId, $clientSecret);
        }
        
        return new PayPalHttpClient($environment);
    }

    // Méthodes pour les autres moyens de paiement
    private function processCardPayment(User $user, float $amount): bool
    {
        // Implémenter l'appel à l'API de paiement par carte
        return true;
    }

    private function processMobileMoney(User $user, float $amount): bool
    {
        // Implémenter l'appel à l'API Mobile Money
        return true;
    }

    private function convertToTRX(float $amount, string $fromCurrency): float
    {
        // Logique de conversion de devises (si nécessaire)
        return $amount * 10;
    }

    private function executeCryptoTransfer(
        string $sourceWallet,
        string $destinationWallet,
        float $amountTRX,
        string $cryptoType
    ): bool {
        // Implémenter le transfert crypto si vous souhaitez opérer une conversion interne
        return true;
    }

    /**
     * Appel à l'API CoinPayments
     */
    private function coinPaymentsApiCall(string $cmd, array $params = []): array
    {
        $privateKey = $_ENV['COINPAYMENTS_API_SECRET'];
        $publicKey = $_ENV['COINPAYMENTS_API_KEY'];

        $params['version'] = 1;
        $params['cmd']     = $cmd;
        $params['key']     = $publicKey;
        $params['format']  = 'json';

        $postData = http_build_query($params, '', '&');
        $hmac = hash_hmac('sha512', $postData, $privateKey);

        $ch = curl_init('https://www.coinpayments.net/api.php');
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['hmac: ' . $hmac]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);

        $data = curl_exec($ch);

        if ($data === false) {
            throw new \Exception('Erreur cURL : ' . curl_error($ch));
        }
        curl_close($ch);

        $result = json_decode($data, true);
        if ($result['error'] !== 'ok') {
            throw new \Exception('Erreur CoinPayments : ' . $result['error']);
        }
        return $result;
    }
}