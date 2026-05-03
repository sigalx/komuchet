<?php

namespace App\Repository;

use App\Entity\ElectricityTariffProfile;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ElectricityTariffProfile>
 */
class ElectricityTariffProfileRepository extends ServiceEntityRepository
{
    public const SORT_CODE = 'code';
    public const SORT_NAME = 'name';
    public const SORT_UPDATED_AT = 'updated_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityTariffProfile::class);
    }

    /**
     * @return list<ElectricityTariffProfile>
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
        $queryBuilder = $this->createQueryBuilder('tariffProfile')
            ->andWhere('tariffProfile.workspace = :workspace')
            ->andWhere('tariffProfile.deletedAt IS NULL')
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
                ->orderBy('tariffProfile.code', $dqlDirection)
                ->addOrderBy('tariffProfile.name', 'ASC'),
            self::SORT_UPDATED_AT => $queryBuilder
                ->orderBy('tariffProfile.updatedAt', $dqlDirection)
                ->addOrderBy('tariffProfile.name', 'ASC'),
            default => $queryBuilder
                ->orderBy('tariffProfile.name', $dqlDirection)
                ->addOrderBy('tariffProfile.code', 'ASC'),
        };
    }

    public function findOneActiveByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?ElectricityTariffProfile
    {
        return $this->createQueryBuilder('tariffProfile')
            ->andWhere('tariffProfile.workspace = :workspace')
            ->andWhere('tariffProfile.uuid = :uuid')
            ->andWhere('tariffProfile.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByWorkspaceAndCode(Workspace $workspace, string $code): ?ElectricityTariffProfile
    {
        return $this->createQueryBuilder('tariffProfile')
            ->andWhere('tariffProfile.workspace = :workspace')
            ->andWhere('tariffProfile.code = :code')
            ->andWhere('tariffProfile.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('code', trim($code))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
