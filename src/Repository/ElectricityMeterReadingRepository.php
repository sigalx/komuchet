<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityTariffZone;
use App\Entity\Workspace;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ElectricityMeterReading>
 */
class ElectricityMeterReadingRepository extends ServiceEntityRepository
{
    public const STATUS_FILTER_ALL = 'all';
    public const STATUS_FILTER_ACTIVE = 'active';
    public const STATUS_FILTER_CANCELLED = 'cancelled';
    public const STATUS_FILTER_SUPERSEDED = 'superseded';

    public const SORT_ACCOUNT_NUMBER = 'account_number';
    public const SORT_SERIAL_NUMBER = 'serial_number';
    public const SORT_TARIFF_ZONE = 'tariff_zone';
    public const SORT_TAKEN_ON = 'taken_on';
    public const SORT_READING_VALUE = 'reading_value';
    public const SORT_SUBMITTED_AT = 'submitted_at';
    public const SORT_SOURCE = 'source';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityMeterReading::class);
    }

    /**
     * @return list<ElectricityMeterReading>
     */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('reading')
            ->addSelect('electricityMeter', 'account', 'tariffZone')
            ->innerJoin('reading.electricityMeter', 'electricityMeter')
            ->innerJoin('electricityMeter.account', 'account')
            ->innerJoin('reading.tariffZone', 'tariffZone')
            ->andWhere('reading.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('reading.takenOn', 'DESC')
            ->addOrderBy('reading.submittedAt', 'DESC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ElectricityMeterReading>
     */
    public function findByWorkspaceForAdminList(
        Workspace $workspace,
        string $search = '',
        ?ElectricityTariffZone $tariffZone = null,
        string $statusFilter = self::STATUS_FILTER_ALL,
        ?DateTimeImmutable $takenOnFrom = null,
        ?DateTimeImmutable $takenOnTo = null,
        string $sort = self::SORT_TAKEN_ON,
        string $direction = self::SORT_DESC,
    ): array {
        return $this->createByWorkspaceForAdminListQuery($workspace, $search, $tariffZone, $statusFilter, $takenOnFrom, $takenOnTo, $sort, $direction)
            ->getQuery()
            ->getResult();
    }

    public function createByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $search = '',
        ?ElectricityTariffZone $tariffZone = null,
        string $statusFilter = self::STATUS_FILTER_ALL,
        ?DateTimeImmutable $takenOnFrom = null,
        ?DateTimeImmutable $takenOnTo = null,
        string $sort = self::SORT_TAKEN_ON,
        string $direction = self::SORT_DESC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('reading')
            ->addSelect('electricityMeter', 'account', 'tariffZone')
            ->innerJoin('reading.electricityMeter', 'electricityMeter')
            ->innerJoin('electricityMeter.account', 'account')
            ->innerJoin('reading.tariffZone', 'tariffZone')
            ->andWhere('reading.workspace = :workspace')
            ->setParameter('workspace', $workspace);

        $search = trim($search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere(<<<'DQL'
                    LOWER(account.number) LIKE :search
                    OR LOWER(electricityMeter.serialNumber) LIKE :search
                    OR LOWER(electricityMeter.model) LIKE :search
                    OR LOWER(reading.notes) LIKE :search
                    DQL)
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ($tariffZone instanceof ElectricityTariffZone) {
            $queryBuilder
                ->andWhere('reading.tariffZone = :tariffZone')
                ->setParameter('tariffZone', $tariffZone);
        }

        if ($statusFilter === self::STATUS_FILTER_ACTIVE) {
            $queryBuilder
                ->andWhere('reading.cancelledAt IS NULL')
                ->andWhere('reading.replacingReading IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_CANCELLED) {
            $queryBuilder->andWhere('reading.cancelledAt IS NOT NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_SUPERSEDED) {
            $queryBuilder->andWhere('reading.replacingReading IS NOT NULL');
        }

        if ($takenOnFrom instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('reading.takenOn >= :takenOnFrom')
                ->setParameter('takenOnFrom', $takenOnFrom);
        }

        if ($takenOnTo instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('reading.takenOn <= :takenOnTo')
                ->setParameter('takenOnTo', $takenOnTo);
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeStatusFilter(string $statusFilter): string
    {
        return in_array($statusFilter, [
            self::STATUS_FILTER_ALL,
            self::STATUS_FILTER_ACTIVE,
            self::STATUS_FILTER_CANCELLED,
            self::STATUS_FILTER_SUPERSEDED,
        ], true) ? $statusFilter : self::STATUS_FILTER_ALL;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_ACCOUNT_NUMBER,
            self::SORT_SERIAL_NUMBER,
            self::SORT_TARIFF_ZONE,
            self::SORT_TAKEN_ON,
            self::SORT_READING_VALUE,
            self::SORT_SUBMITTED_AT,
            self::SORT_SOURCE,
        ], true) ? $sort : self::SORT_TAKEN_ON;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_ASC ? self::SORT_ASC : self::SORT_DESC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_ASC ? 'ASC' : 'DESC';

        match ($sort) {
            self::SORT_ACCOUNT_NUMBER => $queryBuilder
                ->orderBy('account.number', $dqlDirection)
                ->addOrderBy('reading.takenOn', 'DESC')
                ->addOrderBy('tariffZone.sortOrder', 'ASC')
                ->addOrderBy('reading.submittedAt', 'DESC'),
            self::SORT_SERIAL_NUMBER => $queryBuilder
                ->orderBy('electricityMeter.serialNumber', $dqlDirection)
                ->addOrderBy('account.number', 'ASC')
                ->addOrderBy('reading.takenOn', 'DESC')
                ->addOrderBy('reading.submittedAt', 'DESC'),
            self::SORT_TARIFF_ZONE => $queryBuilder
                ->orderBy('tariffZone.sortOrder', $dqlDirection)
                ->addOrderBy('tariffZone.code', $dqlDirection)
                ->addOrderBy('reading.takenOn', 'DESC')
                ->addOrderBy('account.number', 'ASC'),
            self::SORT_READING_VALUE => $queryBuilder
                ->orderBy('reading.readingValue', $dqlDirection)
                ->addOrderBy('reading.takenOn', 'DESC')
                ->addOrderBy('account.number', 'ASC'),
            self::SORT_SUBMITTED_AT => $queryBuilder
                ->orderBy('reading.submittedAt', $dqlDirection)
                ->addOrderBy('reading.takenOn', 'DESC')
                ->addOrderBy('account.number', 'ASC'),
            self::SORT_SOURCE => $queryBuilder
                ->orderBy('reading.source', $dqlDirection)
                ->addOrderBy('reading.takenOn', 'DESC')
                ->addOrderBy('account.number', 'ASC'),
            default => $queryBuilder
                ->orderBy('reading.takenOn', $dqlDirection)
                ->addOrderBy('tariffZone.sortOrder', 'ASC')
                ->addOrderBy('reading.submittedAt', $dqlDirection)
                ->addOrderBy('account.number', 'ASC'),
        };
    }

    /**
     * @return list<ElectricityMeterReading>
     */
    public function findLatestByWorkspace(Workspace $workspace, int $limit = 5): array
    {
        return $this->createQueryBuilder('reading')
            ->addSelect('electricityMeter', 'account', 'tariffZone')
            ->innerJoin('reading.electricityMeter', 'electricityMeter')
            ->innerJoin('electricityMeter.account', 'account')
            ->innerJoin('reading.tariffZone', 'tariffZone')
            ->andWhere('reading.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('reading.takenOn', 'DESC')
            ->addOrderBy('reading.submittedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ElectricityMeterReading>
     */
    public function findByMeter(Workspace $workspace, ElectricityMeter $electricityMeter): array
    {
        return $this->createQueryBuilder('reading')
            ->addSelect('tariffZone')
            ->innerJoin('reading.tariffZone', 'tariffZone')
            ->andWhere('reading.workspace = :workspace')
            ->andWhere('reading.electricityMeter = :electricityMeter')
            ->setParameter('workspace', $workspace)
            ->setParameter('electricityMeter', $electricityMeter)
            ->orderBy('reading.takenOn', 'DESC')
            ->addOrderBy('tariffZone.sortOrder', 'ASC')
            ->addOrderBy('reading.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ElectricityMeterReading>
     */
    public function findByAccount(Workspace $workspace, Account $account): array
    {
        return $this->createByAccountForPortalListQuery($workspace, $account)
            ->getQuery()
            ->getResult();
    }

    public function createByAccountForPortalListQuery(
        Workspace $workspace,
        Account $account,
        string $statusFilter = self::STATUS_FILTER_ALL,
        ?DateTimeImmutable $takenOnFrom = null,
        ?DateTimeImmutable $takenOnTo = null,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('reading')
            ->addSelect('electricityMeter', 'tariffZone')
            ->innerJoin('reading.electricityMeter', 'electricityMeter')
            ->innerJoin('reading.tariffZone', 'tariffZone')
            ->andWhere('reading.workspace = :workspace')
            ->andWhere('electricityMeter.account = :account')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('reading.takenOn', 'DESC')
            ->addOrderBy('tariffZone.sortOrder', 'ASC')
            ->addOrderBy('reading.submittedAt', 'DESC');

        if ($statusFilter === self::STATUS_FILTER_ACTIVE) {
            $queryBuilder
                ->andWhere('reading.cancelledAt IS NULL')
                ->andWhere('reading.replacingReading IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_CANCELLED) {
            $queryBuilder->andWhere('reading.cancelledAt IS NOT NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_SUPERSEDED) {
            $queryBuilder->andWhere('reading.replacingReading IS NOT NULL');
        }

        if ($takenOnFrom instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('reading.takenOn >= :accountTakenOnFrom')
                ->setParameter('accountTakenOnFrom', $takenOnFrom);
        }

        if ($takenOnTo instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('reading.takenOn <= :accountTakenOnTo')
                ->setParameter('accountTakenOnTo', $takenOnTo);
        }

        return $queryBuilder;
    }

    public function findOneByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?ElectricityMeterReading
    {
        return $this->createQueryBuilder('reading')
            ->addSelect('electricityMeter', 'account', 'tariffZone')
            ->innerJoin('reading.electricityMeter', 'electricityMeter')
            ->innerJoin('electricityMeter.account', 'account')
            ->innerJoin('reading.tariffZone', 'tariffZone')
            ->andWhere('reading.workspace = :workspace')
            ->andWhere('reading.uuid = :uuid')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByMeterZoneAndTakenOn(
        Workspace $workspace,
        ElectricityMeter $electricityMeter,
        ElectricityTariffZone $tariffZone,
        DateTimeImmutable $takenOn,
    ): ?ElectricityMeterReading {
        return $this->createQueryBuilder('reading')
            ->andWhere('reading.workspace = :workspace')
            ->andWhere('reading.electricityMeter = :electricityMeter')
            ->andWhere('reading.tariffZone = :tariffZone')
            ->andWhere('reading.takenOn = :takenOn')
            ->andWhere('reading.cancelledAt IS NULL')
            ->andWhere('reading.replacingReading IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('electricityMeter', $electricityMeter)
            ->setParameter('tariffZone', $tariffZone)
            ->setParameter('takenOn', $takenOn)
            ->orderBy('reading.submittedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneSupersededByReplacement(
        Workspace $workspace,
        ElectricityMeterReading $replacement,
    ): ?ElectricityMeterReading {
        return $this->createQueryBuilder('reading')
            ->andWhere('reading.workspace = :workspace')
            ->andWhere('reading.replacingReading = :replacement')
            ->setParameter('workspace', $workspace)
            ->setParameter('replacement', $replacement)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findLatestActiveBeforeOrOn(
        Workspace $workspace,
        ElectricityMeter $electricityMeter,
        ElectricityTariffZone $tariffZone,
        DateTimeImmutable $takenOn,
    ): ?ElectricityMeterReading {
        return $this->createQueryBuilder('reading')
            ->andWhere('reading.workspace = :workspace')
            ->andWhere('reading.electricityMeter = :electricityMeter')
            ->andWhere('reading.tariffZone = :tariffZone')
            ->andWhere('reading.takenOn <= :takenOn')
            ->andWhere('reading.cancelledAt IS NULL')
            ->andWhere('reading.replacingReading IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('electricityMeter', $electricityMeter)
            ->setParameter('tariffZone', $tariffZone)
            ->setParameter('takenOn', $takenOn)
            ->orderBy('reading.takenOn', 'DESC')
            ->addOrderBy('reading.submittedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findEarliestActiveAfter(
        Workspace $workspace,
        ElectricityMeter $electricityMeter,
        ElectricityTariffZone $tariffZone,
        DateTimeImmutable $takenOn,
    ): ?ElectricityMeterReading {
        return $this->createQueryBuilder('reading')
            ->andWhere('reading.workspace = :workspace')
            ->andWhere('reading.electricityMeter = :electricityMeter')
            ->andWhere('reading.tariffZone = :tariffZone')
            ->andWhere('reading.takenOn > :takenOn')
            ->andWhere('reading.cancelledAt IS NULL')
            ->andWhere('reading.replacingReading IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('electricityMeter', $electricityMeter)
            ->setParameter('tariffZone', $tariffZone)
            ->setParameter('takenOn', $takenOn)
            ->orderBy('reading.takenOn', 'ASC')
            ->addOrderBy('reading.submittedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array<string, ElectricityMeterReading>
     */
    public function findLatestActiveIndexedByTariffZone(Workspace $workspace, ElectricityMeter $electricityMeter): array
    {
        $readings = $this->createQueryBuilder('reading')
            ->addSelect('tariffZone')
            ->innerJoin('reading.tariffZone', 'tariffZone')
            ->andWhere('reading.workspace = :workspace')
            ->andWhere('reading.electricityMeter = :electricityMeter')
            ->andWhere('reading.cancelledAt IS NULL')
            ->andWhere('reading.replacingReading IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('electricityMeter', $electricityMeter)
            ->orderBy('reading.takenOn', 'DESC')
            ->addOrderBy('reading.submittedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $indexedReadings = [];

        foreach ($readings as $reading) {
            if (!$reading instanceof ElectricityMeterReading || !$reading->getTariffZone() instanceof ElectricityTariffZone) {
                continue;
            }

            $tariffZoneUuid = $reading->getTariffZone()->getUuid()->toRfc4122();
            $indexedReadings[$tariffZoneUuid] ??= $reading;
        }

        return $indexedReadings;
    }
}
