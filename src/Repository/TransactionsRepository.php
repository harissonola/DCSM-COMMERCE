<?php

namespace App\Repository;

use App\Entity\Transactions;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transactions>
 */
class TransactionsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transactions::class);
    }

    public function sumThisMonth(): float
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.amount)')
            ->where('t.createdAt >= :date')
            ->andWhere('t.status = :status')
            ->setParameter('date', new \DateTime('first day of this month'))
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getSingleScalarResult();

        return $result ?? 0;
    }

    public function findByUser($userId, int $limit = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.user = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('t.createdAt', 'DESC');

        if ($limit) {
            $qb->setMaxResults($limit);
        }

        return $qb->getQuery()->getResult();
    }

    public function getMonthlyStats(): array
    {
        return $this->createQueryBuilder('t')
            ->select("DATE_FORMAT(t.createdAt, '%Y-%m') as month, SUM(t.amount) as total")
            ->where('t.status = :status')
            ->setParameter('status', 'completed')
            ->groupBy('month')
            ->orderBy('month', 'ASC')
            ->getQuery()
            ->getResult();
    }
}