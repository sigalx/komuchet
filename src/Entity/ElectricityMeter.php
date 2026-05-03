<?php

namespace App\Entity;

use App\Repository\ElectricityMeterRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ElectricityMeterRepository::class)]
#[ORM\Table(name: 'electricity_meters')]
#[ORM\UniqueConstraint(name: 'ux_electricity_meters_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_electricity_meters_one_active_per_account', columns: ['workspace_uuid', 'account_uuid'], options: ['where' => 'removed_on IS NULL AND deleted_at IS NULL'])]
#[ORM\Index(name: 'ix_electricity_meters_account', columns: ['workspace_uuid', 'account_uuid', 'installed_on'])]
class ElectricityMeter
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Account $account = null;

    #[ORM\Column(name: 'serial_number', type: Types::TEXT, nullable: true)]
    private ?string $serialNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $model = null;

    #[ORM\Column(name: 'installed_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $installedOn;

    #[ORM\Column(name: 'removed_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $removedOn = null;

    #[ORM\Column(name: 'verified_on', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $verifiedOn = null;

    #[ORM\Column(name: 'verification_valid_until', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $verificationValidUntil = null;

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

    public function __construct(?Workspace $workspace = null, ?Account $account = null, ?DateTimeImmutable $installedOn = null)
    {
        $now = new DateTimeImmutable();

        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->account = $account;
        $this->installedOn = $installedOn ?? new DateTimeImmutable('today');
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

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getSerialNumber(): ?string
    {
        return $this->serialNumber;
    }

    public function setSerialNumber(?string $serialNumber): static
    {
        $serialNumber = $serialNumber === null ? null : trim($serialNumber);
        $this->serialNumber = $serialNumber === '' ? null : $serialNumber;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $model = $model === null ? null : trim($model);
        $this->model = $model === '' ? null : $model;

        return $this;
    }

    public function getInstalledOn(): DateTimeImmutable
    {
        return $this->installedOn;
    }

    public function setInstalledOn(DateTimeImmutable $installedOn): static
    {
        $this->installedOn = $installedOn;

        return $this;
    }

    public function getRemovedOn(): ?DateTimeImmutable
    {
        return $this->removedOn;
    }

    public function setRemovedOn(?DateTimeImmutable $removedOn): static
    {
        $this->removedOn = $removedOn;

        return $this;
    }

    public function getVerifiedOn(): ?DateTimeImmutable
    {
        return $this->verifiedOn;
    }

    public function setVerifiedOn(?DateTimeImmutable $verifiedOn): static
    {
        $this->verifiedOn = $verifiedOn;

        return $this;
    }

    public function getVerificationValidUntil(): ?DateTimeImmutable
    {
        return $this->verificationValidUntil;
    }

    public function setVerificationValidUntil(?DateTimeImmutable $verificationValidUntil): static
    {
        $this->verificationValidUntil = $verificationValidUntil;

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
        return $this->removedOn === null && $this->deletedAt === null;
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
