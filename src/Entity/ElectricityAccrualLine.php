<?php

namespace App\Entity;

use App\Repository\ElectricityAccrualLineRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElectricityAccrualLineRepository::class)]
#[ORM\Table(name: 'electricity_accrual_lines')]
class ElectricityAccrualLine
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

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

    #[ORM\Column(name: 'consumption_kwh', type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $consumptionKwh = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    private string $rate = '0';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $amount = '0';

    public function __construct(
        ?Workspace $workspace = null,
        ?Accrual $accrual = null,
        ?ElectricityTariffZone $tariffZone = null,
        ?ElectricityConsumptionBand $consumptionBand = null,
        string $consumptionKwh = '0',
        string $rate = '0',
        string $amount = '0',
    ) {
        $this->workspace = $workspace;
        $this->accrual = $accrual;
        $this->tariffZone = $tariffZone;
        $this->consumptionBand = $consumptionBand;
        $this->consumptionKwh = $consumptionKwh;
        $this->rate = $rate;
        $this->amount = $amount;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
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
}
