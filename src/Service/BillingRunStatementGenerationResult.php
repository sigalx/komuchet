<?php

namespace App\Service;

use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementSnapshot;

final class BillingRunStatementGenerationResult
{
    /**
     * @var list<AccountStatementSnapshot>
     */
    public array $createdStatements = [];

    /**
     * @var list<AccountStatementSnapshot>
     */
    public array $existingStatements = [];

    /**
     * @var list<AccountStatementDelivery>
     */
    public array $createdDeliveries = [];

    public int $skippedWithoutEmail = 0;

    public int $skippedExistingDelivery = 0;

    public int $repairedPaymentRequisiteStatements = 0;

    public function createdStatementCount(): int
    {
        return count($this->createdStatements);
    }

    public function existingStatementCount(): int
    {
        return count($this->existingStatements);
    }

    public function createdDeliveryCount(): int
    {
        return count($this->createdDeliveries);
    }

    public function addEnqueueResult(AccountStatementDeliveryEnqueueResult $enqueueResult): void
    {
        array_push($this->createdDeliveries, ...$enqueueResult->createdDeliveries);
        $this->skippedWithoutEmail += $enqueueResult->skippedWithoutEmailCount();
        $this->skippedExistingDelivery += $enqueueResult->skippedExistingCount();
    }
}
