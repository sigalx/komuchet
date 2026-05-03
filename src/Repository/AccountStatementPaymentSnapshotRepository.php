<?php

namespace App\Repository;

use App\Entity\AccountStatementPaymentSnapshot;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountStatementPaymentSnapshot>
 */
class AccountStatementPaymentSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountStatementPaymentSnapshot::class);
    }

    /**
     * @return list<AccountStatementPaymentSnapshot>
     */
    public function findByStatement(Workspace $workspace, AccountStatementSnapshot $statement): array
    {
        return $this->createQueryBuilder('statementPayment')
            ->andWhere('statementPayment.workspace = :workspace')
            ->andWhere('statementPayment.accountStatement = :statement')
            ->setParameter('workspace', $workspace)
            ->setParameter('statement', $statement)
            ->orderBy('statementPayment.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
