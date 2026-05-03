<?php

namespace App\Entity;

use App\Repository\ElectricityTariffRateRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElectricityTariffRateRepository::class)]
#[ORM\Table(name: 'electricity_tariff_rates')]
#[ORM\Index(name: 'ix_electricity_tariff_rates_zone_band', columns: ['workspace_uuid', 'tariff_zone_uuid', 'consumption_band_uuid'])]
class ElectricityTariffRate
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityTariffPeriod::class)]
    #[ORM\JoinColumn(name: 'tariff_period_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffPeriod $tariffPeriod = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityTariffZone::class)]
    #[ORM\JoinColumn(name: 'tariff_zone_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffZone $tariffZone = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityConsumptionBand::class)]
    #[ORM\JoinColumn(name: 'consumption_band_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityConsumptionBand $consumptionBand = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 6)]
    private string $rate = '0';

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $updatedBy = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?ElectricityTariffPeriod $tariffPeriod = null,
        ?ElectricityTariffZone $tariffZone = null,
        ?ElectricityConsumptionBand $consumptionBand = null,
        string $rate = '0',
    ) {
        $now = new DateTimeImmutable();

        $this->workspace = $workspace;
        $this->tariffPeriod = $tariffPeriod;
        $this->tariffZone = $tariffZone;
        $this->consumptionBand = $consumptionBand;
        $this->rate = $rate;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getTariffPeriod(): ?ElectricityTariffPeriod
    {
        return $this->tariffPeriod;
    }

    public function getTariffZone(): ?ElectricityTariffZone
    {
        return $this->tariffZone;
    }

    public function getConsumptionBand(): ?ElectricityConsumptionBand
    {
        return $this->consumptionBand;
    }

    public function getRate(): string
    {
        return $this->rate;
    }

    public function setRate(string $rate): static
    {
        $this->rate = trim(str_replace(',', '.', $rate));

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(?User $updatedBy = null): static
    {
        $this->updatedAt = new DateTimeImmutable();
        $this->updatedBy = $updatedBy;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }
}
