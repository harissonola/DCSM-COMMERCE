<?php
namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
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
        $amount = (float)$request->request->get('amount');
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
                // Redirection vers le flux PayPal complet
                return $this->redirectToRoute('app_paypal_redirect', ['amount' => $amount]);

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

        // Pour les autres moyens, le traitement se fait ici…
        $this->addFlash('danger', 'Dépôt non disponible pour le moment !');
        return $this->redirectToRoute('app_profile');
    }

    /**
     * Crée l’ordre PayPal et redirige l’utilisateur vers l’URL d’approbation.
     */
    #[Route('/paypal/redirect', name: 'app_paypal_redirect', methods: ['GET'])]
    public function paypalRedirect(Request $request): Response
    {
        $amount = (float)$request->query->get('amount');
        if ($amount <= 0) {
            $this->addFlash('danger', 'Le montant doit être supérieur à zéro.');
            return $this->redirectToRoute('app_profile');
        }

        // Générer les URL de retour et d'annulation en mode ABSOLU
        $returnUrl = $this->generateUrl('paypal_return', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $cancelUrl = $this->generateUrl('paypal_cancel', [], UrlGeneratorInterface::ABSOLUTE_URL);

        // Construction de la payload de l’ordre incluant application_context
        $orderPayload = [
            'body' => OrderRequestBuilder::init("CAPTURE", [
                PurchaseUnitRequestBuilder::init(
                    AmountWithBreakdownBuilder::init("USD", number_format($amount, 2, '.', ''))
                        ->breakdown(
                            AmountBreakdownBuilder::init()
                                ->itemTotal(MoneyBuilder::init("USD", number_format($amount, 2, '.', ''))->build())
                                ->build()
                        )
                        ->build()
                )
                ->items([
                    ItemBuilder::init(
                        "Dépôt",
                        MoneyBuilder::init("USD", number_format($amount, 2, '.', ''))->build(),
                        "1"
                    )
                    ->description("Dépôt sur le compte")
                    ->sku("deposit01")
                    ->build(),
                ])
                ->build(),
            ])
            ->setApplicationContext([
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ])
            ->build(),
        ];

        try {
            $client = $this->initPaypalClient();
            $apiResponse = $client->getOrdersController()->ordersCreate($orderPayload);
            $jsonResponse = json_decode($apiResponse->getBody(), true);

            if (!isset($jsonResponse['id'])) {
                $this->addFlash('danger', 'Erreur lors de la création de la commande.');
                return $this->redirectToRoute('app_profile');
            }

            // Recherche du lien d'approbation dans la réponse
            $approvalUrl = null;
            if (isset($jsonResponse['links']) && is_array($jsonResponse['links'])) {
                foreach ($jsonResponse['links'] as $link) {
                    if ($link['rel'] === 'approve') {
                        $approvalUrl = $link['href'];
                        break;
                    }
                }
            }

            if (!$approvalUrl) {
                $this->addFlash('danger', 'Lien d\'approbation non trouvé.');
                return $this->redirectToRoute('app_profile');
            }

            // Redirection vers PayPal pour approbation
            return $this->redirect($approvalUrl);
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur lors de la création de la commande: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    /**
     * Point de retour après approbation sur PayPal. Capture l'ordre et met à jour la transaction.
     */
    #[Route('/paypal/return', name: 'paypal_return', methods: ['GET'])]
    public function paypalReturn(Request $request, EntityManagerInterface $em): Response
    {
        // PayPal renvoie le token qui correspond à l'orderId
        $orderId = $request->query->get('token');
        if (!$orderId) {
            $this->addFlash('danger', 'Token de commande manquant.');
            return $this->redirectToRoute('app_profile');
        }

        try {
            $client = $this->initPaypalClient();
            $apiResponse = $client->getOrdersController()->ordersCapture(["id" => $orderId]);
            $captureResponse = json_decode($apiResponse->getBody(), true);

            if (isset($captureResponse['status']) && $captureResponse['status'] === 'COMPLETED') {
                // Récupération du montant depuis la réponse
                $amount = (float)$captureResponse['purchase_units'][0]['payments']['captures'][0]['amount']['value'];

                // Enregistrement de la transaction en BDD
                $user = $this->getUser();
                $transaction = new Transactions();
                $transaction->setUser($user);
                $transaction->setAmount($amount);
                $transaction->setMethod('paypal');
                $transaction->setCreatedAt(new \DateTimeImmutable());
                $em->persist($transaction);

                // Mise à jour du solde utilisateur
                $user->setBalance($user->getBalance() + $amount);
                $em->flush();

                $this->addFlash('success', 'Transaction réussie !');
                return $this->redirectToRoute('app_profile');
            } else {
                $this->addFlash('danger', 'Capture de la commande échouée.');
                return $this->redirectToRoute('app_profile');
            }
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur lors de la capture de la commande: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    /**
     * Point de redirection en cas d'annulation du paiement sur PayPal.
     */
    #[Route('/paypal/cancel', name: 'paypal_cancel', methods: ['GET'])]
    public function paypalCancel(): Response
    {
        $this->addFlash('warning', 'Paiement annulé par l\'utilisateur.');
        return $this->redirectToRoute('app_profile');
    }

    // Méthodes fictives pour les autres paiements
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
     * Initialise le client PayPal en mode SANDBOX (ou LIVE en production).
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
            ->environment(Environment::SANDBOX) // Passez en Environment::LIVE pour la production
            ->build();
    }
}