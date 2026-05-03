<?php

namespace App\Entity;

use App\Repository\ElectricityConsumptionBandRuleRangeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElectricityConsumptionBandRuleRangeRepository::class)]
#[ORM\Table(name: 'electricity_consumption_band_rule_ranges')]
class ElectricityConsumptionBandRuleRange
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityConsumptionBandRule::class)]
    #[ORM\JoinColumn(name: 'rule_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityConsumptionBandRule $rule = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityConsumptionBand::class)]
    #[ORM\JoinColumn(name: 'consumption_band_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityConsumptionBand $consumptionBand = null;

    #[ORM\Column(name: 'lower_bound_kwh', type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $lowerBoundKwh = '0';

    #[ORM\Column(name: 'upper_bound_kwh', type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $upperBoundKwh = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?ElectricityConsumptionBandRule $rule = null,
        ?ElectricityConsumptionBand $consumptionBand = null,
        string $lowerBoundKwh = '0',
        ?string $upperBoundKwh = null,
    ) {
        $this->workspace = $workspace;
        $this->rule = $rule;
        $this->consumptionBand = $consumptionBand;
        $this->lowerBoundKwh = $lowerBoundKwh;
        $this->upperBoundKwh = $upperBoundKwh;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getRule(): ?ElectricityConsumptionBandRule
    {
        return $this->rule;
    }

    public function getConsumptionBand(): ?ElectricityConsumptionBand
    {
        return $this->consumptionBand;
    }

    public function getLowerBoundKwh(): string
    {
        return $this->lowerBoundKwh;
    }

    public function setLowerBoundKwh(string $lowerBoundKwh): static
    {
        $this->lowerBoundKwh = trim(str_replace(',', '.', $lowerBoundKwh));

        return $this;
    }

    public function getUpperBoundKwh(): ?string
    {
        return $this->upperBoundKwh;
    }

    public function setUpperBoundKwh(?string $upperBoundKwh): static
    {
        $upperBoundKwh = $upperBoundKwh === null ? null : trim(str_replace(',', '.', $upperBoundKwh));
        $this->upperBoundKwh = $upperBoundKwh === '' ? null : $upperBoundKwh;

        return $this;
    }
}
