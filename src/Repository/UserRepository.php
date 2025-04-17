<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 *
 * @method User|null   find($id, $lockMode = null, $lockVersion = null)
 * @method User|null   findOneBy(array $criteria, array $orderBy = null)
 * @method User[]      findAll()
 * @method User[]      findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * Méthode requise par PasswordUpgraderInterface :
     * Permet de mettre à jour le mot de passe hashé de l’utilisateur.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf(
                'Instances of "%s" are not supported.',
                \get_class($user)
            ));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    /**
     * Compte les utilisateurs créés au cours du dernier mois.
     */
    public function countLastMonth(): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.createdAt >= :date')
            ->setParameter('date', new \DateTime('-1 month'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Récupère les derniers utilisateurs créés.
     *
     * @param int $maxResults Nombre maximum de résultats
     * @return User[]
     */
    public function findLatest(int $maxResults = 5): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.createdAt', 'DESC')
            ->setMaxResults($maxResults)
            ->getQuery()
            ->getResult();
    }

    /**
     * Retourne tous les utilisateurs ayant un rôle donné.
     *
     * @param string $role Ex : "ROLE_ADMIN"
     * @return User[]
     */
    public function findByRole(string $role): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.roles LIKE :role')
            ->setParameter('role', '%"' . $role . '"%')
            ->getQuery()
            ->getResult();
    }

    /**
     * Recherche par email ou nom/prénom (pour autocomplétion).
     *
     * @param string $query
     * @return User[]
     */
    public function searchByEmailOrName(string $query): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.email LIKE :q')
            ->orWhere('u.fname LIKE :q')
            ->orWhere('u.lname LIKE :q')
            ->setParameter('q', '%' . $query . '%')
            ->orderBy('u.email', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les utilisateurs filtrés par rôle et/ou solde minimum.
     *
     * @param string|null $role       'Administrateur' ou 'Utilisateur'
     * @param float|null  $minBalance solde minimum
     * @return User[]
     */
    public function findWithFilters(?string $role, ?float $minBalance): array
    {
        $qb = $this->createQueryBuilder('u');

        if ($role) {
            if ($role === 'Administrateur') {
                // Rôle admin
                $qb->andWhere('u.roles LIKE :admin')
                   ->setParameter('admin', '%"ROLE_ADMIN"%');
            } elseif ($role === 'Utilisateur') {
                // Tout sauf admin
                $qb->andWhere('u.roles NOT LIKE :admin')
                   ->setParameter('admin', '%"ROLE_ADMIN"%');
            }
        }

        if ($minBalance !== null) {
            $qb->andWhere('u.balance >= :minBalance')
               ->setParameter('minBalance', $minBalance);
        }

        return $qb
            ->orderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}