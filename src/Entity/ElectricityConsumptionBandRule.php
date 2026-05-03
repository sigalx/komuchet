<?php

namespace App\Entity;

use App\Enum\ElectricityConsumptionBandAllocationMethod;
use App\Repository\ElectricityConsumptionBandRuleRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ElectricityConsumptionBandRuleRepository::class)]
#[ORM\Table(name: 'electricity_consumption_band_rules')]
#[ORM\UniqueConstraint(name: 'ux_electricity_consumption_band_rules_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\Index(name: 'ix_electricity_consumption_band_rules_profile_month_period', columns: ['workspace_uuid', 'tariff_profile_uuid', 'month', 'valid_from', 'valid_to'], options: ['where' => 'deleted_at IS NULL'])]
class ElectricityConsumptionBandRule
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne(targetEntity: ElectricityTariffProfile::class)]
    #[ORM\JoinColumn(name: 'tariff_profile_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffProfile $tariffProfile = null;

    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validTo = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $month = 1;

    #[ORM\Column(name: 'allocation_method', enumType: ElectricityConsumptionBandAllocationMethod::class, options: ['default' => 'total_proportional'], columnDefinition: 'electricity_consumption_band_allocation_method')]
    private ElectricityConsumptionBandAllocationMethod $allocationMethod = ElectricityConsumptionBandAllocationMethod::TotalProportional;

    #[ORM\Column(options: ['default' => 100])]
    private int $priority = 100;

    #[ORM\Column(name: 'source_document', type: Types::TEXT, nullable: true)]
    private ?string $sourceDocument = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'updated_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $updatedBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'deleted_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $deletedBy = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?ElectricityTariffProfile $tariffProfile = null,
        ?DateTimeImmutable $validFrom = null,
        int $month = 1,
    ) {
        $now = new DateTimeImmutable();

        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->tariffProfile = $tariffProfile;
        $this->validFrom = $validFrom ?? new DateTimeImmutable('today');
        $this->month = $month;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function setWorkspace(Workspace $workspace): static
    {
        $this->workspace = $workspace;

        return $this;
    }

    public function getTariffProfile(): ?ElectricityTariffProfile
    {
        return $this->tariffProfile;
    }

    public function setTariffProfile(ElectricityTariffProfile $tariffProfile): static
    {
        $this->tariffProfile = $tariffProfile;

        return $this;
    }

    public function getValidFrom(): DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidTo(): ?DateTimeImmutable
    {
        return $this->validTo;
    }

    public function setValidTo(?DateTimeImmutable $validTo): static
    {
        $this->validTo = $validTo;

        return $this;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function setMonth(int $month): static
    {
        $this->month = $month;

        return $this;
    }

    public function getAllocationMethod(): ElectricityConsumptionBandAllocationMethod
    {
        return $this->allocationMethod;
    }

    public function setAllocationMethod(ElectricityConsumptionBandAllocationMethod $allocationMethod): static
    {
        $this->allocationMethod = $allocationMethod;

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): static
    {
        $this->priority = $priority;

        return $this;
    }

    public function getSourceDocument(): ?string
    {
        return $this->sourceDocument;
    }

    public function setSourceDocument(?string $sourceDocument): static
    {
        $sourceDocument = $sourceDocument === null ? null : trim($sourceDocument);
        $this->sourceDocument = $sourceDocument === '' ? null : $sourceDocument;

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $notes = $notes === null ? null : trim($notes);
        $this->notes = $notes === '' ? null : $notes;

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

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function delete(?User $deletedBy = null): static
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->deletedBy = $deletedBy;

        return $this->touch($deletedBy);
    }

    public function isActive(): bool
    {
        return $this->deletedAt === null;
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

    public function getDeletedBy(): ?User
    {
        return $this->deletedBy;
    }
}
