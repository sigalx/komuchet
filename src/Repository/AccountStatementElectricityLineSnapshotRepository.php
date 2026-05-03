<?php

namespace App\Repository;

use App\Entity\AccountStatementElectricityLineSnapshot;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountStatementElectricityLineSnapshot>
 */
class AccountStatementElectricityLineSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountStatementElectricityLineSnapshot::class);
    }

    /**
     * @return list<AccountStatementElectricityLineSnapshot>
     */
    public function findByStatement(Workspace $workspace, AccountStatementSnapshot $statement): array
    {
        return $this->createQueryBuilder('line')
            ->andWhere('line.workspace = :workspace')
            ->andWhere('line.accountStatement = :statement')
            ->setParameter('workspace', $workspace)
            ->setParameter('statement', $statement)
            ->orderBy('line.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
