<?php

namespace App\Factory;

use App\Entity\Product;
use App\Factory\ShopFactory;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

final class ProductFactory extends PersistentProxyObjectFactory
{
    private static array $usedProducts = [];

    public function __construct() {}

    public static function class(): string
    {
        return Product::class;
    }

    protected function defaults(): array|callable
    {
        // Liste des produits fictifs en lien avec la crypto
        $products = [
            'Crypto Wallet',
            'Bitcoin T-shirt',
            'Ethereum Sticker',
            'NFT Art Frame',
            'Blockchain Book',
            'Crypto Mining Rig',
            'Ledger Nano S',
            'Bitcoin Mug',
            'Crypto-Themed Hoodie',
            'Ethereum Cap'
        ];

        // Filtrer les produits déjà utilisés pour éviter les duplications
        $remainingProducts = array_diff($products, self::$usedProducts);

        // Si tous les produits sont utilisés, réinitialiser la liste
        if (empty($remainingProducts)) {
            self::$usedProducts = [];
            $remainingProducts = $products;
        }

        // Choisir un produit aléatoire parmi ceux qui n'ont pas encore été utilisés
        $productName = self::faker()->randomElement($remainingProducts);

        // Marquer ce produit comme utilisé
        self::$usedProducts[] = $productName;

        // Lier le produit à un shop existant
        $shop = ShopFactory::random(); // Utilise un shop aléatoire parmi ceux déjà créés

        // Récupérer le slug du shop
        $shopSlug = $shop->getSlug();

        // Déterminer le prix en fonction du slug du shop
        $price = $this->getPriceByShopSlug($shopSlug);

        return [
            'image' => $this->generateProductImage($productName), // Génère une image en fonction du produit
            'createdAt' => new \DateTimeImmutable(),
            'name' => $productName, // Utilise le nom généré
            'price' => $price, // Applique le prix calculé
            'slug' => $this->generateSlug($productName), // Génère un slug basé sur le nom
            'shop' => $shop, // Associe le produit à un shop
            'description' => self::faker()->text(255),
        ];
    }

    /**
     * Génère une image liée au produit.
     * Cette version utilise le nom exact du produit pour générer l'URL de l'image.
     */
    private function generateProductImage(string $productName): string
    {
        // Utiliser le nom exact du produit, avec remplacement des espaces par des tirets et tout en minuscules
        $formattedName = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $productName)));

        // Retourner l'URL complète de l'image sur GitHub dans le dossier "products"
        return "https://raw.githubusercontent.com/harissonola/my-cdn/main/products/{$formattedName}.webp";
    }

    /**
     * Génère un slug basé sur le nom du produit.
     */
    private function generateSlug(string $productName): string
    {
        return strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $productName)));
    }

    /**
     * Détermine le prix du produit en fonction du slug du shop.
     */
    private function getPriceByShopSlug(string $slug): int
    {
        switch ($slug) {
            case 'vip-a':
                return rand(3000, 4999);
            case 'vip-b':
                return rand(5000, 11999);
            case 'vip-c':
                return rand(12000, 50000);
            default:
                return rand(3000, 9999); // Valeur par défaut si le slug ne correspond à aucun cas
        }
    }

    protected function initialize(): static
    {
        return $this;
    }
}
