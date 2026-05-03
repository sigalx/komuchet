<?php

namespace App\Custom\ZavetyMichurina\ElectricityStatementImport;

final readonly class ParsedElectricityStatement
{
    /**
     * @param list<ParsedElectricityStatementRow> $rows
     * @param array{total_accrued: string|null, total_paid: string|null, balance: string|null} $totals
     * @param list<string> $warnings
     */
    public function __construct(
        public ?string $accountNumber,
        public ?string $subscriberFullName,
        public ?string $meterInstalledOn,
        public ?string $meterSerialNumber,
        public ?string $meterInitialReading,
        public array $rows,
        public array $totals,
        public ParsedPaymentRequisites $paymentRequisites,
        public array $warnings,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => [
                'format' => 'zavety_michurina_electricity_statement',
                'mode' => 'dry_run',
            ],
            'account' => [
                'number' => $this->accountNumber,
            ],
            'subscriber' => [
                'full_name' => $this->subscriberFullName,
            ],
            'electricity_meter' => [
                'installed_on' => $this->meterInstalledOn,
                'serial_number' => $this->meterSerialNumber,
                'initial_reading_kwh' => $this->meterInitialReading,
            ],
            'rows' => array_map(
                static fn (ParsedElectricityStatementRow $row): array => $row->toArray(),
                $this->rows,
            ),
            'totals' => $this->totals,
            'payment_requisites' => $this->paymentRequisites->toArray(),
            'warnings' => $this->warnings,
        ];
    }
}
