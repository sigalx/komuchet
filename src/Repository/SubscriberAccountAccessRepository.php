<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SubscriberAccountAccess>
 */
class SubscriberAccountAccessRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SubscriberAccountAccess::class);
    }

    /**
     * @return list<SubscriberAccountAccess>
     */
    public function findActiveBySubscriber(Workspace $workspace, Subscriber $subscriber): array
    {
        return $this->createQueryBuilder('access')
            ->addSelect('account')
            ->innerJoin('access.account', 'account')
            ->andWhere('access.workspace = :workspace')
            ->andWhere('access.subscriber = :subscriber')
            ->andWhere('access.revokedAt IS NULL')
            ->andWhere('account.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('subscriber', $subscriber)
            ->orderBy('account.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<SubscriberAccountAccess>
     */
    public function findActiveByAccount(Workspace $workspace, Account $account): array
    {
        return $this->createQueryBuilder('access')
            ->addSelect('subscriber')
            ->innerJoin('access.subscriber', 'subscriber')
            ->andWhere('access.workspace = :workspace')
            ->andWhere('access.account = :account')
            ->andWhere('access.revokedAt IS NULL')
            ->andWhere('subscriber.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('subscriber.lastName', 'ASC')
            ->addOrderBy('subscriber.firstName', 'ASC')
            ->addOrderBy('subscriber.secondName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneActiveBySubscriberAndAccount(Workspace $workspace, Subscriber $subscriber, Account $account): ?SubscriberAccountAccess
    {
        return $this->createQueryBuilder('access')
            ->andWhere('access.workspace = :workspace')
            ->andWhere('access.subscriber = :subscriber')
            ->andWhere('access.account = :account')
            ->andWhere('access.revokedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('subscriber', $subscriber)
            ->setParameter('account', $account)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
