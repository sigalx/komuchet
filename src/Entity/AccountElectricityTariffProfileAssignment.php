<?php

namespace App\Entity;

use App\Repository\AccountElectricityTariffProfileAssignmentRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountElectricityTariffProfileAssignmentRepository::class)]
#[ORM\Table(name: 'account_electricity_tariff_profile_assignments')]
#[ORM\Index(name: 'ix_account_electricity_tariff_profile_assignments_profile', columns: ['workspace_uuid', 'tariff_profile_uuid', 'valid_from', 'valid_to'])]
class AccountElectricityTariffProfileAssignment
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Account $account = null;

    #[ORM\Id]
    #[ORM\Column(name: 'valid_from', type: Types::STRING, length: 10, columnDefinition: 'date')]
    private string $validFrom;

    #[ORM\ManyToOne(targetEntity: ElectricityTariffProfile::class)]
    #[ORM\JoinColumn(name: 'tariff_profile_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffProfile $tariffProfile = null;

    #[ORM\Column(name: 'valid_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validTo = null;

    #[ORM\Column(name: 'assigned_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $assignedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $assignedBy = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?Account $account = null,
        ?ElectricityTariffProfile $tariffProfile = null,
        ?DateTimeImmutable $validFrom = null,
        ?User $assignedBy = null,
    ) {
        $this->workspace = $workspace;
        $this->account = $account;
        $this->tariffProfile = $tariffProfile;
        $this->validFrom = ($validFrom ?? new DateTimeImmutable('today'))->format('Y-m-d');
        $this->assignedAt = new DateTimeImmutable();
        $this->assignedBy = $assignedBy;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getTariffProfile(): ?ElectricityTariffProfile
    {
        return $this->tariffProfile;
    }

    public function getValidFrom(): DateTimeImmutable
    {
        $validFrom = DateTimeImmutable::createFromFormat('!Y-m-d', $this->validFrom);

        if (!$validFrom instanceof DateTimeImmutable) {
            throw new \LogicException('Invalid account electricity tariff profile assignment valid_from value.');
        }

        return $validFrom;
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

    public function getAssignedAt(): DateTimeImmutable
    {
        return $this->assignedAt;
    }

    public function getAssignedBy(): ?User
    {
        return $this->assignedBy;
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
}
