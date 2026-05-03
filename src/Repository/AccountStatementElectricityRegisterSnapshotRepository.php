<?php

namespace App\Repository;

use App\Entity\AccountStatementElectricityRegisterSnapshot;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountStatementElectricityRegisterSnapshot>
 */
class AccountStatementElectricityRegisterSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountStatementElectricityRegisterSnapshot::class);
    }

    /**
     * @return list<AccountStatementElectricityRegisterSnapshot>
     */
    public function findByStatement(Workspace $workspace, AccountStatementSnapshot $statement): array
    {
        return $this->createQueryBuilder('register')
            ->andWhere('register.workspace = :workspace')
            ->andWhere('register.accountStatement = :statement')
            ->setParameter('workspace', $workspace)
            ->setParameter('statement', $statement)
            ->orderBy('register.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
