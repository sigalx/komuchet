<?php

namespace App\Repository;

use App\Entity\Accrual;
use App\Entity\BillingRun;
use App\Entity\BillingRunAccountIssue;
use App\Entity\Workspace;
use App\Enum\BillingRunKind;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BillingRun>
 */
class BillingRunRepository extends ServiceEntityRepository
{
    public const STATUS_FILTER_ALL = 'all';
    public const STATUS_FILTER_DRAFT = 'draft';
    public const STATUS_FILTER_POSTED = 'posted';
    public const STATUS_FILTER_CANCELLED = 'cancelled';

    public const ISSUE_FILTER_ALL = 'all';
    public const ISSUE_FILTER_WITH_OPEN = 'with_open';
    public const ISSUE_FILTER_WITHOUT_OPEN = 'without_open';

    public const ACCRUAL_FILTER_ALL = 'all';
    public const ACCRUAL_FILTER_WITH = 'with';
    public const ACCRUAL_FILTER_WITHOUT = 'without';

    public const SORT_PERIOD_START = 'period_start';
    public const SORT_KIND = 'kind';
    public const SORT_GENERATED_AT = 'generated_at';
    public const SORT_POSTED_AT = 'posted_at';
    public const SORT_CANCELLED_AT = 'cancelled_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingRun::class);
    }

    /**
     * @return list<BillingRun>
     */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('billingRun')
            ->andWhere('billingRun.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('billingRun.periodStart', 'DESC')
            ->addOrderBy('billingRun.generatedAt', 'DESC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<BillingRun>
     */
    public function findByWorkspaceForAdminList(
        Workspace $workspace,
        ?BillingRunKind $kind = null,
        string $statusFilter = self::STATUS_FILTER_ALL,
        string $issueFilter = self::ISSUE_FILTER_ALL,
        string $accrualFilter = self::ACCRUAL_FILTER_ALL,
        ?DateTimeImmutable $periodStartFrom = null,
        ?DateTimeImmutable $periodStartTo = null,
        ?DateTimeImmutable $generatedAtFrom = null,
        ?DateTimeImmutable $generatedAtBefore = null,
        string $sort = self::SORT_PERIOD_START,
        string $direction = self::SORT_DESC,
    ): array {
        return $this->createByWorkspaceForAdminListQuery($workspace, $kind, $statusFilter, $issueFilter, $accrualFilter, $periodStartFrom, $periodStartTo, $generatedAtFrom, $generatedAtBefore, $sort, $direction)
            ->getQuery()
            ->getResult();
    }

    public function createByWorkspaceForAdminListQuery(
        Workspace $workspace,
        ?BillingRunKind $kind = null,
        string $statusFilter = self::STATUS_FILTER_ALL,
        string $issueFilter = self::ISSUE_FILTER_ALL,
        string $accrualFilter = self::ACCRUAL_FILTER_ALL,
        ?DateTimeImmutable $periodStartFrom = null,
        ?DateTimeImmutable $periodStartTo = null,
        ?DateTimeImmutable $generatedAtFrom = null,
        ?DateTimeImmutable $generatedAtBefore = null,
        string $sort = self::SORT_PERIOD_START,
        string $direction = self::SORT_DESC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('billingRun')
            ->andWhere('billingRun.workspace = :workspace')
            ->setParameter('workspace', $workspace)
        ;

        if ($kind instanceof BillingRunKind) {
            $queryBuilder
                ->andWhere('billingRun.kind = :kind')
                ->setParameter('kind', $kind->value);
        }

        if ($statusFilter === self::STATUS_FILTER_DRAFT) {
            $queryBuilder
                ->andWhere('billingRun.postedAt IS NULL')
                ->andWhere('billingRun.cancelledAt IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_POSTED) {
            $queryBuilder
                ->andWhere('billingRun.postedAt IS NOT NULL')
                ->andWhere('billingRun.cancelledAt IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_CANCELLED) {
            $queryBuilder->andWhere('billingRun.cancelledAt IS NOT NULL');
        }

        if ($issueFilter === self::ISSUE_FILTER_WITH_OPEN) {
            $queryBuilder->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s issue WHERE issue.billingRun = billingRun AND issue.workspace = :workspace AND issue.closedAt IS NULL)',
                BillingRunAccountIssue::class,
            ));
        } elseif ($issueFilter === self::ISSUE_FILTER_WITHOUT_OPEN) {
            $queryBuilder->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s issue WHERE issue.billingRun = billingRun AND issue.workspace = :workspace AND issue.closedAt IS NULL)',
                BillingRunAccountIssue::class,
            ));
        }

        if ($accrualFilter === self::ACCRUAL_FILTER_WITH) {
            $queryBuilder->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s accrual WHERE accrual.billingRun = billingRun AND accrual.workspace = :workspace)',
                Accrual::class,
            ));
        } elseif ($accrualFilter === self::ACCRUAL_FILTER_WITHOUT) {
            $queryBuilder->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s accrual WHERE accrual.billingRun = billingRun AND accrual.workspace = :workspace)',
                Accrual::class,
            ));
        }

        if ($periodStartFrom instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('billingRun.periodStart >= :periodStartFrom')
                ->setParameter('periodStartFrom', $periodStartFrom);
        }

        if ($periodStartTo instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('billingRun.periodStart <= :periodStartTo')
                ->setParameter('periodStartTo', $periodStartTo);
        }

        if ($generatedAtFrom instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('billingRun.generatedAt >= :generatedAtFrom')
                ->setParameter('generatedAtFrom', $generatedAtFrom);
        }

        if ($generatedAtBefore instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('billingRun.generatedAt < :generatedAtBefore')
                ->setParameter('generatedAtBefore', $generatedAtBefore);
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeStatusFilter(string $statusFilter): string
    {
        return in_array($statusFilter, [
            self::STATUS_FILTER_ALL,
            self::STATUS_FILTER_DRAFT,
            self::STATUS_FILTER_POSTED,
            self::STATUS_FILTER_CANCELLED,
        ], true) ? $statusFilter : self::STATUS_FILTER_ALL;
    }

    public function normalizeIssueFilter(string $issueFilter): string
    {
        return in_array($issueFilter, [
            self::ISSUE_FILTER_ALL,
            self::ISSUE_FILTER_WITH_OPEN,
            self::ISSUE_FILTER_WITHOUT_OPEN,
        ], true) ? $issueFilter : self::ISSUE_FILTER_ALL;
    }

    public function normalizeAccrualFilter(string $accrualFilter): string
    {
        return in_array($accrualFilter, [
            self::ACCRUAL_FILTER_ALL,
            self::ACCRUAL_FILTER_WITH,
            self::ACCRUAL_FILTER_WITHOUT,
        ], true) ? $accrualFilter : self::ACCRUAL_FILTER_ALL;
    }

    public function normalizeKindFilter(string $kindFilter): ?BillingRunKind
    {
        return BillingRunKind::tryFrom($kindFilter);
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_PERIOD_START,
            self::SORT_KIND,
            self::SORT_GENERATED_AT,
            self::SORT_POSTED_AT,
            self::SORT_CANCELLED_AT,
        ], true) ? $sort : self::SORT_PERIOD_START;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_ASC ? self::SORT_ASC : self::SORT_DESC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_ASC ? 'ASC' : 'DESC';

        match ($sort) {
            self::SORT_KIND => $queryBuilder
                ->orderBy('billingRun.kind', $dqlDirection)
                ->addOrderBy('billingRun.periodStart', 'DESC')
                ->addOrderBy('billingRun.generatedAt', 'DESC'),
            self::SORT_GENERATED_AT => $queryBuilder
                ->orderBy('billingRun.generatedAt', $dqlDirection)
                ->addOrderBy('billingRun.periodStart', 'DESC'),
            self::SORT_POSTED_AT => $queryBuilder
                ->orderBy('billingRun.postedAt', $dqlDirection)
                ->addOrderBy('billingRun.periodStart', 'DESC')
                ->addOrderBy('billingRun.generatedAt', 'DESC'),
            self::SORT_CANCELLED_AT => $queryBuilder
                ->orderBy('billingRun.cancelledAt', $dqlDirection)
                ->addOrderBy('billingRun.periodStart', 'DESC')
                ->addOrderBy('billingRun.generatedAt', 'DESC'),
            default => $queryBuilder
                ->orderBy('billingRun.periodStart', $dqlDirection)
                ->addOrderBy('billingRun.generatedAt', $dqlDirection),
        };
    }

    public function findOneByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?BillingRun
    {
        return $this->createQueryBuilder('billingRun')
            ->andWhere('billingRun.workspace = :workspace')
            ->andWhere('billingRun.uuid = :uuid')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByKindAndPeriod(
        Workspace $workspace,
        BillingRunKind $kind,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): ?BillingRun {
        return $this->createQueryBuilder('billingRun')
            ->andWhere('billingRun.workspace = :workspace')
            ->andWhere('billingRun.kind = :kind')
            ->andWhere('billingRun.periodStart = :periodStart')
            ->andWhere('billingRun.periodEnd = :periodEnd')
            ->andWhere('billingRun.cancelledAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('kind', $kind->value)
            ->setParameter('periodStart', $periodStart)
            ->setParameter('periodEnd', $periodEnd)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
