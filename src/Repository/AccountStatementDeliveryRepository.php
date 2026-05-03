<?php

namespace App\Repository;

use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementSnapshot;
use App\Entity\BillingRun;
use App\Entity\Workspace;
use App\Enum\AccountStatementDeliveryChannel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountStatementDelivery>
 */
class AccountStatementDeliveryRepository extends ServiceEntityRepository
{
    public const STATUS_FILTER_ALL = 'all';
    public const STATUS_FILTER_QUEUED = 'queued';
    public const STATUS_FILTER_SENDING = 'sending';
    public const STATUS_FILTER_SENT = 'sent';
    public const STATUS_FILTER_FAILED = 'failed';
    public const STATUS_FILTER_CANCELLED = 'cancelled';

    public const SORT_CREATED_AT = 'created_at';
    public const SORT_ACCOUNT_NUMBER = 'account_number';
    public const SORT_STATEMENT_NUMBER = 'statement_number';
    public const SORT_RECIPIENT = 'recipient';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountStatementDelivery::class);
    }

    public function createByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $search = '',
        string $statusFilter = self::STATUS_FILTER_ALL,
        string $sort = self::SORT_CREATED_AT,
        string $direction = self::SORT_DESC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('delivery')
            ->addSelect('attempt')
            ->addSelect('subscriber')
            ->addSelect('statement')
            ->addSelect('account')
            ->addSelect('billingRun')
            ->innerJoin('delivery.accountStatement', 'statement')
            ->innerJoin('statement.account', 'account')
            ->leftJoin('statement.billingRun', 'billingRun')
            ->leftJoin('delivery.attempts', 'attempt')
            ->leftJoin('delivery.recipientSubscriber', 'subscriber')
            ->andWhere('delivery.workspace = :workspace')
            ->setParameter('workspace', $workspace);

        $search = trim($search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere('LOWER(statement.number) LIKE :search OR LOWER(account.number) LIKE :search OR LOWER(delivery.recipientEmail) LIKE :search OR LOWER(delivery.recipientName) LIKE :search')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ($statusFilter === self::STATUS_FILTER_CANCELLED) {
            $queryBuilder->andWhere('delivery.cancelledAt IS NOT NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_QUEUED) {
            $queryBuilder
                ->andWhere('delivery.cancelledAt IS NULL')
                ->andWhere('attempt.startedAt IS NULL')
                ->andWhere('attempt.succeededAt IS NULL')
                ->andWhere('attempt.failedAt IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_SENDING) {
            $queryBuilder
                ->andWhere('delivery.cancelledAt IS NULL')
                ->andWhere('attempt.startedAt IS NOT NULL')
                ->andWhere('attempt.succeededAt IS NULL')
                ->andWhere('attempt.failedAt IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_SENT) {
            $queryBuilder
                ->andWhere('delivery.cancelledAt IS NULL')
                ->andWhere('attempt.succeededAt IS NOT NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_FAILED) {
            $queryBuilder
                ->andWhere('delivery.cancelledAt IS NULL')
                ->andWhere('attempt.failedAt IS NOT NULL');
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_CREATED_AT,
            self::SORT_ACCOUNT_NUMBER,
            self::SORT_STATEMENT_NUMBER,
            self::SORT_RECIPIENT,
        ], true) ? $sort : self::SORT_CREATED_AT;
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
                ->addOrderBy('delivery.createdAt', 'DESC')
                ->addOrderBy('attempt.attemptNumber', 'DESC'),
            self::SORT_STATEMENT_NUMBER => $queryBuilder
                ->orderBy('statement.number', $dqlDirection)
                ->addOrderBy('delivery.createdAt', 'DESC')
                ->addOrderBy('attempt.attemptNumber', 'DESC'),
            self::SORT_RECIPIENT => $queryBuilder
                ->orderBy('delivery.recipientName', $dqlDirection)
                ->addOrderBy('delivery.recipientEmail', $dqlDirection)
                ->addOrderBy('delivery.createdAt', 'DESC')
                ->addOrderBy('attempt.attemptNumber', 'DESC'),
            default => $queryBuilder
                ->orderBy('delivery.createdAt', $dqlDirection)
                ->addOrderBy('attempt.attemptNumber', 'DESC'),
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
            self::STATUS_FILTER_ALL => 'Все доставки',
            self::STATUS_FILTER_QUEUED => 'В очереди',
            self::STATUS_FILTER_SENDING => 'Отправляется',
            self::STATUS_FILTER_SENT => 'Отправлено',
            self::STATUS_FILTER_FAILED => 'Ошибка',
            self::STATUS_FILTER_CANCELLED => 'Отменено',
        ];
    }

    /**
     * @return array{active_total: int, queued: int, sending: int, sent: int, failed: int}
     */
    public function summarizeActiveByWorkspace(Workspace $workspace): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(<<<'SQL'
            WITH latest_attempt AS (
                SELECT DISTINCT ON (delivery_uuid)
                    delivery_uuid,
                    started_at,
                    succeeded_at,
                    failed_at
                FROM account_statement_delivery_attempts
                WHERE workspace_uuid = :workspace_uuid
                ORDER BY delivery_uuid, attempt_number DESC
            )
            SELECT
                COUNT(*) FILTER (WHERE delivery.cancelled_at IS NULL) AS active_total,
                COUNT(*) FILTER (
                    WHERE delivery.cancelled_at IS NULL
                      AND latest_attempt.delivery_uuid IS NOT NULL
                      AND latest_attempt.started_at IS NULL
                      AND latest_attempt.succeeded_at IS NULL
                      AND latest_attempt.failed_at IS NULL
                ) AS queued,
                COUNT(*) FILTER (
                    WHERE delivery.cancelled_at IS NULL
                      AND latest_attempt.started_at IS NOT NULL
                      AND latest_attempt.succeeded_at IS NULL
                      AND latest_attempt.failed_at IS NULL
                ) AS sending,
                COUNT(*) FILTER (
                    WHERE delivery.cancelled_at IS NULL
                      AND latest_attempt.succeeded_at IS NOT NULL
                ) AS sent,
                COUNT(*) FILTER (
                    WHERE delivery.cancelled_at IS NULL
                      AND latest_attempt.failed_at IS NOT NULL
                ) AS failed
            FROM account_statement_deliveries delivery
            LEFT JOIN latest_attempt ON latest_attempt.delivery_uuid = delivery.uuid
            WHERE delivery.workspace_uuid = :workspace_uuid
            SQL, [
            'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
        ]);

        return [
            'active_total' => (int) ($row['active_total'] ?? 0),
            'queued' => (int) ($row['queued'] ?? 0),
            'sending' => (int) ($row['sending'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    /**
     * @return array{active_total: int, queued: int, sending: int, sent: int, failed: int}
     */
    public function summarizeActiveByBillingRun(Workspace $workspace, BillingRun $billingRun): array
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative(<<<'SQL'
            WITH latest_attempt AS (
                SELECT DISTINCT ON (attempt.delivery_uuid)
                    attempt.delivery_uuid,
                    attempt.started_at,
                    attempt.succeeded_at,
                    attempt.failed_at
                FROM account_statement_delivery_attempts attempt
                INNER JOIN account_statement_deliveries delivery ON delivery.uuid = attempt.delivery_uuid
                INNER JOIN account_statements statement ON statement.uuid = delivery.account_statement_uuid
                WHERE attempt.workspace_uuid = :workspace_uuid
                  AND statement.billing_run_uuid = :billing_run_uuid
                ORDER BY attempt.delivery_uuid, attempt.attempt_number DESC
            )
            SELECT
                COUNT(*) FILTER (WHERE delivery.cancelled_at IS NULL) AS active_total,
                COUNT(*) FILTER (
                    WHERE delivery.cancelled_at IS NULL
                      AND latest_attempt.delivery_uuid IS NOT NULL
                      AND latest_attempt.started_at IS NULL
                      AND latest_attempt.succeeded_at IS NULL
                      AND latest_attempt.failed_at IS NULL
                ) AS queued,
                COUNT(*) FILTER (
                    WHERE delivery.cancelled_at IS NULL
                      AND latest_attempt.started_at IS NOT NULL
                      AND latest_attempt.succeeded_at IS NULL
                      AND latest_attempt.failed_at IS NULL
                ) AS sending,
                COUNT(*) FILTER (
                    WHERE delivery.cancelled_at IS NULL
                      AND latest_attempt.succeeded_at IS NOT NULL
                ) AS sent,
                COUNT(*) FILTER (
                    WHERE delivery.cancelled_at IS NULL
                      AND latest_attempt.failed_at IS NOT NULL
                ) AS failed
            FROM account_statement_deliveries delivery
            INNER JOIN account_statements statement ON statement.uuid = delivery.account_statement_uuid
            LEFT JOIN latest_attempt ON latest_attempt.delivery_uuid = delivery.uuid
            WHERE delivery.workspace_uuid = :workspace_uuid
              AND statement.billing_run_uuid = :billing_run_uuid
              AND statement.cancelled_at IS NULL
            SQL, [
            'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
            'billing_run_uuid' => $billingRun->getUuid()->toRfc4122(),
        ]);

        return [
            'active_total' => (int) ($row['active_total'] ?? 0),
            'queued' => (int) ($row['queued'] ?? 0),
            'sending' => (int) ($row['sending'] ?? 0),
            'sent' => (int) ($row['sent'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    /**
     * @return list<AccountStatementDelivery>
     */
    public function findByStatement(Workspace $workspace, AccountStatementSnapshot $statement): array
    {
        return $this->createQueryBuilder('delivery')
            ->addSelect('attempt')
            ->addSelect('subscriber')
            ->leftJoin('delivery.attempts', 'attempt')
            ->leftJoin('delivery.recipientSubscriber', 'subscriber')
            ->andWhere('delivery.workspace = :workspace')
            ->andWhere('delivery.accountStatement = :statement')
            ->setParameter('workspace', $workspace)
            ->setParameter('statement', $statement)
            ->orderBy('delivery.createdAt', 'DESC')
            ->addOrderBy('attempt.attemptNumber', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<AccountStatementDelivery>
     */
    public function findActiveByStatement(Workspace $workspace, AccountStatementSnapshot $statement): array
    {
        return $this->createQueryBuilder('delivery')
            ->andWhere('delivery.workspace = :workspace')
            ->andWhere('delivery.accountStatement = :statement')
            ->andWhere('delivery.cancelledAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('statement', $statement)
            ->orderBy('delivery.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<AccountStatementSnapshot> $statements
     *
     * @return array<string, list<AccountStatementDelivery>>
     */
    public function findByStatements(Workspace $workspace, array $statements): array
    {
        if ($statements === []) {
            return [];
        }

        $deliveries = $this->createQueryBuilder('delivery')
            ->addSelect('attempt')
            ->addSelect('subscriber')
            ->addSelect('statement')
            ->innerJoin('delivery.accountStatement', 'statement')
            ->leftJoin('delivery.attempts', 'attempt')
            ->leftJoin('delivery.recipientSubscriber', 'subscriber')
            ->andWhere('delivery.workspace = :workspace')
            ->andWhere('delivery.accountStatement IN (:statements)')
            ->setParameter('workspace', $workspace)
            ->setParameter('statements', $statements)
            ->orderBy('delivery.createdAt', 'DESC')
            ->addOrderBy('attempt.attemptNumber', 'DESC')
            ->getQuery()
            ->getResult();

        $deliveriesByStatement = [];

        foreach ($deliveries as $delivery) {
            $statement = $delivery->getAccountStatement();

            if (!$statement instanceof AccountStatementSnapshot) {
                continue;
            }

            $deliveriesByStatement[$statement->getUuid()->toRfc4122()][] = $delivery;
        }

        return $deliveriesByStatement;
    }

    public function findOneActiveByStatementAndRecipient(
        Workspace $workspace,
        AccountStatementSnapshot $statement,
        AccountStatementDeliveryChannel $channel,
        string $recipientEmailNormalized,
    ): ?AccountStatementDelivery {
        return $this->createQueryBuilder('delivery')
            ->andWhere('delivery.workspace = :workspace')
            ->andWhere('delivery.accountStatement = :statement')
            ->andWhere('delivery.channel = :channel')
            ->andWhere('delivery.recipientEmailNormalized = :recipientEmailNormalized')
            ->andWhere('delivery.cancelledAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('statement', $statement)
            ->setParameter('channel', $channel)
            ->setParameter('recipientEmailNormalized', $recipientEmailNormalized)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
