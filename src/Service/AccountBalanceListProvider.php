<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Workspace;
use App\Pagination\PaginatedResult;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

class AccountBalanceListProvider
{
    public const STATE_FILTER_ALL = 'all';
    public const STATE_FILTER_DEBT = 'debt';
    public const STATE_FILTER_OVERPAYMENT = 'overpayment';
    public const STATE_FILTER_SETTLED = 'settled';

    public const SORT_NUMBER = 'number';
    public const SORT_DEBT_DESC = 'debt_desc';
    public const SORT_OVERPAYMENT_DESC = 'overpayment_desc';

    private const STATE_FILTERS = [
        self::STATE_FILTER_ALL,
        self::STATE_FILTER_DEBT,
        self::STATE_FILTER_OVERPAYMENT,
        self::STATE_FILTER_SETTLED,
    ];

    private const SORTS = [
        self::SORT_NUMBER,
        self::SORT_DEBT_DESC,
        self::SORT_OVERPAYMENT_DESC,
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<AccountBalanceListItem>
     */
    public function findByWorkspace(Workspace $workspace, string $stateFilter, string $sort): array
    {
        [$whereSql, $orderSql] = $this->listSqlParts($stateFilter, $sort);

        return $this->mapRows($this->connection->fetchAllAssociative(sprintf(
            <<<'SQL'
                WITH account_balance_rows AS (
                    %s
                )
                %s
                %s
                SQL,
            $this->baseAccountBalanceRowsSql(),
            $this->selectAccountBalanceRowsSql(),
            trim($whereSql.' '.$orderSql),
        ), [
            'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
        ]));
    }

    /**
     * @return PaginatedResult<AccountBalanceListItem>
     */
    public function paginateByWorkspace(Workspace $workspace, string $stateFilter, string $sort, int $page, int $perPage = 50): PaginatedResult
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        [$whereSql, $orderSql] = $this->listSqlParts($stateFilter, $sort);
        $parameters = [
            'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
        ];
        $totalItems = (int) $this->connection->fetchOne(sprintf(
            <<<'SQL'
                WITH account_balance_rows AS (
                    %s
                )
                SELECT COUNT(*)
                FROM account_balance_rows
                %s
                SQL,
            $this->baseAccountBalanceRowsSql(),
            $whereSql,
        ), $parameters);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
        }

        $rows = $this->connection->fetchAllAssociative(sprintf(
            <<<'SQL'
                WITH account_balance_rows AS (
                    %s
                )
                %s
                %s
                LIMIT :limit OFFSET :offset
                SQL,
            $this->baseAccountBalanceRowsSql(),
            $this->selectAccountBalanceRowsSql(),
            trim($whereSql.' '.$orderSql),
        ), $parameters + [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
        ]);

        return new PaginatedResult(
            items: $this->mapRows($rows),
            totalItems: $totalItems,
            currentPage: $page,
            perPage: $perPage,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function listSqlParts(string $stateFilter, string $sort): array
    {
        $stateFilter = $this->normalizeStateFilter($stateFilter);
        $sort = $this->normalizeSort($sort);
        $whereSql = match ($stateFilter) {
            self::STATE_FILTER_DEBT => 'WHERE balance_amount < 0',
            self::STATE_FILTER_OVERPAYMENT => 'WHERE balance_amount > 0',
            self::STATE_FILTER_SETTLED => 'WHERE balance_amount = 0',
            default => '',
        };
        $orderSql = match ($sort) {
            self::SORT_DEBT_DESC => 'ORDER BY balance_amount::numeric ASC, number ASC',
            self::SORT_OVERPAYMENT_DESC => 'ORDER BY balance_amount::numeric DESC, number ASC',
            default => 'ORDER BY number ASC',
        };

        return [$whereSql, $orderSql];
    }

    private function baseAccountBalanceRowsSql(): string
    {
        return <<<'SQL'
            SELECT
                account.uuid::text AS account_uuid,
                account.number,
                account.notes,
                COALESCE(accruals.total_amount, 0)::numeric(14, 2) AS active_accrual_total,
                COALESCE(payments.total_amount, 0)::numeric(14, 2) AS active_payment_total,
                (
                    COALESCE(payments.total_amount, 0)
                    - COALESCE(accruals.total_amount, 0)
                )::numeric(14, 2) AS balance_amount
            FROM accounts account
            LEFT JOIN (
                SELECT account_uuid, SUM(amount) AS total_amount
                FROM accruals
                WHERE workspace_uuid = :workspace_uuid
                  AND posted_at IS NOT NULL
                  AND cancelled_at IS NULL
                  AND replacing_accrual_uuid IS NULL
                GROUP BY account_uuid
            ) accruals ON accruals.account_uuid = account.uuid
            LEFT JOIN (
                SELECT account_uuid, SUM(amount) AS total_amount
                FROM payments
                WHERE workspace_uuid = :workspace_uuid
                  AND cancelled_at IS NULL
                  AND replacing_payment_uuid IS NULL
                GROUP BY account_uuid
            ) payments ON payments.account_uuid = account.uuid
            WHERE account.workspace_uuid = :workspace_uuid
              AND account.deleted_at IS NULL
            SQL;
    }

    private function selectAccountBalanceRowsSql(): string
    {
        return <<<'SQL'
            SELECT
                account_uuid,
                number,
                notes,
                active_accrual_total::text AS active_accrual_total,
                active_payment_total::text AS active_payment_total,
                balance_amount::text AS balance_amount,
                GREATEST(-balance_amount, 0)::numeric(14, 2)::text AS debt_amount,
                GREATEST(balance_amount, 0)::numeric(14, 2)::text AS overpayment_amount,
                CASE
                    WHEN balance_amount < 0 THEN 'debt'
                    WHEN balance_amount > 0 THEN 'overpayment'
                    ELSE 'settled'
                END AS state
            FROM account_balance_rows
            SQL;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<AccountBalanceListItem>
     */
    private function mapRows(array $rows): array
    {
        return array_map(
            static fn (array $row): AccountBalanceListItem => new AccountBalanceListItem(
                accountUuid: (string) $row['account_uuid'],
                accountNumber: (string) $row['number'],
                accountNotes: $row['notes'] === null ? null : (string) $row['notes'],
                activeAccrualTotal: (string) $row['active_accrual_total'],
                activePaymentTotal: (string) $row['active_payment_total'],
                balanceAmount: (string) $row['balance_amount'],
                debtAmount: (string) $row['debt_amount'],
                overpaymentAmount: (string) $row['overpayment_amount'],
                state: (string) $row['state'],
            ),
            $rows,
        );
    }

    /**
     * @param list<Account> $accounts
     *
     * @return array<string, AccountBalanceListItem>
     */
    public function findIndexedByWorkspaceAndAccounts(Workspace $workspace, array $accounts): array
    {
        $accountUuids = [];

        foreach ($accounts as $account) {
            $accountWorkspace = $account->getWorkspace();

            if ($accountWorkspace instanceof Workspace && $accountWorkspace->getUuid()->equals($workspace->getUuid())) {
                $accountUuids[] = $account->getUuid()->toRfc4122();
            }
        }

        $accountUuids = array_values(array_unique($accountUuids));

        if ($accountUuids === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT
                account.uuid::text AS account_uuid,
                account.number,
                account.notes,
                COALESCE(accruals.total_amount, 0)::numeric(14, 2)::text AS active_accrual_total,
                COALESCE(payments.total_amount, 0)::numeric(14, 2)::text AS active_payment_total,
                (
                    COALESCE(payments.total_amount, 0)
                    - COALESCE(accruals.total_amount, 0)
                )::numeric(14, 2)::text AS balance_amount,
                GREATEST(
                    COALESCE(accruals.total_amount, 0) - COALESCE(payments.total_amount, 0),
                    0
                )::numeric(14, 2)::text AS debt_amount,
                GREATEST(
                    COALESCE(payments.total_amount, 0) - COALESCE(accruals.total_amount, 0),
                    0
                )::numeric(14, 2)::text AS overpayment_amount,
                CASE
                    WHEN COALESCE(payments.total_amount, 0) - COALESCE(accruals.total_amount, 0) < 0 THEN 'debt'
                    WHEN COALESCE(payments.total_amount, 0) - COALESCE(accruals.total_amount, 0) > 0 THEN 'overpayment'
                    ELSE 'settled'
                END AS state
            FROM accounts account
            LEFT JOIN (
                SELECT account_uuid, SUM(amount) AS total_amount
                FROM accruals
                WHERE workspace_uuid = :workspace_uuid
                  AND posted_at IS NOT NULL
                  AND cancelled_at IS NULL
                  AND replacing_accrual_uuid IS NULL
                GROUP BY account_uuid
            ) accruals ON accruals.account_uuid = account.uuid
            LEFT JOIN (
                SELECT account_uuid, SUM(amount) AS total_amount
                FROM payments
                WHERE workspace_uuid = :workspace_uuid
                  AND cancelled_at IS NULL
                  AND replacing_payment_uuid IS NULL
                GROUP BY account_uuid
            ) payments ON payments.account_uuid = account.uuid
            WHERE account.workspace_uuid = :workspace_uuid
              AND account.deleted_at IS NULL
              AND account.uuid::text IN (:account_uuids)
            ORDER BY account.number ASC
            SQL, [
                'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
                'account_uuids' => $accountUuids,
            ], [
                'account_uuids' => ArrayParameterType::STRING,
            ]);

        $indexedRows = [];

        foreach ($rows as $row) {
            $item = new AccountBalanceListItem(
                accountUuid: (string) $row['account_uuid'],
                accountNumber: (string) $row['number'],
                accountNotes: $row['notes'] === null ? null : (string) $row['notes'],
                activeAccrualTotal: (string) $row['active_accrual_total'],
                activePaymentTotal: (string) $row['active_payment_total'],
                balanceAmount: (string) $row['balance_amount'],
                debtAmount: (string) $row['debt_amount'],
                overpaymentAmount: (string) $row['overpayment_amount'],
                state: (string) $row['state'],
            );

            $indexedRows[$item->accountUuid] = $item;
        }

        return $indexedRows;
    }

    public function normalizeStateFilter(string $stateFilter): string
    {
        return in_array($stateFilter, self::STATE_FILTERS, true) ? $stateFilter : self::STATE_FILTER_ALL;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, self::SORTS, true) ? $sort : self::SORT_NUMBER;
    }
}
