<?php

namespace App\Entity;

use App\Repository\AccountStatementElectricityLineSnapshotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountStatementElectricityLineSnapshotRepository::class)]
#[ORM\Table(name: 'account_statement_electricity_lines')]
class AccountStatementElectricityLineSnapshot
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
    #[ORM\ManyToOne(targetEntity: ElectricityTariffZone::class)]
    #[ORM\JoinColumn(name: 'tariff_zone_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffZone $tariffZone = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityConsumptionBand::class)]
    #[ORM\JoinColumn(name: 'consumption_band_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityConsumptionBand $consumptionBand = null;

    #[ORM\Column(name: 'tariff_zone_code', type: Types::TEXT)]
    private string $tariffZoneCode = '';

    #[ORM\Column(name: 'tariff_zone_name', type: Types::TEXT)]
    private string $tariffZoneName = '';

    #[ORM\Column(name: 'consumption_band_code', type: Types::TEXT)]
    private string $consumptionBandCode = '';

    #[ORM\Column(name: 'consumption_band_name', type: Types::TEXT)]
    private string $consumptionBandName = '';

    #[ORM\Column(name: 'consumption_kwh', type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $consumptionKwh = '0.000';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    private string $rate = '0.000000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $amount = '0.00';

    #[ORM\Column(name: 'sort_order', type: Types::INTEGER)]
    private int $sortOrder = 1;

    public function __construct(
        ?Workspace $workspace = null,
        ?AccountStatementSnapshot $accountStatement = null,
        ?ElectricityAccrualLine $line = null,
        int $sortOrder = 1,
    ) {
        $this->workspace = $workspace;
        $this->accountStatement = $accountStatement;
        $this->sortOrder = $sortOrder;

        if ($line instanceof ElectricityAccrualLine) {
            $this->accrual = $line->getAccrual();
            $this->tariffZone = $line->getTariffZone();
            $this->consumptionBand = $line->getConsumptionBand();
            $this->consumptionKwh = $this->normalizeDecimal($line->getConsumptionKwh(), 3);
            $this->rate = $this->normalizeDecimal($line->getRate(), 6);
            $this->amount = $this->normalizeDecimal($line->getAmount(), 2);

            if ($this->tariffZone instanceof ElectricityTariffZone) {
                $this->tariffZoneCode = $this->tariffZone->getCode();
                $this->tariffZoneName = $this->tariffZone->getName();
            }

            if ($this->consumptionBand instanceof ElectricityConsumptionBand) {
                $this->consumptionBandCode = $this->consumptionBand->getCode();
                $this->consumptionBandName = $this->consumptionBand->getName();
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

    public function getTariffZone(): ?ElectricityTariffZone
    {
        return $this->tariffZone;
    }

    public function getConsumptionBand(): ?ElectricityConsumptionBand
    {
        return $this->consumptionBand;
    }

    public function getTariffZoneCode(): string
    {
        return $this->tariffZoneCode;
    }

    public function getTariffZoneName(): string
    {
        return $this->tariffZoneName;
    }

    public function getConsumptionBandCode(): string
    {
        return $this->consumptionBandCode;
    }

    public function getConsumptionBandName(): string
    {
        return $this->consumptionBandName;
    }

    public function getConsumptionKwh(): string
    {
        return $this->consumptionKwh;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function getAmount(): string
    {
        return $this->amount;
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
