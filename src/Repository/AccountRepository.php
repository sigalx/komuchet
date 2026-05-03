<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\SubscriberAccountAccess;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public const ACCESS_FILTER_ALL = 'all';
    public const ACCESS_FILTER_WITH_SUBSCRIBERS = 'with_subscribers';
    public const ACCESS_FILTER_WITHOUT_SUBSCRIBERS = 'without_subscribers';

    public const SORT_NUMBER = 'number';
    public const SORT_UPDATED_AT = 'updated_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * @return list<Account>
     */
    public function findActiveByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('account')
            ->andWhere('account.workspace = :workspace')
            ->andWhere('account.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->orderBy('account.number', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * @return list<Account>
     */
    public function findActiveByWorkspaceForAdminList(
        Workspace $workspace,
        string $search = '',
        string $accessFilter = self::ACCESS_FILTER_ALL,
        string $sort = self::SORT_NUMBER,
        string $direction = self::SORT_ASC,
    ): array
    {
        return $this->createActiveByWorkspaceForAdminListQuery($workspace, $search, $accessFilter, $sort, $direction)
            ->getQuery()
            ->getResult();
    }

    public function createActiveByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $search = '',
        string $accessFilter = self::ACCESS_FILTER_ALL,
        string $sort = self::SORT_NUMBER,
        string $direction = self::SORT_ASC,
    ): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('account')
            ->andWhere('account.workspace = :workspace')
            ->andWhere('account.deletedAt IS NULL')
            ->setParameter('workspace', $workspace);

        $search = trim($search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere('LOWER(account.number) LIKE :search OR LOWER(account.notes) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ($accessFilter === self::ACCESS_FILTER_WITH_SUBSCRIBERS) {
            $queryBuilder->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s access WHERE access.account = account AND access.workspace = :workspace AND access.revokedAt IS NULL)',
                SubscriberAccountAccess::class,
            ));
        } elseif ($accessFilter === self::ACCESS_FILTER_WITHOUT_SUBSCRIBERS) {
            $queryBuilder->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s access WHERE access.account = account AND access.workspace = :workspace AND access.revokedAt IS NULL)',
                SubscriberAccountAccess::class,
            ));
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_NUMBER,
            self::SORT_UPDATED_AT,
        ], true) ? $sort : self::SORT_NUMBER;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_DESC ? self::SORT_DESC : self::SORT_ASC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_DESC ? 'DESC' : 'ASC';

        match ($sort) {
            self::SORT_UPDATED_AT => $queryBuilder
                ->orderBy('account.updatedAt', $dqlDirection)
                ->addOrderBy('account.number', 'ASC'),
            default => $queryBuilder->orderBy('account.number', $dqlDirection),
        };
    }

    public function normalizeAccessFilter(string $accessFilter): string
    {
        return in_array($accessFilter, [
            self::ACCESS_FILTER_ALL,
            self::ACCESS_FILTER_WITH_SUBSCRIBERS,
            self::ACCESS_FILTER_WITHOUT_SUBSCRIBERS,
        ], true) ? $accessFilter : self::ACCESS_FILTER_ALL;
    }

    public function findOneActiveByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?Account
    {
        return $this->createQueryBuilder('account')
            ->andWhere('account.workspace = :workspace')
            ->andWhere('account.uuid = :uuid')
            ->andWhere('account.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }

    public function findOneActiveByWorkspaceAndNumber(Workspace $workspace, string $number): ?Account
    {
        return $this->createQueryBuilder('account')
            ->andWhere('account.workspace = :workspace')
            ->andWhere('account.number = :number')
            ->andWhere('account.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('number', trim($number))
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
}
