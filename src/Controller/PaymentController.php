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

        $this->addFlash('danger', 'Dépôt non disponible pour le moment !');
        return $this->redirectToRoute('app_profile');
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

        // Création du client PayPal
        $client = $this->getPayPalClient();
        
        try {
            // Création de la requête d'ordre
            $request = new OrdersCreateRequest();
            $request->prefer('return=representation');
            
            // Structure de l'ordre PayPal avec le nouveau SDK
            $request->body = [
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
            
            // Envoi de la requête à PayPal
            $response = $client->execute($request);
            
            if ($response->statusCode !== 201) {
                throw new \Exception('Échec de la création de l\'ordre PayPal: ' . $response->statusCode);
            }
            
            // Sauvegarde de l'ID de l'ordre en session
            $request->getSession()->set('paypal_order_id', $response->result->id);
            
            // Recherche du lien d'approbation
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
            
            // Redirection vers PayPal
            return $this->redirect($approvalLink);
            
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur PayPal: ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/return', name: 'paypal_return', methods: ['GET'])]
    public function paypalReturn(Request $request, EntityManagerInterface $em): Response
    {
        // Récupération de l'ID de l'ordre depuis la session ou la requête
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
            // Création du client PayPal
            $client = $this->getPayPalClient();
            
            // Création de la requête de capture
            $request = new OrdersCaptureRequest($orderId);
            $request->prefer('return=representation');
            
            // Exécution de la requête de capture
            $response = $client->execute($request);
            
            // Vérification du statut de la capture
            if ($response->result->status !== 'COMPLETED') {
                throw new \Exception('La capture de paiement n\'a pas été complétée. Statut: ' . $response->result->status);
            }
            
            // Récupération du montant payé
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
            
            // Enregistrement de la transaction
            $transaction = new Transactions();
            $transaction->setUser($user);
            $transaction->setAmount($amount);
            $transaction->setMethod('paypal');
            $transaction->setCreatedAt(new \DateTimeImmutable());
            $em->persist($transaction);
            
            // Mise à jour du solde de l'utilisateur
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
        
        // Déterminer si nous sommes en environnement de production ou de test
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
        // Logique de conversion de devises
        return $amount * 10;
    }

    private function executeCryptoTransfer(
        string $sourceWallet,
        string $destinationWallet,
        float $amountTRX,
        string $cryptoType
    ): bool {
        // Implémenter le transfert crypto
        return true;
    }
}