<?php

namespace App\Entity;

use App\Enum\ElectricityConsumptionBandRuleScopeMode;
use App\Repository\ElectricityConsumptionBandRuleAllScopeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElectricityConsumptionBandRuleAllScopeRepository::class)]
#[ORM\Table(name: 'electricity_consumption_band_rule_all_scopes')]
class ElectricityConsumptionBandRuleAllScope
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: ElectricityConsumptionBandRule::class)]
    #[ORM\JoinColumn(name: 'rule_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityConsumptionBandRule $rule = null;

    #[ORM\Column(enumType: ElectricityConsumptionBandRuleScopeMode::class, options: ['default' => 'include'], columnDefinition: 'electricity_consumption_band_rule_scope_mode')]
    private ElectricityConsumptionBandRuleScopeMode $mode = ElectricityConsumptionBandRuleScopeMode::Include;

    public function __construct(
        ?Workspace $workspace = null,
        ?ElectricityConsumptionBandRule $rule = null,
        ElectricityConsumptionBandRuleScopeMode $mode = ElectricityConsumptionBandRuleScopeMode::Include,
    ) {
        $this->workspace = $workspace;
        $this->rule = $rule;
        $this->mode = $mode;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getRule(): ?ElectricityConsumptionBandRule
    {
        return $this->rule;
    }

    public function getMode(): ElectricityConsumptionBandRuleScopeMode
    {
        return $this->mode;
    }

    public function setMode(ElectricityConsumptionBandRuleScopeMode $mode): static
    {
        $this->mode = $mode;

        return $this;
    }
}
