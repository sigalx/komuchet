<?php

namespace App\Entity;

use App\Repository\ElectricityMeterRegisterRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElectricityMeterRegisterRepository::class)]
#[ORM\Table(name: 'electricity_meter_registers')]
#[ORM\Index(name: 'ix_electricity_meter_registers_zone', columns: ['workspace_uuid', 'tariff_zone_uuid'])]
class ElectricityMeterRegister
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityMeter::class)]
    #[ORM\JoinColumn(name: 'electricity_meter_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityMeter $electricityMeter = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityTariffZone::class)]
    #[ORM\JoinColumn(name: 'tariff_zone_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffZone $tariffZone = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?ElectricityMeter $electricityMeter = null,
        ?ElectricityTariffZone $tariffZone = null,
    ) {
        $this->workspace = $workspace;
        $this->electricityMeter = $electricityMeter;
        $this->tariffZone = $tariffZone;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getElectricityMeter(): ?ElectricityMeter
    {
        return $this->electricityMeter;
    }

    public function getTariffZone(): ?ElectricityTariffZone
    {
        return $this->tariffZone;
    }
}
