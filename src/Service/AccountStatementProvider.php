<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Workspace;
use App\Repository\AccrualRepository;
use App\Repository\ElectricityAccrualLineRepository;
use App\Repository\ElectricityAccrualRegisterRepository;
use App\Repository\PaymentRepository;
use DateTimeImmutable;

final readonly class AccountStatementProvider
{
    public function __construct(
        private AccountBalanceCalculator $balanceCalculator,
        private AccrualRepository $accrualRepository,
        private PaymentRepository $paymentRepository,
        private ElectricityAccrualRegisterRepository $electricityRegisterRepository,
        private ElectricityAccrualLineRepository $electricityLineRepository,
    ) {
    }

    public function buildCurrent(Workspace $workspace, Account $account, DateTimeImmutable $statementDate): AccountStatement
    {
        $balance = $this->balanceCalculator->calculate($workspace, $account);
        $accrualRows = [];

        foreach ($this->accrualRepository->findActivePostedByAccount($workspace, $account) as $accrual) {
            $accrualRows[] = new AccountStatementAccrualRow(
                accrual: $accrual,
                electricityRegisters: $this->electricityRegisterRepository->findByAccrual($workspace, $accrual),
                electricityLines: $this->electricityLineRepository->findByAccrual($workspace, $accrual),
            );
        }

        $paymentRows = [];

        foreach ($this->paymentRepository->findActiveByAccount($workspace, $account) as $payment) {
            $paymentRows[] = new AccountStatementPaymentRow($payment);
        }

        return new AccountStatement(
            workspace: $workspace,
            account: $account,
            statementDate: $statementDate,
            balance: $balance,
            amountToPay: $this->positivePart($this->negate($balance->balanceAmount)),
            overpaymentAmount: $this->positivePart($balance->balanceAmount),
            accrualRows: $accrualRows,
            paymentRows: $paymentRows,
        );
    }

    private function positivePart(string $amount): string
    {
        $cents = $this->toCents($amount);

        return $this->fromCents(max(0, $cents));
    }

    private function negate(string $amount): string
    {
        return $this->fromCents(-$this->toCents($amount));
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
