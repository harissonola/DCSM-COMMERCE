<?php

namespace App\Repository;

use App\Entity\Shop;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Shop>
 */
class ShopRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Shop::class);
    }

    public function countActive(): int
    {
        return $this->createQueryBuilder('s')
            ->select('COUNT(DISTINCT s.id)')
            ->innerJoin('s.products', 'p')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findWithProductsCount(): array
    {
        return $this->createQueryBuilder('s')
            ->select('s, COUNT(p.id) as productsCount')
            ->leftJoin('s.products', 'p')
            ->groupBy('s.id')
            ->orderBy('productsCount', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function searchByName(string $query): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.name LIKE :query')
            ->setParameter('query', '%'.$query.'%')
            ->orderBy('s.name', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }
}