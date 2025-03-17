<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Transactions;
use App\Entity\User;

// PayPal Server SDK
use PaypalServerSdkLib\Models\Builders\OrderRequestBuilder;
use PaypalServerSdkLib\Models\Builders\PurchaseUnitRequestBuilder;
use PaypalServerSdkLib\Models\Builders\AmountWithBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\AmountBreakdownBuilder;
use PaypalServerSdkLib\Models\Builders\MoneyBuilder;
use PaypalServerSdkLib\Models\Builders\ItemBuilder;
use PaypalServerSdkLib\PaypalServerSdkClientBuilder;
use PaypalServerSdkLib\Authentication\ClientCredentialsAuthCredentialsBuilder;
use PaypalServerSdkLib\Environment;

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
                // Redirige vers la page de dépôt PayPal
                return $this->redirectToRoute('app_paypal_deposit', ['amount' => $amount]);

            case 'crypto':
                $cryptoType = $request->request->get('cryptoType');
                $walletAddress = $request->request->get('walletAddress');
                if (!$cryptoType || !$walletAddress) {
                    $this->addFlash('danger', 'Informations de crypto manquantes.');
                    return $this->redirectToRoute('app_profile');
                }
                $convertedAmount = $this->convertToTRX($amount, $cryptoType);
                $commerceWalletAddress = 'TLQMEec1F5zJuHXsgKWfbUqEHXWj9p5KkV';
                if (!$this->executeCryptoTransfer($walletAddress, $commerceWalletAddress, $convertedAmount, $cryptoType)) {
                    $this->addFlash('danger', 'Erreur de transfert crypto.');
                    return $this->redirectToRoute('app_profile');
                }
                break;

            default:
                $this->addFlash('danger', 'Méthode de paiement invalide.');
                return $this->redirectToRoute('app_profile');
        }

        // Enregistrement de la transaction
        $transaction = new Transactions();
        $transaction->setUser($user);
        $transaction->setAmount($amount);
        $transaction->setMethod($paymentMethod);
        $transaction->setCreatedAt(new \DateTimeImmutable());
        $em->persist($transaction);

        // Mise à jour du solde
        $user->setBalance($user->getBalance() + $amount);
        $em->flush();

        $this->addFlash('success', 'Dépôt réussi !');
        return $this->redirectToRoute('app_profile');
    }

    #[Route('/paypal/deposit', name: 'app_paypal_deposit', methods: ['GET'])]
    public function paypalDeposit(Request $request): Response
    {
        // Récupération du client_id Live (ou Sandbox) depuis .env
        $paypalClientId = $_ENV["PAYPAL_CLIENT_ID"] ?? '';

        return $this->render('paypal_deposit.html.twig', [
            'amount' => $request->query->get('amount'),
            'paypal_client_id' => $paypalClientId,
        ]);
    }

    /**
     * Crée l'ordre PayPal (endpoint appelé en AJAX par createOrder).
     */
    #[Route('/api/orders', name: 'api_paypal_create_order', methods: ['POST'])]
    public function apiCreateOrder(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $amount = $data['amount'] ?? '100';

        // Enveloppe "body" + OrderRequestBuilder
        $orderPayload = [
            'body' => OrderRequestBuilder::init("CAPTURE", [
                PurchaseUnitRequestBuilder::init(
                    AmountWithBreakdownBuilder::init("USD", (string)$amount)
                        ->breakdown(
                            AmountBreakdownBuilder::init()
                                ->itemTotal(MoneyBuilder::init("USD", (string)$amount)->build())
                                ->build()
                        )
                        ->build()
                )
                ->items([
                    ItemBuilder::init(
                        "Dépôt",
                        MoneyBuilder::init("USD", (string)$amount)->build(),
                        "1"
                    )
                    ->description("Dépôt sur le compte")
                    ->sku("deposit01")
                    ->build(),
                ])
                ->build(),
            ])->build(),
        ];

        try {
            $client = $this->initPaypalClient();
            $apiResponse = $client->getOrdersController()->ordersCreate($orderPayload);

            // Décoder la réponse PayPal
            $jsonResponse = json_decode($apiResponse->getBody(), true);

            // Vérifier la présence d'un id
            if (!isset($jsonResponse['id'])) {
                return new JsonResponse([
                    'error' => 'Order ID manquant',
                    'response' => $jsonResponse
                ], 400);
            }

            return new JsonResponse(['id' => $jsonResponse['id']]);
        } catch (\Exception $e) {
            // Vérifiez les logs pour voir le détail
            return new JsonResponse([
                'error'   => 'Erreur interne lors de la création de la commande',
                'message' => $e->getMessage(),
                // Activez ceci en dev pour voir la pile d'appels :
                // 'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * Capture l'ordre (endpoint appelé en AJAX par onApprove).
     */
    #[Route('/api/orders/{orderId}/capture', name: 'api_paypal_capture_order', methods: ['POST'])]
    public function apiCaptureOrder(string $orderId): JsonResponse
    {
        try {
            $client = $this->initPaypalClient();
            $apiResponse = $client->getOrdersController()->ordersCapture(["id" => $orderId]);

            return new JsonResponse(json_decode($apiResponse->getBody(), true));
        } catch (\Exception $e) {
            return new JsonResponse([
                'error'   => 'Erreur lors de la capture de la commande',
                'message' => $e->getMessage(),
                // 'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * Confirme le dépôt dans la BDD une fois la capture terminée.
     */
    #[Route('/api/paypal/deposit/confirm', name: 'api_paypal_deposit_confirm', methods: ['POST'])]
    public function confirmPaypalDeposit(Request $request, EntityManagerInterface $em): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['error' => 'User not authenticated'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $amount = (float)($data['amount'] ?? 0);

        // Enregistrement de la transaction
        $transaction = new Transactions();
        $transaction->setUser($user);
        $transaction->setAmount($amount);
        $transaction->setMethod('paypal');
        $transaction->setCreatedAt(new \DateTimeImmutable());
        $em->persist($transaction);

        // Mise à jour du solde
        $user->setBalance($user->getBalance() + $amount);
        $em->flush();

        return new JsonResponse(['status' => 'success']);
    }

    // Méthodes fictives pour gérer les autres moyens de paiement
    private function processCardPayment(User $user, float $amount): bool
    {
        // Implémenter l'appel à l'API de paiement par carte (Stripe, etc.)
        return true;
    }

    private function processMobileMoney(User $user, float $amount): bool
    {
        // Implémenter l'appel à l'API Mobile Money (ex: KakiaPay, FadaPay, etc.)
        return true;
    }

    private function convertToTRX(float $amount, string $fromCurrency): float
    {
        // Conversion fictive en TRX
        return $amount * 10;
    }

    private function executeCryptoTransfer(
        string $sourceWallet,
        string $destinationWallet,
        float $amountTRX,
        string $cryptoType
    ): bool {
        // Implémenter l'appel à l'API CoinPayments (ou autre) pour le transfert
        return true;
    }

    /**
     * Initialise le client PayPal en mode LIVE (ou SANDBOX pour test).
     */
    private function initPaypalClient()
    {
        return PaypalServerSdkClientBuilder::init()
            ->clientCredentialsAuthCredentials(
                ClientCredentialsAuthCredentialsBuilder::init(
                    $_ENV["PAYPAL_CLIENT_ID"],
                    $_ENV["PAYPAL_CLIENT_SECRET"]
                )
            )
            // Pour tester, passer en SANDBOX :
            // ->environment(Environment::SANDBOX)
            ->environment(Environment::LIVE)
            ->build();
    }
}