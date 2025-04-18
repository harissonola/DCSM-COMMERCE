<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Transactions;
use App\Entity\User;
use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\SandboxEnvironment;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Orders\OrdersCreateRequest;
use PayPalCheckoutSdk\Orders\OrdersCaptureRequest;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PaymentController extends AbstractController
{
    private string $tatumApiUrl    = 'https://api-eu1.tatum.io';
    private string $tatumApiKey;
    private string $tatumAccountId;

    public function __construct()
    {
        // Récupérer depuis vos variables d'environnement
        $this->tatumApiKey    = $_ENV['TATUM_API_KEY'];
        $this->tatumAccountId = $_ENV['TATUM_ACCOUNT_ID'];
    }

    /**
     * Envoi d'une requête à l'API Tatum
     */
    private function callTatum(string $method, string $endpoint, array $payload = []): array
    {
        $url = $this->tatumApiUrl . $endpoint;
        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $this->tatumApiKey,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if (!empty($payload) && in_array($method, ['POST','PUT','PATCH'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $response = curl_exec($ch);
        if ($response === false) {
            throw new \Exception('Tatum cURL Error: ' . curl_error($ch));
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);
        if ($code < 200 || $code >= 300 || json_last_error() !== JSON_ERROR_NONE) {
            $msg = $data['message'] ?? $response;
            throw new \Exception("Tatum API Error ({$code}): {$msg}");
        }

        return $data;
    }

    /**
     * Calcule les frais de retrait
     */
    private function calculateWithdrawalFees(float $amount): float
    {
        if ($amount <= 20) {
            $pct = 0.05;
        } elseif ($amount <= 100) {
            $pct = 0.03;
        } elseif ($amount <= 500) {
            $pct = 0.02;
        } else {
            $pct = 0.01;
        }
        return max($amount * $pct, 1.0);
    }

    /**
     * Retrait crypto (offchain ERC‑20)
     */
    #[Route('/withdraw', name: 'app_withdraw', methods: ['POST'])]
    public function withdraw(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amountUsd = (float)$request->request->get('amount');
        $currency  = strtoupper(trim($request->request->get('currency')));
        $address   = trim($request->request->get('recipient'));

        if ($amountUsd < 2 || empty($address)) {
            $this->addFlash('danger', 'Paramètres de retrait invalides.');
            return $this->redirectToRoute('app_profile');
        }

        $fees  = $this->calculateWithdrawalFees($amountUsd);
        $total = $amountUsd + $fees;

        if ($total > $user->getBalance()) {
            $this->addFlash('danger', sprintf(
                'Solde insuffisant pour un retrait de %.2f USD (+ %.2f USD de frais).',
                $amountUsd,
                $fees
            ));
            return $this->redirectToRoute('app_profile');
        }

        // Création de la transaction en statut pending
        $tx = (new Transactions())
            ->setUser($user)
            ->setType('withdrawal')
            ->setAmount($amountUsd)
            ->setFees($fees)
            ->setMethod("Tatum ({$currency})")
            ->setStatus('pending')
            ->setCreatedAt(new \DateTimeImmutable());
        $em->persist($tx);

        // Déduction immédiate du solde
        $user->setBalance($user->getBalance() - $total);
        $em->persist($user);
        $em->flush();

        try {
            // Appel offchain transfer
            $payload = [
                'senderAccountId' => $this->tatumAccountId,
                'recipient'       => $address,
                'token'           => $currency,
                'amount'          => (string)$amountUsd,
            ];
            $res  = $this->callTatum('POST', '/v3/offchain/transfer', $payload);
            $txId = $res['txId'] ?? $res['id'] ?? null;
            if (!$txId) {
                throw new \Exception('Aucun txId retourné par Tatum');
            }

            // Mise à jour de la transaction
            $tx->setExternalId($txId)
               ->setStatus('completed');
            $em->flush();

            $this->addFlash('success', sprintf(
                'Retrait de %.2f USD effectué (TxId: %s).',
                $amountUsd,
                $txId
            ));
        } catch (\Exception $e) {
            // Rollback en cas d'erreur
            $user->setBalance($user->getBalance() + $total);
            $tx->setStatus('failed');
            $em->flush();

            $this->addFlash('danger', 'Erreur lors du retrait : ' . $e->getMessage());
        }

        return $this->redirectToRoute('app_profile');
    }

    /**
     * Dépôt crypto : génération d'adresse
     */
    #[Route('/deposit/redirect', name: 'app_crypto_redirect', methods: ['GET','POST'])]
    public function cryptoRedirect(Request $request, EntityManagerInterface $em): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Montant de dépôt invalide.');
            return $this->redirectToRoute('app_profile');
        }

        if ($request->isMethod('GET')) {
            return $this->render('payment/crypto_deposit.html.twig', ['amount' => $amount]);
        }

        $crypto = strtoupper(trim($request->request->get('cryptoType')));
        if (!$crypto) {
            $this->addFlash('danger', 'Veuillez sélectionner une crypto.');
            return $this->redirectToRoute('app_profile');
        }

        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        try {
            // Récupérer l'adresse de dépôt
            $endpoint = "/v3/offchain/account/{$this->tatumAccountId}/address?index=0";
            $res      = $this->callTatum('GET', $endpoint);
            $address  = $res['address'] ?? null;
            if (!$address) {
                throw new \Exception('Impossible de générer l\'adresse de dépôt');
            }

            // Enregistrer la transaction pending
            $tx = (new Transactions())
                ->setUser($user)
                ->setAmount($amount)
                ->setType('deposit')
                ->setMethod("Tatum ({$crypto})")
                ->setExternalId($address)
                ->setStatus('pending')
                ->setCreatedAt(new \DateTimeImmutable());
            $em->persist($tx);
            $em->flush();

            return $this->render('payment/crypto_deposit.html.twig', [
                'amount'         => $amount,
                'depositAddress' => $address,
            ]);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur dépôt : ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    /**
     * Sélection de méthode de dépôt (crypto / PayPal)
     */
    #[Route('/deposit', name: 'app_deposit', methods: ['POST'])]
    public function deposit(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        $amount = (float)$request->request->get('amount');
        $method = $request->request->get('paymentMethod');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Montant incorrect.');
            return $this->redirectToRoute('app_profile');
        }

        return match ($method) {
            'crypto' => $this->redirectToRoute('app_crypto_redirect', ['amount' => $amount]),
            'paypal' => $this->redirectToRoute('app_paypal_redirect', ['amount' => $amount]),
            default  => $this->addFlash('danger', 'Méthode de paiement invalide.') ?: $this->redirectToRoute('app_profile'),
        };
    }

    /**
     * Redirection vers PayPal
     */
    #[Route('/paypal/redirect', name: 'app_paypal_redirect')]
    public function paypalRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Montant PayPal invalide.');
            return $this->redirectToRoute('app_profile');
        }

        $client = $this->getPayPalClient();
        $req    = new OrdersCreateRequest();
        $req->prefer('return=representation');
        $req->body = [
            'intent'           => 'CAPTURE',
            'purchase_units'   => [[
                'amount' => [
                    'currency_code' => 'USD',
                    'value'         => number_format($amount, 2, '.', ''),
                ]
            ]],
            'application_context' => [
                'return_url' => $this->generateUrl('paypal_return', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'cancel_url' => $this->generateUrl('paypal_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'brand_name' => $this->getParameter('app.site_name'),
                'user_action'=> 'PAY_NOW',
            ],
        ];

        $res = $client->execute($req);
        foreach ($res->result->links as $link) {
            if ($link->rel === 'approve') {
                $approveUrl = $link->href;
                break;
            }
        }

        $request->getSession()->set('paypal_order_id', $res->result->id);
        $request->getSession()->set('paypal_amount',    $amount);

        return $this->redirect($approveUrl);
    }

    /**
     * Retour de PayPal
     */
    #[Route('/paypal/return', name: 'paypal_return')]
    public function paypalReturn(Request $request, EntityManagerInterface $em): Response
    {
        $orderId = $request->query->get('token') ?? $request->getSession()->get('paypal_order_id');
        $amount  = $request->getSession()->get('paypal_amount');

        if (!$orderId || !$amount) {
            $this->addFlash('danger', 'Impossible de récupérer la commande PayPal.');
            return $this->redirectToRoute('app_profile');
        }

        $client    = $this->getPayPalClient();
        $captureRq = new OrdersCaptureRequest($orderId);
        $res       = $client->execute($captureRq);

        if ($res->result->status !== 'COMPLETED') {
            $this->addFlash('danger', 'Paiement non complété.');
            return $this->redirectToRoute('app_profile');
        }

        /** @var User $user */
        $user = $this->getUser();
        $tx   = (new Transactions())
            ->setUser($user)
            ->setAmount($amount)
            ->setType('deposit')
            ->setMethod('paypal')
            ->setStatus('completed')
            ->setExternalId($orderId)
            ->setCreatedAt(new \DateTimeImmutable());

        $user->setBalance($user->getBalance() + $amount);
        $em->persist($tx);
        $em->persist($user);
        $em->flush();

        $request->getSession()->remove('paypal_order_id');
        $request->getSession()->remove('paypal_amount');
        $this->addFlash('success', sprintf('Dépôt PayPal de %.2f USD effectué.', $amount));

        return $this->redirectToRoute('app_profile');
    }

    /**
     * Annulation PayPal
     */
    #[Route('/paypal/cancel', name: 'paypal_cancel')]
    public function paypalCancel(Request $request): Response
    {
        $request->getSession()->remove('paypal_order_id');
        $request->getSession()->remove('paypal_amount');
        $this->addFlash('warning', 'Paiement PayPal annulé.');
        return $this->redirectToRoute('app_profile');
    }

    /**
     * Instanciation du client PayPal
     */
    private function getPayPalClient(): PayPalHttpClient
    {
        if (empty($_ENV['PAYPAL_CLIENT_ID']) || empty($_ENV['PAYPAL_CLIENT_SECRET'])) {
            throw new \Exception('Clés PayPal manquantes');
        }
        $clientId     = $_ENV['PAYPAL_CLIENT_ID'];
        $clientSecret = $_ENV['PAYPAL_CLIENT_SECRET'];
        $env          = $_ENV['APP_ENV'] === 'prod'
            ? new ProductionEnvironment($clientId, $clientSecret)
            : new SandboxEnvironment($clientId, $clientSecret);

        return new PayPalHttpClient($env);
    }
}