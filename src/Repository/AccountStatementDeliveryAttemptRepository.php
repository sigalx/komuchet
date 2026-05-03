<?php

namespace App\Repository;

use App\Entity\AccountStatementDeliveryAttempt;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountStatementDeliveryAttempt>
 */
class AccountStatementDeliveryAttemptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountStatementDeliveryAttempt::class);
    }

    /**
     * @return list<AccountStatementDeliveryAttempt>
     */
    public function findQueued(int $limit): array
    {
        return $this->createQueryBuilder('attempt')
            ->addSelect('delivery')
            ->addSelect('statement')
            ->addSelect('workspace')
            ->innerJoin('attempt.delivery', 'delivery')
            ->innerJoin('delivery.accountStatement', 'statement')
            ->innerJoin('attempt.workspace', 'workspace')
            ->andWhere('attempt.startedAt IS NULL')
            ->andWhere('attempt.succeededAt IS NULL')
            ->andWhere('attempt.failedAt IS NULL')
            ->andWhere('delivery.cancelledAt IS NULL')
            ->andWhere('statement.cancelledAt IS NULL')
            ->orderBy('attempt.queuedAt', 'ASC')
            ->addOrderBy('attempt.attemptNumber', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
