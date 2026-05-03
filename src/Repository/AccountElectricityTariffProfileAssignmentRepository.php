<?php

namespace App\Repository;

use App\Entity\AccountElectricityTariffProfileAssignment;
use App\Entity\Account;
use App\Entity\Workspace;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountElectricityTariffProfileAssignment>
 */
class AccountElectricityTariffProfileAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountElectricityTariffProfileAssignment::class);
    }

    /**
     * @return list<AccountElectricityTariffProfileAssignment>
     */
    public function findByAccount(Workspace $workspace, Account $account): array
    {
        return $this->createQueryBuilder('assignment')
            ->addSelect('tariffProfile')
            ->innerJoin('assignment.tariffProfile', 'tariffProfile')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.account = :account')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('assignment.validFrom', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneOpenEndedByAccount(Workspace $workspace, Account $account): ?AccountElectricityTariffProfileAssignment
    {
        return $this->createQueryBuilder('assignment')
            ->addSelect('tariffProfile')
            ->innerJoin('assignment.tariffProfile', 'tariffProfile')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.account = :account')
            ->andWhere('assignment.validTo IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestByAccount(Workspace $workspace, Account $account): ?AccountElectricityTariffProfileAssignment
    {
        return $this->createQueryBuilder('assignment')
            ->addSelect('tariffProfile')
            ->innerJoin('assignment.tariffProfile', 'tariffProfile')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.account = :account')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('assignment.validFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneEffectiveByAccountAt(
        Workspace $workspace,
        Account $account,
        DateTimeImmutable $date,
    ): ?AccountElectricityTariffProfileAssignment {
        return $this->createQueryBuilder('assignment')
            ->addSelect('tariffProfile')
            ->innerJoin('assignment.tariffProfile', 'tariffProfile')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.account = :account')
            ->andWhere('assignment.validFrom <= :date')
            ->andWhere('assignment.validTo IS NULL OR assignment.validTo > :date')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->setParameter('date', $date->format('Y-m-d'))
            ->orderBy('assignment.validFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
