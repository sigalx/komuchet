<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Accrual;
use App\Entity\BillingRun;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Accrual>
 */
class AccrualRepository extends ServiceEntityRepository
{
    public const STATUS_FILTER_ALL = 'all';
    public const STATUS_FILTER_DRAFT = 'draft';
    public const STATUS_FILTER_POSTED = 'posted';
    public const STATUS_FILTER_CANCELLED = 'cancelled';
    public const STATUS_FILTER_SUPERSEDED = 'superseded';
    public const PORTAL_STATUS_FILTER_ACTIVE = 'active';

    public const SORT_PERIOD_START = 'period_start';
    public const SORT_ACCOUNT_NUMBER = 'account_number';
    public const SORT_TYPE = 'type';
    public const SORT_AMOUNT = 'amount';
    public const SORT_CREATED_AT = 'created_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Accrual::class);
    }

    public function sumActivePostedAmountByAccount(Workspace $workspace, Account $account): string
    {
        $amount = $this->createQueryBuilder('accrual')
            ->select('COALESCE(SUM(accrual.amount), 0)')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.account = :account')
            ->andWhere('accrual.postedAt IS NOT NULL')
            ->andWhere('accrual.cancelledAt IS NULL')
            ->andWhere('accrual.replacingAccrual IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $amount;
    }

    /**
     * @return list<Accrual>
     */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('accrual')
            ->addSelect('account')
            ->innerJoin('accrual.account', 'account')
            ->andWhere('accrual.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('accrual.periodStart', 'DESC')
            ->addOrderBy('accrual.createdAt', 'DESC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Accrual>
     */
    public function findByWorkspaceForAdminList(
        Workspace $workspace,
        string $search = '',
        ?AccrualType $type = null,
        string $statusFilter = self::STATUS_FILTER_ALL,
        ?DateTimeImmutable $periodStartFrom = null,
        ?DateTimeImmutable $periodStartTo = null,
        string $sort = self::SORT_PERIOD_START,
        string $direction = self::SORT_DESC,
    ): array {
        return $this->createByWorkspaceForAdminListQuery($workspace, $search, $type, $statusFilter, $periodStartFrom, $periodStartTo, $sort, $direction)
            ->getQuery()
            ->getResult();
    }

    public function createByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $search = '',
        ?AccrualType $type = null,
        string $statusFilter = self::STATUS_FILTER_ALL,
        ?DateTimeImmutable $periodStartFrom = null,
        ?DateTimeImmutable $periodStartTo = null,
        string $sort = self::SORT_PERIOD_START,
        string $direction = self::SORT_DESC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('accrual')
            ->addSelect('account')
            ->innerJoin('accrual.account', 'account')
            ->andWhere('accrual.workspace = :workspace')
            ->setParameter('workspace', $workspace);

        $search = trim($search);

        if ($search !== '') {
            $searchConditions = [
                'LOWER(account.number) LIKE :search',
                'LOWER(accrual.notes) LIKE :search',
                'LOWER(accrual.calculationVersion) LIKE :search',
            ];

            $searchAmount = $this->normalizeSearchAmount($search);

            if ($searchAmount !== null) {
                $searchConditions[] = 'accrual.amount = :searchAmount';
                $queryBuilder->setParameter('searchAmount', $searchAmount);
            }

            $queryBuilder
                ->andWhere('('.implode(' OR ', $searchConditions).')')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ($type instanceof AccrualType) {
            $queryBuilder
                ->andWhere('accrual.type = :type')
                ->setParameter('type', $type->value);
        }

        if ($statusFilter === self::STATUS_FILTER_DRAFT) {
            $queryBuilder
                ->andWhere('accrual.postedAt IS NULL')
                ->andWhere('accrual.cancelledAt IS NULL')
                ->andWhere('accrual.replacingAccrual IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_POSTED) {
            $queryBuilder
                ->andWhere('accrual.postedAt IS NOT NULL')
                ->andWhere('accrual.cancelledAt IS NULL')
                ->andWhere('accrual.replacingAccrual IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_CANCELLED) {
            $queryBuilder->andWhere('accrual.cancelledAt IS NOT NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_SUPERSEDED) {
            $queryBuilder->andWhere('accrual.replacingAccrual IS NOT NULL');
        }

        if ($periodStartFrom instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('accrual.periodStart >= :periodStartFrom')
                ->setParameter('periodStartFrom', $periodStartFrom);
        }

        if ($periodStartTo instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('accrual.periodStart <= :periodStartTo')
                ->setParameter('periodStartTo', $periodStartTo);
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_PERIOD_START,
            self::SORT_ACCOUNT_NUMBER,
            self::SORT_TYPE,
            self::SORT_AMOUNT,
            self::SORT_CREATED_AT,
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
            self::SORT_ACCOUNT_NUMBER => $queryBuilder
                ->orderBy('account.number', $dqlDirection)
                ->addOrderBy('accrual.periodStart', 'DESC')
                ->addOrderBy('accrual.createdAt', 'DESC'),
            self::SORT_TYPE => $queryBuilder
                ->orderBy('accrual.type', $dqlDirection)
                ->addOrderBy('accrual.periodStart', 'DESC')
                ->addOrderBy('account.number', 'ASC'),
            self::SORT_AMOUNT => $queryBuilder
                ->orderBy('accrual.amount', $dqlDirection)
                ->addOrderBy('accrual.periodStart', 'DESC')
                ->addOrderBy('account.number', 'ASC'),
            self::SORT_CREATED_AT => $queryBuilder
                ->orderBy('accrual.createdAt', $dqlDirection)
                ->addOrderBy('accrual.periodStart', 'DESC'),
            default => $queryBuilder
                ->orderBy('accrual.periodStart', $dqlDirection)
                ->addOrderBy('accrual.createdAt', $dqlDirection)
                ->addOrderBy('account.number', 'ASC'),
        };
    }

    public function normalizeStatusFilter(string $statusFilter): string
    {
        return in_array($statusFilter, [
            self::STATUS_FILTER_ALL,
            self::STATUS_FILTER_DRAFT,
            self::STATUS_FILTER_POSTED,
            self::STATUS_FILTER_CANCELLED,
            self::STATUS_FILTER_SUPERSEDED,
        ], true) ? $statusFilter : self::STATUS_FILTER_ALL;
    }

    public function normalizeTypeFilter(string $typeFilter): ?AccrualType
    {
        return AccrualType::tryFrom($typeFilter);
    }

    /**
     * @return list<Accrual>
     */
    public function findByAccount(Workspace $workspace, Account $account): array
    {
        return $this->createQueryBuilder('accrual')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.account = :account')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('accrual.periodStart', 'DESC')
            ->addOrderBy('accrual.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Accrual>
     */
    public function findPostedByAccount(Workspace $workspace, Account $account): array
    {
        return $this->createPostedByAccountForPortalListQuery($workspace, $account)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Accrual>
     */
    public function findActivePostedByAccount(Workspace $workspace, Account $account): array
    {
        return $this->createQueryBuilder('accrual')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.account = :account')
            ->andWhere('accrual.postedAt IS NOT NULL')
            ->andWhere('accrual.cancelledAt IS NULL')
            ->andWhere('accrual.replacingAccrual IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('accrual.periodStart', 'DESC')
            ->addOrderBy('accrual.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function createPostedByAccountForPortalListQuery(
        Workspace $workspace,
        Account $account,
        string $statusFilter = self::STATUS_FILTER_ALL,
        ?DateTimeImmutable $periodStartFrom = null,
        ?DateTimeImmutable $periodStartTo = null,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('accrual')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.account = :account')
            ->andWhere('accrual.postedAt IS NOT NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('accrual.periodStart', 'DESC')
            ->addOrderBy('accrual.createdAt', 'DESC');

        if ($statusFilter === self::PORTAL_STATUS_FILTER_ACTIVE) {
            $queryBuilder
                ->andWhere('accrual.cancelledAt IS NULL')
                ->andWhere('accrual.replacingAccrual IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_CANCELLED) {
            $queryBuilder->andWhere('accrual.cancelledAt IS NOT NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_SUPERSEDED) {
            $queryBuilder->andWhere('accrual.replacingAccrual IS NOT NULL');
        }

        if ($periodStartFrom instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('accrual.periodStart >= :accountPeriodStartFrom')
                ->setParameter('accountPeriodStartFrom', $periodStartFrom);
        }

        if ($periodStartTo instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('accrual.periodStart <= :accountPeriodStartTo')
                ->setParameter('accountPeriodStartTo', $periodStartTo);
        }

        return $queryBuilder;
    }

    public function normalizePortalStatusFilter(string $statusFilter): string
    {
        return in_array($statusFilter, [
            self::STATUS_FILTER_ALL,
            self::PORTAL_STATUS_FILTER_ACTIVE,
            self::STATUS_FILTER_CANCELLED,
            self::STATUS_FILTER_SUPERSEDED,
        ], true) ? $statusFilter : self::STATUS_FILTER_ALL;
    }

    /**
     * @return list<Accrual>
     */
    public function findByBillingRun(Workspace $workspace, BillingRun $billingRun): array
    {
        return $this->createQueryBuilder('accrual')
            ->addSelect('account')
            ->innerJoin('accrual.account', 'account')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.billingRun = :billingRun')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->orderBy('account.number', 'ASC')
            ->addOrderBy('accrual.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Account>
     */
    public function findActivePostedAccountsByBillingRun(Workspace $workspace, BillingRun $billingRun): array
    {
        $accruals = $this->createQueryBuilder('accrual')
            ->addSelect('account')
            ->innerJoin('accrual.account', 'account')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.billingRun = :billingRun')
            ->andWhere('accrual.postedAt IS NOT NULL')
            ->andWhere('accrual.cancelledAt IS NULL')
            ->andWhere('accrual.replacingAccrual IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->orderBy('account.number', 'ASC')
            ->getQuery()
            ->getResult();

        $accounts = [];

        foreach ($accruals as $accrual) {
            $account = $accrual->getAccount();

            if (!$account instanceof Account) {
                continue;
            }

            $accounts[$account->getUuid()->toRfc4122()] = $account;
        }

        return array_values($accounts);
    }

    public function countActivePostedAccountsByBillingRun(Workspace $workspace, BillingRun $billingRun): int
    {
        return (int) $this->createQueryBuilder('accrual')
            ->select('COUNT(DISTINCT account.uuid)')
            ->innerJoin('accrual.account', 'account')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.billingRun = :billingRun')
            ->andWhere('accrual.postedAt IS NOT NULL')
            ->andWhere('accrual.cancelledAt IS NULL')
            ->andWhere('accrual.replacingAccrual IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<BillingRun> $billingRuns
     *
     * @return array<string, int>
     */
    public function countActivePostedAccountsByBillingRuns(Workspace $workspace, array $billingRuns): array
    {
        if ($billingRuns === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('accrual')
            ->select('billingRun.uuid AS billing_run_uuid')
            ->addSelect('COUNT(DISTINCT account.uuid) AS account_count')
            ->innerJoin('accrual.billingRun', 'billingRun')
            ->innerJoin('accrual.account', 'account')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.billingRun IN (:billingRuns)')
            ->andWhere('accrual.postedAt IS NOT NULL')
            ->andWhere('accrual.cancelledAt IS NULL')
            ->andWhere('accrual.replacingAccrual IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRuns', $billingRuns)
            ->groupBy('billingRun.uuid')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $billingRunUuid = $row['billing_run_uuid'];

            if ($billingRunUuid instanceof Uuid) {
                $billingRunUuid = $billingRunUuid->toRfc4122();
            }

            $counts[(string) $billingRunUuid] = (int) $row['account_count'];
        }

        return $counts;
    }

    /**
     * @param list<BillingRun> $billingRuns
     *
     * @return array<string, int>
     */
    public function countByBillingRuns(Workspace $workspace, array $billingRuns): array
    {
        if ($billingRuns === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('accrual')
            ->select('billingRun.uuid AS billing_run_uuid')
            ->addSelect('COUNT(accrual.uuid) AS accrual_count')
            ->innerJoin('accrual.billingRun', 'billingRun')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.billingRun IN (:billingRuns)')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRuns', $billingRuns)
            ->groupBy('billingRun.uuid')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $billingRunUuid = $row['billing_run_uuid'];

            if ($billingRunUuid instanceof Uuid) {
                $billingRunUuid = $billingRunUuid->toRfc4122();
            }

            $counts[(string) $billingRunUuid] = (int) $row['accrual_count'];
        }

        return $counts;
    }

    public function findOneByBillingRunAndAccount(
        Workspace $workspace,
        BillingRun $billingRun,
        Account $account,
    ): ?Accrual {
        return $this->createQueryBuilder('accrual')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.billingRun = :billingRun')
            ->andWhere('accrual.account = :account')
            ->andWhere('accrual.cancelledAt IS NULL')
            ->andWhere('accrual.replacingAccrual IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->setParameter('account', $account)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?Accrual
    {
        return $this->createQueryBuilder('accrual')
            ->addSelect('account')
            ->innerJoin('accrual.account', 'account')
            ->andWhere('accrual.workspace = :workspace')
            ->andWhere('accrual.uuid = :uuid')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActivePostedByAccountTypeAndPeriod(
        Workspace $workspace,
        Account $account,
        AccrualType $type,
        \DateTimeImmutable $periodStart,
        \DateTimeImmutable $periodEnd,
    ): ?Accrual {
        $uuid = $this->getEntityManager()->getConnection()->fetchOne(
            <<<'SQL'
                SELECT uuid
                FROM accruals
                WHERE workspace_uuid = :workspace_uuid
                  AND account_uuid = :account_uuid
                  AND type = :type
                  AND period_start = :period_start
                  AND period_end = :period_end
                  AND posted_at IS NOT NULL
                  AND cancelled_at IS NULL
                  AND replacing_accrual_uuid IS NULL
                LIMIT 1
                SQL,
            [
                'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
                'account_uuid' => $account->getUuid()->toRfc4122(),
                'type' => $type->value,
                'period_start' => $periodStart->format('Y-m-d'),
                'period_end' => $periodEnd->format('Y-m-d'),
            ],
        );

        if ($uuid === false) {
            return null;
        }

        return $this->find(Uuid::fromString((string) $uuid));
    }

    private function normalizeSearchAmount(string $search): ?string
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($search));

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
            return null;
        }

        [$rubles, $kopecks] = array_pad(explode('.', $normalized, 2), 2, '0');

        return $rubles.'.'.str_pad($kopecks, 2, '0');
    }
}
