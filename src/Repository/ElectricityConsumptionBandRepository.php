<?php

namespace App\Repository;

use App\Entity\ElectricityConsumptionBand;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ElectricityConsumptionBand>
 */
class ElectricityConsumptionBandRepository extends ServiceEntityRepository
{
    public const SORT_CODE = 'code';
    public const SORT_NAME = 'name';
    public const SORT_SORT_ORDER = 'sort_order';
    public const SORT_UPDATED_AT = 'updated_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityConsumptionBand::class);
    }

    /**
     * @return list<ElectricityConsumptionBand>
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
        $queryBuilder = $this->createQueryBuilder('consumptionBand')
            ->andWhere('consumptionBand.workspace = :workspace')
            ->andWhere('consumptionBand.deletedAt IS NULL')
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
                ->orderBy('consumptionBand.code', $dqlDirection)
                ->addOrderBy('consumptionBand.sortOrder', 'ASC')
                ->addOrderBy('consumptionBand.name', 'ASC'),
            self::SORT_NAME => $queryBuilder
                ->orderBy('consumptionBand.name', $dqlDirection)
                ->addOrderBy('consumptionBand.sortOrder', 'ASC'),
            self::SORT_UPDATED_AT => $queryBuilder
                ->orderBy('consumptionBand.updatedAt', $dqlDirection)
                ->addOrderBy('consumptionBand.sortOrder', 'ASC')
                ->addOrderBy('consumptionBand.name', 'ASC'),
            default => $queryBuilder
                ->orderBy('consumptionBand.sortOrder', $dqlDirection)
                ->addOrderBy('consumptionBand.name', 'ASC'),
        };
    }

    public function findOneActiveByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?ElectricityConsumptionBand
    {
        return $this->createQueryBuilder('consumptionBand')
            ->andWhere('consumptionBand.workspace = :workspace')
            ->andWhere('consumptionBand.uuid = :uuid')
            ->andWhere('consumptionBand.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByWorkspaceAndCode(Workspace $workspace, string $code): ?ElectricityConsumptionBand
    {
        return $this->createQueryBuilder('consumptionBand')
            ->andWhere('consumptionBand.workspace = :workspace')
            ->andWhere('consumptionBand.code = :code')
            ->andWhere('consumptionBand.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('code', trim($code))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
