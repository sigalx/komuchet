<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\AccountStatementAccrualSnapshot;
use App\Entity\AccountStatementElectricityLineSnapshot;
use App\Entity\AccountStatementElectricityRegisterSnapshot;
use App\Entity\AccountStatementPaymentSnapshot;
use App\Entity\AccountStatementSnapshot;
use App\Entity\BillingRun;
use App\Entity\User;
use App\Entity\Workspace;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AccountStatementSnapshotGenerator
{
    public function __construct(
        private AccountStatementProvider $statementProvider,
        private PaymentRequisiteResolver $paymentRequisiteResolver,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function generateCurrent(
        Workspace $workspace,
        Account $account,
        DateTimeImmutable $statementDate,
        ?User $generatedBy = null,
        ?BillingRun $billingRun = null,
    ): AccountStatementSnapshot {
        $statement = $this->statementProvider->buildCurrent($workspace, $account, $statementDate);
        $snapshot = new AccountStatementSnapshot(
            workspace: $workspace,
            account: $account,
            statementDate: $statementDate,
            activeAccrualTotal: $statement->balance->activeAccrualTotal,
            activePaymentTotal: $statement->balance->activePaymentTotal,
            balanceAmount: $statement->balance->balanceAmount,
            amountToPay: $statement->amountToPay,
            overpaymentAmount: $statement->overpaymentAmount,
            generatedBy: $generatedBy,
            billingRun: $billingRun,
        );
        $this->paymentRequisiteResolver->applyToSnapshot($workspace, $statement, $snapshot);

        $this->entityManager->persist($snapshot);

        $electricityRegisterSortOrder = 1;
        $electricityLineSortOrder = 1;

        foreach ($statement->accrualRows as $index => $row) {
            $this->entityManager->persist(new AccountStatementAccrualSnapshot(
                workspace: $workspace,
                accountStatement: $snapshot,
                accrual: $row->accrual,
                sortOrder: $index + 1,
            ));

            foreach ($row->electricityRegisters as $register) {
                $this->entityManager->persist(new AccountStatementElectricityRegisterSnapshot(
                    workspace: $workspace,
                    accountStatement: $snapshot,
                    register: $register,
                    sortOrder: $electricityRegisterSortOrder++,
                ));
            }

            foreach ($row->electricityLines as $line) {
                $this->entityManager->persist(new AccountStatementElectricityLineSnapshot(
                    workspace: $workspace,
                    accountStatement: $snapshot,
                    line: $line,
                    sortOrder: $electricityLineSortOrder++,
                ));
            }
        }

        foreach ($statement->paymentRows as $index => $row) {
            $this->entityManager->persist(new AccountStatementPaymentSnapshot(
                workspace: $workspace,
                accountStatement: $snapshot,
                payment: $row->payment,
                sortOrder: $index + 1,
            ));
        }

        return $snapshot;
    }

    public function fillMissingPaymentRequisites(Workspace $workspace, AccountStatementSnapshot $snapshot): bool
    {
        if ($snapshot->hasPaymentRequisites()) {
            return false;
        }

        $account = $snapshot->getAccount();

        if (!$account instanceof Account) {
            return false;
        }

        $statement = $this->statementProvider->buildCurrent($workspace, $account, $snapshot->getStatementDate());
        $this->paymentRequisiteResolver->applyToSnapshot($workspace, $statement, $snapshot);

        return $snapshot->hasPaymentRequisites();
    }
}
