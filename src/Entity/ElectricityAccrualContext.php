<?php

namespace App\Entity;

use App\Repository\ElectricityAccrualContextRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElectricityAccrualContextRepository::class)]
#[ORM\Table(name: 'electricity_accrual_contexts')]
class ElectricityAccrualContext
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Accrual::class)]
    #[ORM\JoinColumn(name: 'accrual_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Accrual $accrual = null;

    #[ORM\ManyToOne(targetEntity: ElectricityMeter::class)]
    #[ORM\JoinColumn(name: 'electricity_meter_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityMeter $electricityMeter = null;

    #[ORM\ManyToOne(targetEntity: ElectricityTariffProfile::class)]
    #[ORM\JoinColumn(name: 'tariff_profile_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffProfile $tariffProfile = null;

    #[ORM\ManyToOne(targetEntity: ElectricityTariffPeriod::class)]
    #[ORM\JoinColumn(name: 'tariff_period_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffPeriod $tariffPeriod = null;

    #[ORM\ManyToOne(targetEntity: ElectricityConsumptionBandRule::class)]
    #[ORM\JoinColumn(name: 'consumption_band_rule_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityConsumptionBandRule $consumptionBandRule = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    public function __construct(
        ?Workspace $workspace = null,
        ?Accrual $accrual = null,
        ?ElectricityMeter $electricityMeter = null,
        ?ElectricityTariffProfile $tariffProfile = null,
        ?ElectricityTariffPeriod $tariffPeriod = null,
        ?ElectricityConsumptionBandRule $consumptionBandRule = null,
    ) {
        $this->workspace = $workspace;
        $this->accrual = $accrual;
        $this->electricityMeter = $electricityMeter;
        $this->tariffProfile = $tariffProfile;
        $this->tariffPeriod = $tariffPeriod;
        $this->consumptionBandRule = $consumptionBandRule;
        $this->createdAt = new DateTimeImmutable();
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

    public function getTariffProfile(): ?ElectricityTariffProfile
    {
        return $this->tariffProfile;
    }

    public function getTariffPeriod(): ?ElectricityTariffPeriod
    {
        return $this->tariffPeriod;
    }

    public function getConsumptionBandRule(): ?ElectricityConsumptionBandRule
    {
        return $this->consumptionBandRule;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
