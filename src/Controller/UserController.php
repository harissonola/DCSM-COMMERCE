<?php
// src/Controller/UserController.php

namespace App\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class UserController extends AbstractController
{
    #[Route('/profile/product/remove/{id}', name: 'app_user_remove_product')]
    public function removeProduct(Product $product, EntityManagerInterface $entityManager): Response
    {
        $user = $this->getUser();

        if (!$user->getProduct()->contains($product)) {
            $this->addFlash('danger', 'Vous ne possédez pas ce produit.');
            return $this->redirectToRoute('app_profile');
        }

        // Vérification du token CSRF
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('delete-product-' . $product->getId(), $token)) {
            $this->addFlash('danger', 'Jeton de sécurité invalide.');
            return $this->redirectToRoute('app_profile');
        }

        // Récupérer le prix actuel du produit
        $productPrice = ($product->getPrice() / 601.50);

        // Ajouter le prix au solde de l'utilisateur (remboursement)
        $user->setBalance($user->getBalance() + $productPrice);

        // Supprimer le produit de la collection de l'utilisateur
        $user->removeProduct($product);

        // Enregistrer les modifications
        $entityManager->flush();

        $this->addFlash('success', 'Produit supprimé avec succès. Votre solde a été crédité de ' . number_format($productPrice, 2) . ' $');

        return $this->redirectToRoute('app_profile');
    }
}
