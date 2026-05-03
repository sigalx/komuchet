<?php

namespace App\Service;

use App\Entity\Accrual;
use App\Entity\ElectricityAccrualLine;
use App\Entity\ElectricityAccrualRegister;

final readonly class AccountStatementAccrualRow
{
    /**
     * @param list<ElectricityAccrualRegister> $electricityRegisters
     * @param list<ElectricityAccrualLine> $electricityLines
     */
    public function __construct(
        public Accrual $accrual,
        public array $electricityRegisters = [],
        public array $electricityLines = [],
    ) {
    }
}
