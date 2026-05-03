<?php

namespace App\Entity;

use App\Repository\ElectricityAccrualRegisterRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElectricityAccrualRegisterRepository::class)]
#[ORM\Table(name: 'electricity_accrual_registers')]
class ElectricityAccrualRegister
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
    #[ORM\ManyToOne(targetEntity: ElectricityMeter::class)]
    #[ORM\JoinColumn(name: 'electricity_meter_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityMeter $electricityMeter = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityTariffZone::class)]
    #[ORM\JoinColumn(name: 'tariff_zone_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffZone $tariffZone = null;

    #[ORM\ManyToOne(targetEntity: ElectricityMeterReading::class)]
    #[ORM\JoinColumn(name: 'previous_reading_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?ElectricityMeterReading $previousReading = null;

    #[ORM\ManyToOne(targetEntity: ElectricityMeterReading::class)]
    #[ORM\JoinColumn(name: 'current_reading_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityMeterReading $currentReading = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?Accrual $accrual = null,
        ?ElectricityMeter $electricityMeter = null,
        ?ElectricityTariffZone $tariffZone = null,
        ?ElectricityMeterReading $currentReading = null,
        ?ElectricityMeterReading $previousReading = null,
    ) {
        $this->workspace = $workspace;
        $this->accrual = $accrual;
        $this->electricityMeter = $electricityMeter;
        $this->tariffZone = $tariffZone;
        $this->currentReading = $currentReading;
        $this->previousReading = $previousReading;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
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

    public function getPreviousReading(): ?ElectricityMeterReading
    {
        return $this->previousReading;
    }

    public function getCurrentReading(): ?ElectricityMeterReading
    {
        return $this->currentReading;
    }
}
