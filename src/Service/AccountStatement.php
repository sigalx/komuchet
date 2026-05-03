<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Workspace;
use DateTimeImmutable;

final readonly class AccountStatement
{
    /**
     * @param list<AccountStatementAccrualRow> $accrualRows
     * @param list<AccountStatementPaymentRow> $paymentRows
     */
    public function __construct(
        public Workspace $workspace,
        public Account $account,
        public DateTimeImmutable $statementDate,
        public AccountBalanceSummary $balance,
        public string $amountToPay,
        public string $overpaymentAmount,
        public array $accrualRows,
        public array $paymentRows,
    ) {
    }

    public function hasDebt(): bool
    {
        return $this->balance->state === 'debt';
    }

    public function hasOverpayment(): bool
    {
        return $this->balance->state === 'overpayment';
    }

    public function hasElectricityDetails(): bool
    {
        foreach ($this->accrualRows as $row) {
            if ($row->electricityRegisters !== [] || $row->electricityLines !== []) {
                return true;
            }
        }

        return false;
    }
}
