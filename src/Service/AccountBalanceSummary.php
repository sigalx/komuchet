<?php

namespace App\Service;

final readonly class AccountBalanceSummary
{
    public function __construct(
        public string $activeAccrualTotal,
        public string $activePaymentTotal,
        public string $balanceAmount,
        public string $state,
    ) {
    }
}
