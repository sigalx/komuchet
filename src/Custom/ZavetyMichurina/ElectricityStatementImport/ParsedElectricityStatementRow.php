<?php

namespace App\Custom\ZavetyMichurina\ElectricityStatementImport;

final readonly class ParsedElectricityStatementRow
{
    public function __construct(
        public int $year,
        public int $month,
        public string $monthName,
        public string $periodStart,
        public string $readingValueKwh,
        public string $consumptionKwh,
        public string $socialNormKwh,
        public string $socialNormRate,
        public string $aboveNormKwh,
        public string $aboveNormRate,
        public string $accruedAmount,
        public ?string $paidOn,
        public ?string $paidAmount,
    ) {
    }

    /**
     * @return array<string, int|string|null>
     */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'month_name' => $this->monthName,
            'period_start' => $this->periodStart,
            'reading_value_kwh' => $this->readingValueKwh,
            'consumption_kwh' => $this->consumptionKwh,
            'social_norm_kwh' => $this->socialNormKwh,
            'social_norm_rate' => $this->socialNormRate,
            'above_norm_kwh' => $this->aboveNormKwh,
            'above_norm_rate' => $this->aboveNormRate,
            'accrued_amount' => $this->accruedAmount,
            'paid_on' => $this->paidOn,
            'paid_amount' => $this->paidAmount,
        ];
    }
}
