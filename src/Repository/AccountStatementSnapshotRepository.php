<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementSnapshot;
use App\Entity\BillingRun;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AccountStatementSnapshot>
 */
class AccountStatementSnapshotRepository extends ServiceEntityRepository
{
    public const STATUS_FILTER_ALL = 'all';
    public const STATUS_FILTER_ACTIVE = 'active';
    public const STATUS_FILTER_CANCELLED = 'cancelled';

    public const DELIVERY_FILTER_ALL = 'all';
    public const DELIVERY_FILTER_WITH = 'with';
    public const DELIVERY_FILTER_WITHOUT = 'without';

    public const SORT_GENERATED_AT = 'generated_at';
    public const SORT_ACCOUNT_NUMBER = 'account_number';
    public const SORT_NUMBER = 'number';
    public const SORT_BILLING_RUN_PERIOD = 'billing_run_period';
    public const SORT_AMOUNT_TO_PAY = 'amount_to_pay';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountStatementSnapshot::class);
    }

    public function createByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $search = '',
        ?BillingRun $billingRun = null,
        bool $withoutBillingRun = false,
        string $statusFilter = self::STATUS_FILTER_ALL,
        string $deliveryFilter = self::DELIVERY_FILTER_ALL,
        ?\DateTimeImmutable $generatedAtFrom = null,
        ?\DateTimeImmutable $generatedAtBefore = null,
        ?string $amountToPayFrom = null,
        ?string $amountToPayTo = null,
        string $sort = self::SORT_GENERATED_AT,
        string $direction = self::SORT_DESC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('statement')
            ->addSelect('account')
            ->addSelect('billingRun')
            ->innerJoin('statement.account', 'account')
            ->leftJoin('statement.billingRun', 'billingRun')
            ->andWhere('statement.workspace = :workspace')
            ->setParameter('workspace', $workspace);

        $search = trim($search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere('LOWER(statement.number) LIKE :search OR LOWER(statement.accountNumber) LIKE :search OR LOWER(account.number) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ($billingRun instanceof BillingRun) {
            $queryBuilder
                ->andWhere('statement.billingRun = :billingRun')
                ->setParameter('billingRun', $billingRun);
        } elseif ($withoutBillingRun) {
            $queryBuilder->andWhere('statement.billingRun IS NULL');
        }

        if ($statusFilter === self::STATUS_FILTER_ACTIVE) {
            $queryBuilder->andWhere('statement.cancelledAt IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_CANCELLED) {
            $queryBuilder->andWhere('statement.cancelledAt IS NOT NULL');
        }

        if ($deliveryFilter === self::DELIVERY_FILTER_WITH) {
            $queryBuilder->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s delivery WHERE delivery.accountStatement = statement AND delivery.workspace = :workspace)',
                AccountStatementDelivery::class,
            ));
        } elseif ($deliveryFilter === self::DELIVERY_FILTER_WITHOUT) {
            $queryBuilder->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s delivery WHERE delivery.accountStatement = statement AND delivery.workspace = :workspace)',
                AccountStatementDelivery::class,
            ));
        }

        if ($generatedAtFrom instanceof \DateTimeImmutable) {
            $queryBuilder
                ->andWhere('statement.generatedAt >= :generatedAtFrom')
                ->setParameter('generatedAtFrom', $generatedAtFrom);
        }

        if ($generatedAtBefore instanceof \DateTimeImmutable) {
            $queryBuilder
                ->andWhere('statement.generatedAt < :generatedAtBefore')
                ->setParameter('generatedAtBefore', $generatedAtBefore);
        }

        if ($amountToPayFrom !== null) {
            $queryBuilder
                ->andWhere('statement.amountToPay >= :amountToPayFrom')
                ->setParameter('amountToPayFrom', $amountToPayFrom);
        }

        if ($amountToPayTo !== null) {
            $queryBuilder
                ->andWhere('statement.amountToPay <= :amountToPayTo')
                ->setParameter('amountToPayTo', $amountToPayTo);
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_GENERATED_AT,
            self::SORT_ACCOUNT_NUMBER,
            self::SORT_NUMBER,
            self::SORT_BILLING_RUN_PERIOD,
            self::SORT_AMOUNT_TO_PAY,
        ], true) ? $sort : self::SORT_GENERATED_AT;
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
                ->orderBy('statement.accountNumber', $dqlDirection)
                ->addOrderBy('statement.generatedAt', 'DESC')
                ->addOrderBy('statement.number', 'DESC'),
            self::SORT_NUMBER => $queryBuilder
                ->orderBy('statement.number', $dqlDirection)
                ->addOrderBy('statement.generatedAt', 'DESC'),
            self::SORT_BILLING_RUN_PERIOD => $queryBuilder
                ->orderBy('billingRun.periodStart', $dqlDirection)
                ->addOrderBy('statement.accountNumber', 'ASC')
                ->addOrderBy('statement.generatedAt', 'DESC'),
            self::SORT_AMOUNT_TO_PAY => $queryBuilder
                ->orderBy('statement.amountToPay', $dqlDirection)
                ->addOrderBy('statement.generatedAt', 'DESC')
                ->addOrderBy('statement.number', 'DESC'),
            default => $queryBuilder
                ->orderBy('statement.generatedAt', $dqlDirection)
                ->addOrderBy('statement.number', $dqlDirection),
        };
    }

    public function normalizeStatusFilter(string $statusFilter): string
    {
        return in_array($statusFilter, array_keys(self::statusFilterChoices()), true)
            ? $statusFilter
            : self::STATUS_FILTER_ALL;
    }

    /**
     * @return array<string, string>
     */
    public static function statusFilterChoices(): array
    {
        return [
            self::STATUS_FILTER_ALL => 'Все квитанции',
            self::STATUS_FILTER_ACTIVE => 'Активные',
            self::STATUS_FILTER_CANCELLED => 'Отмененные',
        ];
    }

    public function normalizeDeliveryFilter(string $deliveryFilter): string
    {
        return in_array($deliveryFilter, array_keys(self::deliveryFilterChoices()), true)
            ? $deliveryFilter
            : self::DELIVERY_FILTER_ALL;
    }

    /**
     * @return array<string, string>
     */
    public static function deliveryFilterChoices(): array
    {
        return [
            self::DELIVERY_FILTER_ALL => 'Все квитанции',
            self::DELIVERY_FILTER_WITH => 'С доставками',
            self::DELIVERY_FILTER_WITHOUT => 'Без доставок',
        ];
    }

    public function findOneByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?AccountStatementSnapshot
    {
        return $this->createQueryBuilder('statement')
            ->andWhere('statement.workspace = :workspace')
            ->andWhere('statement.uuid = :uuid')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByBillingRunAndAccount(
        Workspace $workspace,
        BillingRun $billingRun,
        Account $account,
    ): ?AccountStatementSnapshot {
        return $this->createQueryBuilder('statement')
            ->andWhere('statement.workspace = :workspace')
            ->andWhere('statement.billingRun = :billingRun')
            ->andWhere('statement.account = :account')
            ->andWhere('statement.cancelledAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->setParameter('account', $account)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<AccountStatementSnapshot>
     */
    public function findByBillingRun(Workspace $workspace, BillingRun $billingRun): array
    {
        return $this->createQueryBuilder('statement')
            ->addSelect('account')
            ->innerJoin('statement.account', 'account')
            ->andWhere('statement.workspace = :workspace')
            ->andWhere('statement.billingRun = :billingRun')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->orderBy('account.number', 'ASC')
            ->addOrderBy('statement.generatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function countActiveByBillingRun(Workspace $workspace, BillingRun $billingRun): int
    {
        return (int) $this->createQueryBuilder('statement')
            ->select('COUNT(statement.uuid)')
            ->andWhere('statement.workspace = :workspace')
            ->andWhere('statement.billingRun = :billingRun')
            ->andWhere('statement.cancelledAt IS NULL')
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
    public function countActiveByBillingRuns(Workspace $workspace, array $billingRuns): array
    {
        if ($billingRuns === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('statement')
            ->select('billingRun.uuid AS billing_run_uuid')
            ->addSelect('COUNT(statement.uuid) AS statement_count')
            ->innerJoin('statement.billingRun', 'billingRun')
            ->andWhere('statement.workspace = :workspace')
            ->andWhere('statement.billingRun IN (:billingRuns)')
            ->andWhere('statement.cancelledAt IS NULL')
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

            $counts[(string) $billingRunUuid] = (int) $row['statement_count'];
        }

        return $counts;
    }

    /**
     * @return list<AccountStatementSnapshot>
     */
    public function findLatestByAccount(Workspace $workspace, Account $account, int $limit = 10): array
    {
        return $this->createQueryBuilder('statement')
            ->andWhere('statement.workspace = :workspace')
            ->andWhere('statement.account = :account')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('statement.generatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<AccountStatementSnapshot>
     */
    public function findLatestActiveByAccount(Workspace $workspace, Account $account, int $limit = 10): array
    {
        return $this->createQueryBuilder('statement')
            ->andWhere('statement.workspace = :workspace')
            ->andWhere('statement.account = :account')
            ->andWhere('statement.cancelledAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('statement.generatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function findOneActiveByWorkspaceAccountAndUuid(Workspace $workspace, Account $account, Uuid $uuid): ?AccountStatementSnapshot
    {
        return $this->createQueryBuilder('statement')
            ->andWhere('statement.workspace = :workspace')
            ->andWhere('statement.account = :account')
            ->andWhere('statement.uuid = :uuid')
            ->andWhere('statement.cancelledAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->setParameter('uuid', $uuid)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
