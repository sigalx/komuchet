<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserEmailIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserEmailIdentity>
 */
class UserEmailIdentityRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserEmailIdentity::class);
    }

    public function findOneActiveByEmailNormalized(string $emailNormalized): ?UserEmailIdentity
    {
        return $this->createQueryBuilder('identity')
            ->addSelect('user')
            ->leftJoin('identity.user', 'user')
            ->andWhere('identity.emailNormalized = :emailNormalized')
            ->andWhere('identity.deletedAt IS NULL')
            ->setParameter('emailNormalized', $emailNormalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByUserAndEmailNormalized(User $user, string $emailNormalized): ?UserEmailIdentity
    {
        return $this->createQueryBuilder('identity')
            ->andWhere('identity.user = :user')
            ->andWhere('identity.emailNormalized = :emailNormalized')
            ->andWhere('identity.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter('emailNormalized', $emailNormalized)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countActiveByUser(User $user): int
    {
        return (int) $this->createQueryBuilder('identity')
            ->select('COUNT(identity.emailNormalized)')
            ->andWhere('identity.user = :user')
            ->andWhere('identity.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<UserEmailIdentity>
     */
    public function findActiveVerifiedByUser(User $user): array
    {
        return $this->createQueryBuilder('identity')
            ->andWhere('identity.user = :user')
            ->andWhere('identity.deletedAt IS NULL')
            ->andWhere('identity.verifiedAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('identity.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
