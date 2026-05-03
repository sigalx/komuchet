<?php

namespace App\Entity;

use App\Repository\AccountStatementElectricityRegisterSnapshotRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountStatementElectricityRegisterSnapshotRepository::class)]
#[ORM\Table(name: 'account_statement_electricity_registers')]
#[ORM\Index(name: 'ix_account_statement_electricity_registers_readings', columns: ['workspace_uuid', 'previous_reading_uuid', 'current_reading_uuid'])]
class AccountStatementElectricityRegisterSnapshot
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: AccountStatementSnapshot::class)]
    #[ORM\JoinColumn(name: 'account_statement_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?AccountStatementSnapshot $accountStatement = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Accrual::class)]
    #[ORM\JoinColumn(name: 'accrual_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Accrual $accrual = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityMeter::class)]
    #[ORM\JoinColumn(name: 'electricity_meter_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityMeter $electricityMeter = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityTariffZone::class)]
    #[ORM\JoinColumn(name: 'tariff_zone_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffZone $tariffZone = null;

    #[ORM\Column(name: 'tariff_zone_code', type: Types::TEXT)]
    private string $tariffZoneCode = '';

    #[ORM\Column(name: 'tariff_zone_name', type: Types::TEXT)]
    private string $tariffZoneName = '';

    #[ORM\Column(name: 'electricity_meter_serial_number', type: Types::TEXT, nullable: true)]
    private ?string $electricityMeterSerialNumber = null;

    #[ORM\Column(name: 'electricity_meter_model', type: Types::TEXT, nullable: true)]
    private ?string $electricityMeterModel = null;

    #[ORM\ManyToOne(targetEntity: ElectricityMeterReading::class)]
    #[ORM\JoinColumn(name: 'previous_reading_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?ElectricityMeterReading $previousReading = null;

    #[ORM\Column(name: 'previous_reading_value', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $previousReadingValue = null;

    #[ORM\Column(name: 'previous_reading_taken_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $previousReadingTakenOn = null;

    #[ORM\ManyToOne(targetEntity: ElectricityMeterReading::class)]
    #[ORM\JoinColumn(name: 'current_reading_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityMeterReading $currentReading = null;

    #[ORM\Column(name: 'current_reading_value', type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $currentReadingValue = '0.000';

    #[ORM\Column(name: 'current_reading_taken_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $currentReadingTakenOn;

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER)]
    private int $sortOrder = 1;

    public function __construct(
        ?Workspace $workspace = null,
        ?AccountStatementSnapshot $accountStatement = null,
        ?ElectricityAccrualRegister $register = null,
        int $sortOrder = 1,
    ) {
        $this->workspace = $workspace;
        $this->accountStatement = $accountStatement;
        $this->sortOrder = $sortOrder;
        $this->currentReadingTakenOn = new DateTimeImmutable('today');

        if ($register instanceof ElectricityAccrualRegister) {
            $this->accrual = $register->getAccrual();
            $this->electricityMeter = $register->getElectricityMeter();
            $this->tariffZone = $register->getTariffZone();
            $this->previousReading = $register->getPreviousReading();
            $this->currentReading = $register->getCurrentReading();

            if ($this->tariffZone instanceof ElectricityTariffZone) {
                $this->tariffZoneCode = $this->tariffZone->getCode();
                $this->tariffZoneName = $this->tariffZone->getName();
            }

            if ($this->electricityMeter instanceof ElectricityMeter) {
                $this->electricityMeterSerialNumber = $this->electricityMeter->getSerialNumber();
                $this->electricityMeterModel = $this->electricityMeter->getModel();
            }

            if ($this->previousReading instanceof ElectricityMeterReading) {
                $this->previousReadingValue = $this->normalizeDecimal($this->previousReading->getReadingValue(), 3);
                $this->previousReadingTakenOn = $this->previousReading->getTakenOn();
            }

            if ($this->currentReading instanceof ElectricityMeterReading) {
                $this->currentReadingValue = $this->normalizeDecimal($this->currentReading->getReadingValue(), 3);
                $this->currentReadingTakenOn = $this->currentReading->getTakenOn();
            }
        }
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getAccountStatement(): ?AccountStatementSnapshot
    {
        return $this->accountStatement;
    }

    public function getAccrual(): ?Accrual
    {
        return $this->accrual;
    }

    public function getElectricityMeter(): ?ElectricityMeter
    {
        return $this->electricityMeter;
    }

    public function getTariffZone(): ?ElectricityTariffZone
    {
        return $this->tariffZone;
    }

    public function getTariffZoneCode(): string
    {
        return $this->tariffZoneCode;
    }

    public function getTariffZoneName(): string
    {
        return $this->tariffZoneName;
    }

    public function getElectricityMeterSerialNumber(): ?string
    {
        return $this->electricityMeterSerialNumber;
    }

    public function getElectricityMeterModel(): ?string
    {
        return $this->electricityMeterModel;
    }

    public function getPreviousReading(): ?ElectricityMeterReading
    {
        return $this->previousReading;
    }

    public function getPreviousReadingValue(): ?string
    {
        return $this->previousReadingValue;
    }

    public function getPreviousReadingTakenOn(): ?DateTimeImmutable
    {
        return $this->previousReadingTakenOn;
    }

    public function getCurrentReading(): ?ElectricityMeterReading
    {
        return $this->currentReading;
    }

    public function getCurrentReadingValue(): string
    {
        return $this->currentReadingValue;
    }

    public function getCurrentReadingTakenOn(): DateTimeImmutable
    {
        return $this->currentReadingTakenOn;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    private function normalizeDecimal(string $value, int $scale): string
    {
        $value = trim(str_replace(',', '.', $value));
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');

        return sprintf('%s%d.%s', $negative ? '-' : '', (int) $whole, $fraction);
    }
}
