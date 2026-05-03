<?php

namespace App\Service;

final readonly class AccountBalanceListItem
{
    public function __construct(
        public string $accountUuid,
        public string $accountNumber,
        public ?string $accountNotes,
        public string $activeAccrualTotal,
        public string $activePaymentTotal,
        public string $balanceAmount,
        public string $debtAmount,
        public string $overpaymentAmount,
        public string $state,
    ) {
    }
}
