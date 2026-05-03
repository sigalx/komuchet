<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountGroup;
use App\Entity\AccountGroupMember;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountGroupMember>
 */
class AccountGroupMemberRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountGroupMember::class);
    }

    /**
     * @return list<AccountGroupMember>
     */
    public function findActiveByGroup(Workspace $workspace, AccountGroup $accountGroup): array
    {
        return $this->createQueryBuilder('groupMember')
            ->addSelect('account')
            ->innerJoin('groupMember.account', 'account')
            ->andWhere('groupMember.workspace = :workspace')
            ->andWhere('groupMember.accountGroup = :accountGroup')
            ->andWhere('groupMember.validTo IS NULL')
            ->andWhere('account.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('accountGroup', $accountGroup)
            ->orderBy('account.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneActiveByGroupAndAccount(Workspace $workspace, AccountGroup $accountGroup, Account $account): ?AccountGroupMember
    {
        return $this->createQueryBuilder('groupMember')
            ->andWhere('groupMember.workspace = :workspace')
            ->andWhere('groupMember.accountGroup = :accountGroup')
            ->andWhere('groupMember.account = :account')
            ->andWhere('groupMember.validTo IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('accountGroup', $accountGroup)
            ->setParameter('account', $account)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
