<?php

namespace App\Service;

use App\Entity\AccountStatementDelivery;
use App\Entity\Subscriber;

final readonly class AccountStatementDeliveryEnqueueResult
{
    /**
     * @param list<AccountStatementDelivery> $createdDeliveries
     * @param list<Subscriber>              $skippedWithoutEmail
     * @param list<Subscriber>              $skippedExisting
     */
    public function __construct(
        public array $createdDeliveries,
        public array $skippedWithoutEmail,
        public array $skippedExisting,
    ) {
    }

    public function createdCount(): int
    {
        return count($this->createdDeliveries);
    }

    public function skippedWithoutEmailCount(): int
    {
        return count($this->skippedWithoutEmail);
    }

    public function skippedExistingCount(): int
    {
        return count($this->skippedExisting);
    }
}
