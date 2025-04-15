<?php

namespace App\DataFixtures;

use App\Factory\ProductFactory;
use App\Factory\ShopFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class ProductFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // Créer 3 shops
        ShopFactory::createMany(3);

        // Générer 10 produits aléatoires
        ProductFactory::createMany(10);

        // Ne pas oublier de flush !
        $manager->flush();
    }
}
