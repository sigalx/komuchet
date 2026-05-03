<?php

namespace App\Repository;

use App\Entity\ElectricityTariffZone;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ElectricityTariffZone>
 */
class ElectricityTariffZoneRepository extends ServiceEntityRepository
{
    public const SORT_CODE = 'code';
    public const SORT_NAME = 'name';
    public const SORT_SORT_ORDER = 'sort_order';
    public const SORT_UPDATED_AT = 'updated_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityTariffZone::class);
    }

    /**
     * @return list<ElectricityTariffZone>
     */
    public function findActiveByWorkspace(Workspace $workspace): array
    {
        return $this->createActiveByWorkspaceForAdminListQuery($workspace)
            ->getQuery()
            ->getResult();
    }

    public function createActiveByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $sort = self::SORT_SORT_ORDER,
        string $direction = self::SORT_ASC,
    ): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('tariffZone')
            ->andWhere('tariffZone.workspace = :workspace')
            ->andWhere('tariffZone.deletedAt IS NULL')
            ->setParameter('workspace', $workspace);

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_CODE,
            self::SORT_NAME,
            self::SORT_SORT_ORDER,
            self::SORT_UPDATED_AT,
        ], true) ? $sort : self::SORT_SORT_ORDER;
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
                ->orderBy('tariffZone.code', $dqlDirection)
                ->addOrderBy('tariffZone.sortOrder', 'ASC')
                ->addOrderBy('tariffZone.name', 'ASC'),
            self::SORT_NAME => $queryBuilder
                ->orderBy('tariffZone.name', $dqlDirection)
                ->addOrderBy('tariffZone.sortOrder', 'ASC'),
            self::SORT_UPDATED_AT => $queryBuilder
                ->orderBy('tariffZone.updatedAt', $dqlDirection)
                ->addOrderBy('tariffZone.sortOrder', 'ASC')
                ->addOrderBy('tariffZone.name', 'ASC'),
            default => $queryBuilder
                ->orderBy('tariffZone.sortOrder', $dqlDirection)
                ->addOrderBy('tariffZone.name', 'ASC'),
        };
    }

    public function findOneActiveByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?ElectricityTariffZone
    {
        return $this->createQueryBuilder('tariffZone')
            ->andWhere('tariffZone.workspace = :workspace')
            ->andWhere('tariffZone.uuid = :uuid')
            ->andWhere('tariffZone.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByWorkspaceAndCode(Workspace $workspace, string $code): ?ElectricityTariffZone
    {
        return $this->createQueryBuilder('tariffZone')
            ->andWhere('tariffZone.workspace = :workspace')
            ->andWhere('tariffZone.code = :code')
            ->andWhere('tariffZone.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('code', trim($code))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
