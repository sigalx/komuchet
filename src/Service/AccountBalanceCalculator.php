<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Workspace;
use App\Repository\AccrualRepository;
use App\Repository\PaymentRepository;

class AccountBalanceCalculator
{
    public function __construct(
        private readonly AccrualRepository $accrualRepository,
        private readonly PaymentRepository $paymentRepository,
    ) {
    }

    public function calculate(Workspace $workspace, Account $account): AccountBalanceSummary
    {
        $activeAccrualTotal = $this->accrualRepository->sumActivePostedAmountByAccount($workspace, $account);
        $activePaymentTotal = $this->paymentRepository->sumActiveAmountByAccount($workspace, $account);
        $balanceCents = $this->toCents($activePaymentTotal) - $this->toCents($activeAccrualTotal);

        return new AccountBalanceSummary(
            activeAccrualTotal: $this->fromCents($this->toCents($activeAccrualTotal)),
            activePaymentTotal: $this->fromCents($this->toCents($activePaymentTotal)),
            balanceAmount: $this->fromCents($balanceCents),
            state: match (true) {
                $balanceCents < 0 => 'debt',
                $balanceCents > 0 => 'overpayment',
                default => 'settled',
            },
        );
    }

    private function toCents(string $amount): int
    {
        $amount = trim(str_replace(',', '.', $amount));
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');

        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    private function fromCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absoluteCents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($absoluteCents, 100), $absoluteCents % 100);
    }
}
