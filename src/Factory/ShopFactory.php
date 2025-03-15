<?php
namespace App\Factory;

use App\Entity\Shop;
use Zenstruck\Foundry\Persistence\PersistentProxyObjectFactory;

/**
 * @extends PersistentProxyObjectFactory<Shop>
 */
final class ShopFactory extends PersistentProxyObjectFactory
{
    private static array $usedShops = [];

    public function __construct() {}

    public static function class(): string
    {
        return Shop::class;
    }

    protected function defaults(): array|callable
    {
        // Liste des shops avec leurs images
        $shops = [
            'VIP A' => 'VIP_A.webp',
            'VIP B' => 'VIP_B.webp',
            'VIP C' => 'VIP_C.webp',
        ];

        // Filtrer les shops déjà utilisés pour éviter les duplications
        $remainingShops = array_diff(array_keys($shops), self::$usedShops);

        // Si tous les shops sont utilisés, réinitialiser la liste
        if (empty($remainingShops)) {
            self::$usedShops = [];
            $remainingShops = array_keys($shops);
        }

        // Choisir un shop aléatoire parmi ceux qui n'ont pas encore été utilisés
        $shopName = self::faker()->randomElement($remainingShops);
        $image = $shops[$shopName];

        // Marquer ce shop comme utilisé
        self::$usedShops[] = $shopName;

        return [
            'image' => $image,
            'name' => $shopName,  // Utilise le nom du shop
            'slug' => strtolower(str_replace(' ', '-', $shopName)), // Slug généré automatiquement
            'description' => self::faker()->text(255),
        ];
    }

    protected function initialize(): static
    {
        return $this;
    }
}