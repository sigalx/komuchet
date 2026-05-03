<?php

namespace App\Repository;

use App\Entity\AccountGroup;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccountGroup>
 */
class AccountGroupRepository extends ServiceEntityRepository
{
    public const SORT_CODE = 'code';
    public const SORT_NAME = 'name';
    public const SORT_UPDATED_AT = 'updated_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountGroup::class);
    }

    /**
     * @return list<AccountGroup>
     */
    public function findActiveByWorkspace(Workspace $workspace): array
    {
        return $this->createActiveByWorkspaceForAdminListQuery($workspace)
            ->getQuery()
            ->getResult();
    }

    public function createActiveByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $sort = self::SORT_NAME,
        string $direction = self::SORT_ASC,
    ): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('accountGroup')
            ->andWhere('accountGroup.workspace = :workspace')
            ->andWhere('accountGroup.deletedAt IS NULL')
            ->setParameter('workspace', $workspace);

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_CODE,
            self::SORT_NAME,
            self::SORT_UPDATED_AT,
        ], true) ? $sort : self::SORT_NAME;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_DESC ? self::SORT_DESC : self::SORT_ASC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_DESC ? 'DESC' : 'ASC';

        match ($sort) {
            self::SORT_CODE => $queryBuilder
                ->orderBy('accountGroup.code', $dqlDirection)
                ->addOrderBy('accountGroup.name', 'ASC'),
            self::SORT_UPDATED_AT => $queryBuilder
                ->orderBy('accountGroup.updatedAt', $dqlDirection)
                ->addOrderBy('accountGroup.name', 'ASC'),
            default => $queryBuilder
                ->orderBy('accountGroup.name', $dqlDirection)
                ->addOrderBy('accountGroup.code', 'ASC'),
        };
    }

    public function findOneActiveByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?AccountGroup
    {
        return $this->createQueryBuilder('accountGroup')
            ->andWhere('accountGroup.workspace = :workspace')
            ->andWhere('accountGroup.uuid = :uuid')
            ->andWhere('accountGroup.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByWorkspaceAndCode(Workspace $workspace, string $code): ?AccountGroup
    {
        return $this->createQueryBuilder('accountGroup')
            ->andWhere('accountGroup.workspace = :workspace')
            ->andWhere('accountGroup.code = :code')
            ->andWhere('accountGroup.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('code', trim($code))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
