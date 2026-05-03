<?php

namespace App\Service;

use App\Entity\BillingRun;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\AccountStatementSnapshotRepository;
use App\Repository\AccrualRepository;
use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Throwable;

final readonly class BillingRunStatementGenerator
{
    public function __construct(
        private AccrualRepository $accrualRepository,
        private AccountStatementSnapshotRepository $statementRepository,
        private AccountStatementSnapshotGenerator $statementGenerator,
        private AccountStatementDeliveryEnqueuer $deliveryEnqueuer,
    ) {
    }

    public function generateForPostedBillingRun(BillingRun $billingRun, ?User $generatedBy = null): BillingRunStatementGenerationResult
    {
        if (!$billingRun->isPosted()) {
            throw new LogicException('Statements can be generated only for posted billing runs.');
        }

        $workspace = $billingRun->getWorkspace();

        if (!$workspace instanceof Workspace) {
            throw new LogicException('Billing run must be bound to a workspace.');
        }

        $result = new BillingRunStatementGenerationResult();
        $statementDate = $this->todayInWorkspace($workspace);

        foreach ($this->accrualRepository->findActivePostedAccountsByBillingRun($workspace, $billingRun) as $account) {
            $statement = $this->statementRepository->findOneActiveByBillingRunAndAccount($workspace, $billingRun, $account);

            if ($statement === null) {
                $statement = $this->statementGenerator->generateCurrent(
                    workspace: $workspace,
                    account: $account,
                    statementDate: $statementDate,
                    generatedBy: $generatedBy,
                    billingRun: $billingRun,
                );
                $result->createdStatements[] = $statement;
            } else {
                if ($this->statementGenerator->fillMissingPaymentRequisites($workspace, $statement)) {
                    ++$result->repairedPaymentRequisiteStatements;
                }

                $result->existingStatements[] = $statement;
            }

            $result->addEnqueueResult($this->deliveryEnqueuer->enqueueForActiveAccountSubscribers(
                workspace: $workspace,
                statement: $statement,
                queuedBy: $generatedBy,
            ));
        }

        return $result;
    }

    private function todayInWorkspace(Workspace $workspace): DateTimeImmutable
    {
        try {
            $timezone = new DateTimeZone($workspace->getTimezone());
        } catch (Throwable) {
            $timezone = new DateTimeZone('Europe/Moscow');
        }

        return new DateTimeImmutable('today', $timezone);
    }
}
