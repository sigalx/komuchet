<?php

namespace App\Repository;

use App\Entity\AccountStatementAccrualSnapshot;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountStatementAccrualSnapshot>
 */
class AccountStatementAccrualSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountStatementAccrualSnapshot::class);
    }

    /**
     * @return list<AccountStatementAccrualSnapshot>
     */
    public function findByStatement(Workspace $workspace, AccountStatementSnapshot $statement): array
    {
        return $this->createQueryBuilder('statementAccrual')
            ->andWhere('statementAccrual.workspace = :workspace')
            ->andWhere('statementAccrual.accountStatement = :statement')
            ->setParameter('workspace', $workspace)
            ->setParameter('statement', $statement)
            ->orderBy('statementAccrual.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
