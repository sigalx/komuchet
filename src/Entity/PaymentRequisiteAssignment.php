<?php

namespace App\Entity;

use App\Enum\AccrualType;
use App\Repository\PaymentRequisiteAssignmentRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaymentRequisiteAssignmentRepository::class)]
#[ORM\Table(name: 'payment_requisite_assignments')]
#[ORM\UniqueConstraint(name: 'ux_payment_requisite_assignments_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_payment_requisite_assignments_default_open', columns: ['workspace_uuid'], options: ['where' => 'accrual_type IS NULL AND valid_to IS NULL AND closed_at IS NULL'])]
#[ORM\UniqueConstraint(name: 'ux_payment_requisite_assignments_type_open', columns: ['workspace_uuid', 'accrual_type'], options: ['where' => 'accrual_type IS NOT NULL AND valid_to IS NULL AND closed_at IS NULL'])]
#[ORM\Index(name: 'ix_payment_requisite_assignments_profile', columns: ['workspace_uuid', 'payment_requisite_profile_uuid'])]
#[ORM\Index(name: 'ix_payment_requisite_assignments_scope_validity', columns: ['workspace_uuid', 'accrual_type', 'valid_from', 'valid_to'])]
class PaymentRequisiteAssignment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne(targetEntity: PaymentRequisiteProfile::class)]
    #[ORM\JoinColumn(name: 'payment_requisite_profile_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?PaymentRequisiteProfile $paymentRequisiteProfile = null;

    #[ORM\Column(name: 'accrual_type', enumType: AccrualType::class, nullable: true, columnDefinition: 'accrual_type')]
    private ?AccrualType $accrualType = null;

    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validTo = null;

    #[ORM\Column(name: 'assigned_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $assignedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'assigned_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $assignedBy = null;

    #[ORM\Column(name: 'closed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'closed_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $closedBy = null;

    #[ORM\Column(name: 'close_reason', type: Types::TEXT, nullable: true)]
    private ?string $closeReason = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?PaymentRequisiteProfile $paymentRequisiteProfile = null,
        ?AccrualType $accrualType = null,
        ?DateTimeImmutable $validFrom = null,
        ?User $assignedBy = null,
    ) {
        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->paymentRequisiteProfile = $paymentRequisiteProfile;
        $this->accrualType = $accrualType;
        $this->validFrom = $validFrom ?? new DateTimeImmutable('today');
        $this->assignedAt = new DateTimeImmutable();
        $this->assignedBy = $assignedBy;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getPaymentRequisiteProfile(): ?PaymentRequisiteProfile
    {
        return $this->paymentRequisiteProfile;
    }

    public function getAccrualType(): ?AccrualType
    {
        return $this->accrualType;
    }

    public function getScopeLabel(): string
    {
        return $this->accrualType?->label() ?? 'Все начисления';
    }

    public function getValidFrom(): DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidTo(): ?DateTimeImmutable
    {
        return $this->validTo;
    }

    public function close(string $reason, ?User $closedBy = null, ?DateTimeImmutable $validTo = null): static
    {
        $this->closedAt = new DateTimeImmutable();
        $this->closedBy = $closedBy;
        $this->closeReason = trim($reason);
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

    public function getClosedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getClosedBy(): ?User
    {
        return $this->closedBy;
    }

    public function getCloseReason(): ?string
    {
        return $this->closeReason;
    }

    public function isOpen(): bool
    {
        return $this->closedAt === null;
    }
}
