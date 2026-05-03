<?php

namespace App\Entity;

use App\Enum\ElectricityConsumptionBandRuleScopeMode;
use App\Repository\ElectricityConsumptionBandRuleGroupScopeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElectricityConsumptionBandRuleGroupScopeRepository::class)]
#[ORM\Table(name: 'electricity_consumption_band_rule_group_scopes')]
#[ORM\Index(name: 'ix_electricity_consumption_band_rule_group_scopes_group', columns: ['workspace_uuid', 'account_group_uuid'])]
class ElectricityConsumptionBandRuleGroupScope
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
    #[ORM\ManyToOne(targetEntity: AccountGroup::class)]
    #[ORM\JoinColumn(name: 'account_group_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?AccountGroup $accountGroup = null;

    #[ORM\Column(enumType: ElectricityConsumptionBandRuleScopeMode::class, options: ['default' => 'include'], columnDefinition: 'electricity_consumption_band_rule_scope_mode')]
    private ElectricityConsumptionBandRuleScopeMode $mode = ElectricityConsumptionBandRuleScopeMode::Include;

    public function __construct(
        ?Workspace $workspace = null,
        ?ElectricityConsumptionBandRule $rule = null,
        ?AccountGroup $accountGroup = null,
        ElectricityConsumptionBandRuleScopeMode $mode = ElectricityConsumptionBandRuleScopeMode::Include,
    ) {
        $this->workspace = $workspace;
        $this->rule = $rule;
        $this->accountGroup = $accountGroup;
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

    public function getAccountGroup(): ?AccountGroup
    {
        return $this->accountGroup;
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
