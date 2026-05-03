<?php

namespace App\Repository;

use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleAllScope;
use App\Entity\ElectricityTariffProfile;
use App\Entity\Workspace;
use App\Enum\ElectricityConsumptionBandRuleScopeMode;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ElectricityConsumptionBandRule>
 */
class ElectricityConsumptionBandRuleRepository extends ServiceEntityRepository
{
    public const SORT_TARIFF_PROFILE = 'tariff_profile';
    public const SORT_MONTH = 'month';
    public const SORT_VALID_FROM = 'valid_from';
    public const SORT_PRIORITY = 'priority';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityConsumptionBandRule::class);
    }

    /**
     * @return list<ElectricityConsumptionBandRule>
     */
    public function findActiveByWorkspace(Workspace $workspace): array
    {
        return $this->createActiveByWorkspaceForAdminListQuery($workspace)
            ->getQuery()
            ->getResult();
    }

    public function createActiveByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $sort = self::SORT_TARIFF_PROFILE,
        string $direction = self::SORT_ASC,
    ): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('rule')
            ->addSelect('tariffProfile')
            ->innerJoin('rule.tariffProfile', 'tariffProfile')
            ->andWhere('rule.workspace = :workspace')
            ->andWhere('rule.deletedAt IS NULL')
            ->setParameter('workspace', $workspace);

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_TARIFF_PROFILE,
            self::SORT_MONTH,
            self::SORT_VALID_FROM,
            self::SORT_PRIORITY,
        ], true) ? $sort : self::SORT_TARIFF_PROFILE;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_DESC ? self::SORT_DESC : self::SORT_ASC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_DESC ? 'DESC' : 'ASC';

        match ($sort) {
            self::SORT_MONTH => $queryBuilder
                ->orderBy('rule.month', $dqlDirection)
                ->addOrderBy('tariffProfile.name', 'ASC')
                ->addOrderBy('rule.validFrom', 'DESC')
                ->addOrderBy('rule.priority', 'ASC'),
            self::SORT_VALID_FROM => $queryBuilder
                ->orderBy('rule.validFrom', $dqlDirection)
                ->addOrderBy('tariffProfile.name', 'ASC')
                ->addOrderBy('rule.month', 'ASC')
                ->addOrderBy('rule.priority', 'ASC'),
            self::SORT_PRIORITY => $queryBuilder
                ->orderBy('rule.priority', $dqlDirection)
                ->addOrderBy('tariffProfile.name', 'ASC')
                ->addOrderBy('rule.month', 'ASC')
                ->addOrderBy('rule.validFrom', 'DESC'),
            default => $queryBuilder
                ->orderBy('tariffProfile.name', $dqlDirection)
                ->addOrderBy('rule.month', 'ASC')
                ->addOrderBy('rule.validFrom', 'DESC')
                ->addOrderBy('rule.priority', 'ASC'),
        };
    }

    /**
     * @return list<ElectricityConsumptionBandRule>
     */
    public function findActiveByProfile(Workspace $workspace, ElectricityTariffProfile $tariffProfile): array
    {
        return $this->createQueryBuilder('rule')
            ->andWhere('rule.workspace = :workspace')
            ->andWhere('rule.tariffProfile = :tariffProfile')
            ->andWhere('rule.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('tariffProfile', $tariffProfile)
            ->orderBy('rule.month', 'ASC')
            ->addOrderBy('rule.validFrom', 'DESC')
            ->addOrderBy('rule.priority', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneActiveByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?ElectricityConsumptionBandRule
    {
        return $this->createQueryBuilder('rule')
            ->addSelect('tariffProfile')
            ->innerJoin('rule.tariffProfile', 'tariffProfile')
            ->andWhere('rule.workspace = :workspace')
            ->andWhere('rule.uuid = :uuid')
            ->andWhere('rule.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveAllScopeByProfileMonthAt(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        int $month,
        DateTimeImmutable $date,
    ): ?ElectricityConsumptionBandRule {
        return $this->createQueryBuilder('rule')
            ->innerJoin(
                ElectricityConsumptionBandRuleAllScope::class,
                'allScope',
                'WITH',
                'allScope.workspace = rule.workspace AND allScope.rule = rule'
            )
            ->andWhere('rule.workspace = :workspace')
            ->andWhere('rule.tariffProfile = :tariffProfile')
            ->andWhere('rule.month = :month')
            ->andWhere('rule.validFrom <= :date')
            ->andWhere('rule.validTo IS NULL OR rule.validTo > :date')
            ->andWhere('rule.deletedAt IS NULL')
            ->andWhere('allScope.mode = :scopeMode')
            ->setParameter('workspace', $workspace)
            ->setParameter('tariffProfile', $tariffProfile)
            ->setParameter('month', $month)
            ->setParameter('date', $date)
            ->setParameter('scopeMode', ElectricityConsumptionBandRuleScopeMode::Include)
            ->orderBy('rule.priority', 'ASC')
            ->addOrderBy('rule.validFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOverlappingActiveRuleWithSamePriority(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        int $month,
        int $priority,
        DateTimeImmutable $validFrom,
        ?DateTimeImmutable $validTo,
        ?Uuid $excludeUuid = null,
    ): ?ElectricityConsumptionBandRule {
        $queryBuilder = $this->createQueryBuilder('rule')
            ->andWhere('rule.workspace = :workspace')
            ->andWhere('rule.tariffProfile = :tariffProfile')
            ->andWhere('rule.month = :month')
            ->andWhere('rule.priority = :priority')
            ->andWhere('rule.deletedAt IS NULL')
            ->andWhere('rule.validTo IS NULL OR rule.validTo > :validFrom')
            ->setParameter('workspace', $workspace)
            ->setParameter('tariffProfile', $tariffProfile)
            ->setParameter('month', $month)
            ->setParameter('priority', $priority)
            ->setParameter('validFrom', $validFrom)
            ->setMaxResults(1);

        if ($validTo !== null) {
            $queryBuilder
                ->andWhere('rule.validFrom < :validTo')
                ->setParameter('validTo', $validTo);
        }

        if ($excludeUuid !== null) {
            $queryBuilder
                ->andWhere('rule.uuid != :excludeUuid')
                ->setParameter('excludeUuid', $excludeUuid);
        }

        return $queryBuilder
            ->getQuery()
            ->getOneOrNullResult();
    }
}
