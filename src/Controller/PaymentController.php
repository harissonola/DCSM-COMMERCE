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

// PayPal SDK imports
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\Transaction;
use PayPal\Api\Amount;
use PayPal\Api\RedirectUrls;
use PayPal\Rest\ApiContext;
use PayPal\Auth\OAuthTokenCredential;

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

        // Configuration de PayPal
        $apiContext = $this->initPaypalContext();

        // Création du payer
        $payer = new Payer();
        $payer->setPaymentMethod("paypal");

        // Configuration du montant
        $amountObj = new Amount();
        $amountObj->setCurrency("USD")
                  ->setTotal(number_format($amount, 2, '.', ''));

        // Création de la transaction
        $transaction = new Transaction();
        $transaction->setAmount($amountObj)
                    ->setDescription("Dépôt sur le site");

        // Configuration des URLs de redirection
        $redirectUrls = new RedirectUrls();
        $redirectUrls->setReturnUrl($returnUrl)
                     ->setCancelUrl($cancelUrl);

        // Création de l'objet Payment
        $payment = new Payment();
        $payment->setIntent("sale")
                ->setPayer($payer)
                ->setTransactions([$transaction])
                ->setRedirectUrls($redirectUrls);

        try {
            // Création du paiement
            $payment->create($apiContext);
            
            // Récupération de l'URL d'approbation
            $approvalUrl = null;
            foreach ($payment->getLinks() as $link) {
                if ($link->getRel() == 'approval_url') {
                    $approvalUrl = $link->getHref();
                    break;
                }
            }
            
            if (!$approvalUrl) {
                $this->addFlash('danger', 'Lien d\'approbation PayPal non trouvé.');
                return $this->redirectToRoute('app_profile');
            }
            
            // Sauvegarde du paiementId en session (optionnel)
            $request->getSession()->set('paypal_payment_id', $payment->getId());
            
            // Redirection vers PayPal
            return $this->redirect($approvalUrl);
            
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur PayPal : ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/return', name: 'paypal_return', methods: ['GET'])]
    public function paypalReturn(Request $request, EntityManagerInterface $em): Response
    {
        $paymentId = $request->query->get('paymentId');
        $payerId = $request->query->get('PayerID');

        if (!$paymentId || !$payerId) {
            $this->addFlash('danger', 'Informations de paiement PayPal manquantes.');
            return $this->redirectToRoute('app_profile');
        }

        $user = $this->getUser();
        if (!$user) {
            return $this->redirectToRoute('app_login');
        }

        try {
            $apiContext = $this->initPaypalContext();
            
            // Récupération du paiement
            $payment = Payment::get($paymentId, $apiContext);
            
            // Exécution du paiement
            $execution = new PaymentExecution();
            $execution->setPayerId($payerId);
            $result = $payment->execute($execution, $apiContext);
            
            // Vérification que le paiement est approuvé
            if ($result->getState() === 'approved') {
                // Récupération du montant de la transaction
                $transactions = $result->getTransactions();
                if (empty($transactions)) {
                    throw new \Exception('Aucune transaction trouvée');
                }
                
                $amount = (float)$transactions[0]->getAmount()->getTotal();
                
                // Enregistrement de la transaction
                $transaction = new Transactions();
                $transaction->setUser($user);
                $transaction->setAmount($amount);
                $transaction->setMethod('paypal');
                $transaction->setCreatedAt(new \DateTimeImmutable());
                $em->persist($transaction);
                
                // Mise à jour du solde utilisateur
                $user->setBalance($user->getBalance() + $amount);
                $em->persist($user);
                $em->flush();
                
                $this->addFlash('success', "Dépôt de $amount USD réussi via PayPal !");
                return $this->redirectToRoute('app_profile');
            } else {
                $this->addFlash('danger', 'Le paiement PayPal n\'a pas été approuvé.');
                return $this->redirectToRoute('app_profile');
            }
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur lors du traitement du paiement PayPal : ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/cancel', name: 'paypal_cancel', methods: ['GET'])]
    public function paypalCancel(): Response
    {
        $this->addFlash('warning', 'Paiement PayPal annulé.');
        return $this->redirectToRoute('app_profile');
    }

    private function initPaypalContext(): ApiContext
    {
        $clientId = $_ENV["PAYPAL_CLIENT_ID"];
        $clientSecret = $_ENV["PAYPAL_CLIENT_SECRET"];

        $apiContext = new ApiContext(
            new OAuthTokenCredential($clientId, $clientSecret)
        );
        
        // Configuration de l'environnement ('sandbox' ou 'live')
        $apiContext->setConfig([
            'mode' => 'live',  
            'log.LogEnabled' => true,
            'log.FileName' => '../var/log/paypal.log',
            'log.LogLevel' => 'INFO'
        ]);

        return $apiContext;
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