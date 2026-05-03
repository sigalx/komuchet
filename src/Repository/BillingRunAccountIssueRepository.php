<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\BillingRun;
use App\Entity\BillingRunAccountIssue;
use App\Entity\Workspace;
use App\Enum\BillingRunAccountIssueCloseReason;
use App\Enum\BillingRunAccountIssueType;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<BillingRunAccountIssue>
 */
class BillingRunAccountIssueRepository extends ServiceEntityRepository
{
    public const SORT_BILLING_RUN_PERIOD = 'billing_run_period';
    public const SORT_ACCOUNT_NUMBER = 'account_number';
    public const SORT_ISSUE_TYPE = 'issue_type';
    public const SORT_UPDATED_AT = 'updated_at';
    public const SORT_CREATED_AT = 'created_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BillingRunAccountIssue::class);
    }

    /**
     * @return list<BillingRunAccountIssue>
     */
    public function findByBillingRun(Workspace $workspace, BillingRun $billingRun): array
    {
        return $this->createQueryBuilder('issue')
            ->addSelect('account')
            ->innerJoin('issue.account', 'account')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.billingRun = :billingRun')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->orderBy('account.number', 'ASC')
            ->addOrderBy('issue.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<BillingRunAccountIssue>
     */
    public function findOpenByBillingRun(Workspace $workspace, BillingRun $billingRun): array
    {
        return $this->createQueryBuilder('issue')
            ->addSelect('account')
            ->innerJoin('issue.account', 'account')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.billingRun = :billingRun')
            ->andWhere('issue.closedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->orderBy('account.number', 'ASC')
            ->addOrderBy('issue.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<BillingRunAccountIssue>
     */
    public function findOpenByWorkspace(
        Workspace $workspace,
        ?BillingRun $billingRun = null,
        ?Account $account = null,
        ?BillingRunAccountIssueType $issueType = null,
        string $sort = self::SORT_BILLING_RUN_PERIOD,
        string $direction = self::SORT_DESC,
    ): array
    {
        return $this->createOpenByWorkspaceQuery($workspace, $billingRun, $account, $issueType, $sort, $direction)
            ->getQuery()
            ->getResult();
    }

    public function createOpenByWorkspaceQuery(
        Workspace $workspace,
        ?BillingRun $billingRun = null,
        ?Account $account = null,
        ?BillingRunAccountIssueType $issueType = null,
        string $sort = self::SORT_BILLING_RUN_PERIOD,
        string $direction = self::SORT_DESC,
    ): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('issue')
            ->addSelect('account', 'billingRun')
            ->innerJoin('issue.account', 'account')
            ->innerJoin('issue.billingRun', 'billingRun')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.closedAt IS NULL')
            ->setParameter('workspace', $workspace);

        if ($billingRun instanceof BillingRun) {
            $queryBuilder
                ->andWhere('issue.billingRun = :billingRun')
                ->setParameter('billingRun', $billingRun);
        }

        if ($account instanceof Account) {
            $queryBuilder
                ->andWhere('issue.account = :account')
                ->setParameter('account', $account);
        }

        if ($issueType instanceof BillingRunAccountIssueType) {
            $queryBuilder
                ->andWhere('issue.issueType = :issueType')
                ->setParameter('issueType', $issueType->value);
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_BILLING_RUN_PERIOD,
            self::SORT_ACCOUNT_NUMBER,
            self::SORT_ISSUE_TYPE,
            self::SORT_UPDATED_AT,
            self::SORT_CREATED_AT,
        ], true) ? $sort : self::SORT_BILLING_RUN_PERIOD;
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
                ->addOrderBy('billingRun.periodStart', 'DESC')
                ->addOrderBy('issue.createdAt', 'ASC'),
            self::SORT_ISSUE_TYPE => $queryBuilder
                ->orderBy('issue.issueType', $dqlDirection)
                ->addOrderBy('billingRun.periodStart', 'DESC')
                ->addOrderBy('account.number', 'ASC'),
            self::SORT_UPDATED_AT => $queryBuilder
                ->orderBy('issue.updatedAt', $dqlDirection)
                ->addOrderBy('billingRun.periodStart', 'DESC')
                ->addOrderBy('account.number', 'ASC'),
            self::SORT_CREATED_AT => $queryBuilder
                ->orderBy('issue.createdAt', $dqlDirection)
                ->addOrderBy('billingRun.periodStart', 'DESC')
                ->addOrderBy('account.number', 'ASC'),
            default => $queryBuilder
                ->orderBy('billingRun.periodStart', $dqlDirection)
                ->addOrderBy('billingRun.generatedAt', $dqlDirection)
                ->addOrderBy('account.number', 'ASC')
                ->addOrderBy('issue.createdAt', 'ASC'),
        };
    }

    public function countOpenByBillingRun(Workspace $workspace, BillingRun $billingRun): int
    {
        return (int) $this->createQueryBuilder('issue')
            ->select('COUNT(issue.uuid)')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.billingRun = :billingRun')
            ->andWhere('issue.closedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOpenByWorkspace(Workspace $workspace): int
    {
        return (int) $this->createQueryBuilder('issue')
            ->select('COUNT(issue.uuid)')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.closedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<BillingRunAccountIssue>
     */
    public function findRecentOpenByWorkspace(Workspace $workspace, int $limit = 5): array
    {
        return $this->createQueryBuilder('issue')
            ->addSelect('account', 'billingRun')
            ->innerJoin('issue.account', 'account')
            ->innerJoin('issue.billingRun', 'billingRun')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.closedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->orderBy('issue.updatedAt', 'DESC')
            ->addOrderBy('issue.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<BillingRun> $billingRuns
     *
     * @return array<string, int>
     */
    public function countOpenByBillingRuns(Workspace $workspace, array $billingRuns): array
    {
        if ($billingRuns === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('issue')
            ->select('billingRun.uuid AS billing_run_uuid')
            ->addSelect('COUNT(issue.uuid) AS issue_count')
            ->innerJoin('issue.billingRun', 'billingRun')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.billingRun IN (:billingRuns)')
            ->andWhere('issue.closedAt IS NULL')
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

            $counts[(string) $billingRunUuid] = (int) $row['issue_count'];
        }

        return $counts;
    }

    public function findLatestUpdatedAtByBillingRun(Workspace $workspace, BillingRun $billingRun): ?DateTimeImmutable
    {
        $value = $this->createQueryBuilder('issue')
            ->select('MAX(issue.updatedAt)')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.billingRun = :billingRun')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->getQuery()
            ->getSingleScalarResult();

        return $this->dateTimeOrNull($value);
    }

    /**
     * @param list<BillingRun> $billingRuns
     *
     * @return array<string, DateTimeImmutable>
     */
    public function findLatestUpdatedAtByBillingRuns(Workspace $workspace, array $billingRuns): array
    {
        if ($billingRuns === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('issue')
            ->select('billingRun.uuid AS billing_run_uuid')
            ->addSelect('MAX(issue.updatedAt) AS latest_updated_at')
            ->innerJoin('issue.billingRun', 'billingRun')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.billingRun IN (:billingRuns)')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRuns', $billingRuns)
            ->groupBy('billingRun.uuid')
            ->getQuery()
            ->getArrayResult();

        $latestByRun = [];

        foreach ($rows as $row) {
            $billingRunUuid = $row['billing_run_uuid'];
            $latestUpdatedAt = $this->dateTimeOrNull($row['latest_updated_at']);

            if ($latestUpdatedAt === null) {
                continue;
            }

            if ($billingRunUuid instanceof Uuid) {
                $billingRunUuid = $billingRunUuid->toRfc4122();
            }

            $latestByRun[(string) $billingRunUuid] = $latestUpdatedAt;
        }

        return $latestByRun;
    }

    public function findOneByBillingRunAndUuid(
        Workspace $workspace,
        BillingRun $billingRun,
        Uuid $uuid,
    ): ?BillingRunAccountIssue {
        return $this->createQueryBuilder('issue')
            ->addSelect('account')
            ->innerJoin('issue.account', 'account')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.billingRun = :billingRun')
            ->andWhere('issue.uuid = :uuid')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?BillingRunAccountIssue
    {
        return $this->createQueryBuilder('issue')
            ->addSelect('account', 'billingRun')
            ->innerJoin('issue.account', 'account')
            ->innerJoin('issue.billingRun', 'billingRun')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.uuid = :uuid')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneOpenByBillingRunAccountAndType(
        Workspace $workspace,
        BillingRun $billingRun,
        Account $account,
        BillingRunAccountIssueType $issueType,
    ): ?BillingRunAccountIssue {
        return $this->createQueryBuilder('issue')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.billingRun = :billingRun')
            ->andWhere('issue.account = :account')
            ->andWhere('issue.issueType = :issueType')
            ->andWhere('issue.closedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->setParameter('account', $account)
            ->setParameter('issueType', $issueType)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function hasClosedIgnoredIssue(
        Workspace $workspace,
        BillingRun $billingRun,
        Account $account,
        BillingRunAccountIssueType $issueType,
    ): bool {
        $count = $this->createQueryBuilder('issue')
            ->select('COUNT(issue.uuid)')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.billingRun = :billingRun')
            ->andWhere('issue.account = :account')
            ->andWhere('issue.issueType = :issueType')
            ->andWhere('issue.closedAt IS NOT NULL')
            ->andWhere('issue.closeReason = :closeReason')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->setParameter('account', $account)
            ->setParameter('issueType', $issueType)
            ->setParameter('closeReason', BillingRunAccountIssueCloseReason::Ignored)
            ->getQuery()
            ->getSingleScalarResult();

        return 0 < (int) $count;
    }

    public function hasOpenByBillingRunAccount(
        Workspace $workspace,
        BillingRun $billingRun,
        Account $account,
    ): bool {
        $count = $this->createQueryBuilder('issue')
            ->select('COUNT(issue.uuid)')
            ->andWhere('issue.workspace = :workspace')
            ->andWhere('issue.billingRun = :billingRun')
            ->andWhere('issue.account = :account')
            ->andWhere('issue.closedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('billingRun', $billingRun)
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();

        return 0 < (int) $count;
    }

    private function dateTimeOrNull(mixed $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return new DateTimeImmutable((string) $value);
    }
}
