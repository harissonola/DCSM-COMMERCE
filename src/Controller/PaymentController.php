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

// Utilisation du SDK classique PayPal
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\Payer;
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
            $apiContext = $this->initPaypalContext();
            $payment->create($apiContext);

            $approvalUrl = null;
            foreach ($payment->getLinks() as $link) {
                if ($link->getRel() === 'approval_url') {
                    $approvalUrl = $link->getHref();
                    break;
                }
            }
            if (!$approvalUrl) {
                $this->addFlash('danger', 'Lien d\'approbation non trouvé.');
                return $this->redirectToRoute('app_profile');
            }
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
        $payerId   = $request->query->get('PayerID');

        if (!$paymentId || !$payerId) {
            $this->addFlash('danger', 'Informations de paiement manquantes.');
            return $this->redirectToRoute('app_profile');
        }

        try {
            $apiContext = $this->initPaypalContext();
            $payment = Payment::get($paymentId, $apiContext);

            $execution = new PaymentExecution();
            $execution->setPayerId($payerId);

            $result = $payment->execute($execution, $apiContext);

            if ($result->getState() === 'approved') {
                $amount = (float)$result->getTransactions()[0]->getAmount()->getTotal();

                $user = $this->getUser();
                $transaction = new Transactions();
                $transaction->setUser($user);
                $transaction->setAmount($amount);
                $transaction->setMethod('paypal');
                $transaction->setCreatedAt(new \DateTimeImmutable());
                $em->persist($transaction);

                $user->setBalance($user->getBalance() + $amount);
                $em->flush();

                $this->addFlash('success', 'Transaction réussie !');
                return $this->redirectToRoute('app_profile');
            } else {
                $this->addFlash('danger', 'La transaction n\'a pas été approuvée.');
                return $this->redirectToRoute('app_profile');
            }
        } catch (\Exception $e) {
            $this->addFlash('danger', 'Erreur PayPal : ' . $e->getMessage());
            return $this->redirectToRoute('app_profile');
        }
    }

    #[Route('/paypal/cancel', name: 'paypal_cancel', methods: ['GET'])]
    public function paypalCancel(): Response
    {
        $this->addFlash('warning', 'Paiement annulé par l\'utilisateur.');
        return $this->redirectToRoute('app_profile');
    }

    private function initPaypalContext()
    {
        $clientId = $_ENV["PAYPAL_CLIENT_ID"];
        $clientSecret = $_ENV["PAYPAL_CLIENT_SECRET"];

        $apiContext = new ApiContext(
            new OAuthTokenCredential($clientId, $clientSecret)
        );
        // Utilise 'sandbox' pour tester ou 'live' pour la production
        $apiContext->setConfig(['mode' => 'sandbox']);

        return $apiContext;
    }

    // Méthodes fictives pour les autres paiements
    private function processCardPayment(User $user, float $amount): bool
    {
        // Implémenter l'appel à l'API de paiement par carte (Stripe, etc.)
        return true;
    }

    private function processMobileMoney(User $user, float $amount): bool
    {
        // Implémenter l'appel à l'API Mobile Money
        return true;
    }

    private function convertToTRX(float $amount, string $fromCurrency): float
    {
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