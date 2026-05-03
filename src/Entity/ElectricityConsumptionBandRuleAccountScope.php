<?php

namespace App\Entity;

use App\Enum\ElectricityConsumptionBandRuleScopeMode;
use App\Repository\ElectricityConsumptionBandRuleAccountScopeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ElectricityConsumptionBandRuleAccountScopeRepository::class)]
#[ORM\Table(name: 'electricity_consumption_band_rule_account_scopes')]
#[ORM\Index(name: 'ix_electricity_consumption_band_rule_account_scopes_account', columns: ['workspace_uuid', 'account_uuid'])]
class ElectricityConsumptionBandRuleAccountScope
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
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Account $account = null;

    #[ORM\Column(enumType: ElectricityConsumptionBandRuleScopeMode::class, options: ['default' => 'include'], columnDefinition: 'electricity_consumption_band_rule_scope_mode')]
    private ElectricityConsumptionBandRuleScopeMode $mode = ElectricityConsumptionBandRuleScopeMode::Include;

    public function __construct(
        ?Workspace $workspace = null,
        ?ElectricityConsumptionBandRule $rule = null,
        ?Account $account = null,
        ElectricityConsumptionBandRuleScopeMode $mode = ElectricityConsumptionBandRuleScopeMode::Include,
    ) {
        $this->workspace = $workspace;
        $this->rule = $rule;
        $this->account = $account;
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

    public function getAccount(): ?Account
    {
        return $this->account;
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
