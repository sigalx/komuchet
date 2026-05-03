<?php

namespace App\Entity;

use App\Enum\AccrualType;
use App\Repository\AccrualRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccrualRepository::class)]
#[ORM\Table(name: 'accruals')]
#[ORM\UniqueConstraint(name: 'ux_accruals_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_accruals_one_posted_per_period', columns: ['workspace_uuid', 'account_uuid', 'type', 'period_start', 'period_end'], options: ['where' => 'posted_at IS NOT NULL AND cancelled_at IS NULL AND replacing_accrual_uuid IS NULL'])]
#[ORM\UniqueConstraint(name: 'ux_accruals_replacing', columns: ['workspace_uuid', 'replacing_accrual_uuid'], options: ['where' => 'replacing_accrual_uuid IS NOT NULL'])]
#[ORM\Index(name: 'ix_accruals_account_period', columns: ['workspace_uuid', 'account_uuid', 'period_start', 'period_end'])]
class Accrual
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

    #[ORM\ManyToOne(targetEntity: BillingRun::class)]
    #[ORM\JoinColumn(name: 'billing_run_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?BillingRun $billingRun = null;

    #[ORM\Column(enumType: AccrualType::class, columnDefinition: 'accrual_type')]
    private AccrualType $type = AccrualType::Electricity;

    #[ORM\Column(name: 'period_start', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $periodStart;

    #[ORM\Column(name: 'period_end', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $periodEnd;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $amount = '0';

    #[ORM\Column(name: 'posted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $postedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'posted_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $postedBy = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'replacing_accrual_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?self $replacingAccrual = null;

    #[ORM\Column(name: 'replaced_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $replacedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'replaced_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $replacedBy = null;

    #[ORM\Column(name: 'replacement_reason', type: Types::TEXT, nullable: true)]
    private ?string $replacementReason = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'cancelled_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $cancelledBy = null;

    #[ORM\Column(name: 'cancellation_reason', type: Types::TEXT, nullable: true)]
    private ?string $cancellationReason = null;

    #[ORM\Column(name: 'calculated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $calculatedAt;

    #[ORM\Column(name: 'calculation_version', type: Types::TEXT, nullable: true)]
    private ?string $calculationVersion = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

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
        ?Account $account = null,
        AccrualType $type = AccrualType::Electricity,
        ?DateTimeImmutable $periodStart = null,
        ?DateTimeImmutable $periodEnd = null,
        string $amount = '0',
        ?User $createdBy = null,
    ) {
        $now = new DateTimeImmutable();

        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->account = $account;
        $this->setType($type);
        $this->periodStart = $periodStart ?? new DateTimeImmutable('first day of this month');
        $this->periodEnd = $periodEnd ?? $this->periodStart->modify('+1 month');
        $this->setAmount($amount);
        $this->calculatedAt = $now;
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->createdBy = $createdBy;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getBillingRun(): ?BillingRun
    {
        return $this->billingRun;
    }

    public function setBillingRun(?BillingRun $billingRun): static
    {
        $this->billingRun = $billingRun;

        return $this;
    }

    public function getType(): AccrualType
    {
        return $this->type;
    }

    public function setType(AccrualType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getPeriodStart(): DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(DateTimeImmutable $periodStart): static
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getPeriodEnd(): DateTimeImmutable
    {
        return $this->periodEnd;
    }

    public function setPeriodEnd(DateTimeImmutable $periodEnd): static
    {
        $this->periodEnd = $periodEnd;

        return $this;
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = trim(str_replace(',', '.', $amount));

        return $this;
    }

    public function getPostedAt(): ?DateTimeImmutable
    {
        return $this->postedAt;
    }

    public function post(?User $postedBy = null): static
    {
        $this->postedAt = new DateTimeImmutable();
        $this->postedBy = $postedBy;

        return $this->touch($postedBy);
    }

    public function getPostedBy(): ?User
    {
        return $this->postedBy;
    }

    public function getReplacingAccrual(): ?self
    {
        return $this->replacingAccrual;
    }

    public function markReplacedBy(self $replacement, string $reason, ?User $replacedBy = null): static
    {
        $this->replacingAccrual = $replacement;
        $this->replacedAt = new DateTimeImmutable();
        $this->replacedBy = $replacedBy;
        $this->replacementReason = trim($reason);

        return $this->touch($replacedBy);
    }

    public function getReplacedAt(): ?DateTimeImmutable
    {
        return $this->replacedAt;
    }

    public function getReplacementReason(): ?string
    {
        return $this->replacementReason;
    }

    public function getReplacedBy(): ?User
    {
        return $this->replacedBy;
    }

    public function getCancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function cancel(string $reason, ?User $cancelledBy = null): static
    {
        $this->cancelledAt = new DateTimeImmutable();
        $this->cancelledBy = $cancelledBy;
        $this->cancellationReason = trim($reason);

        return $this->touch($cancelledBy);
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function getCancelledBy(): ?User
    {
        return $this->cancelledBy;
    }

    public function getCalculatedAt(): DateTimeImmutable
    {
        return $this->calculatedAt;
    }

    public function getCalculationVersion(): ?string
    {
        return $this->calculationVersion;
    }

    public function setCalculationVersion(?string $calculationVersion): static
    {
        $calculationVersion = $calculationVersion === null ? null : trim($calculationVersion);
        $this->calculationVersion = $calculationVersion === '' ? null : $calculationVersion;

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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function isActivePosted(): bool
    {
        return $this->postedAt !== null && $this->cancelledAt === null && $this->replacingAccrual === null;
    }

    public function isDraft(): bool
    {
        return $this->postedAt === null && $this->cancelledAt === null && $this->replacingAccrual === null;
    }
}
