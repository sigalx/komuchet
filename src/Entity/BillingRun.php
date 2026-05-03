<?php

namespace App\Entity;

use App\Enum\BillingRunKind;
use App\Repository\BillingRunRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BillingRunRepository::class)]
#[ORM\Table(name: 'billing_runs')]
#[ORM\UniqueConstraint(name: 'ux_billing_runs_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_billing_runs_active_kind_period', columns: ['workspace_uuid', 'kind', 'period_start', 'period_end'], options: ['where' => 'cancelled_at IS NULL'])]
#[ORM\Index(name: 'ix_billing_runs_kind_period', columns: ['workspace_uuid', 'kind', 'period_start', 'period_end'])]
#[ORM\Index(name: 'ix_billing_runs_accruals_generated_by', columns: ['accruals_generated_by'], options: ['where' => 'accruals_generated_by IS NOT NULL'])]
class BillingRun
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Column(enumType: BillingRunKind::class, columnDefinition: 'billing_run_kind')]
    private BillingRunKind $kind = BillingRunKind::Electricity;

    #[ORM\Column(name: 'period_start', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $periodStart;

    #[ORM\Column(name: 'period_end', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $periodEnd;

    #[ORM\Column(name: 'generated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $generatedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'generated_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $generatedBy = null;

    #[ORM\Column(name: 'accruals_generated_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $accrualsGeneratedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'accruals_generated_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $accrualsGeneratedBy = null;

    #[ORM\Column(name: 'posted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $postedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'posted_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $postedBy = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'cancelled_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $cancelledBy = null;

    #[ORM\Column(name: 'cancellation_reason', type: Types::TEXT, nullable: true)]
    private ?string $cancellationReason = null;

    public function __construct(
        ?Workspace $workspace = null,
        BillingRunKind $kind = BillingRunKind::Electricity,
        ?DateTimeImmutable $periodStart = null,
        ?DateTimeImmutable $periodEnd = null,
        ?User $generatedBy = null,
    ) {
        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->kind = $kind;
        $this->periodStart = $periodStart ?? new DateTimeImmutable('first day of this month');
        $this->periodEnd = $periodEnd ?? $this->periodStart->modify('+1 month');
        $this->generatedAt = new DateTimeImmutable();
        $this->generatedBy = $generatedBy;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getKind(): BillingRunKind
    {
        return $this->kind;
    }

    public function setKind(BillingRunKind $kind): static
    {
        $this->kind = $kind;

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

    public function getGeneratedAt(): DateTimeImmutable
    {
        return $this->generatedAt;
    }

    public function getGeneratedBy(): ?User
    {
        return $this->generatedBy;
    }

    public function getAccrualsGeneratedAt(): ?DateTimeImmutable
    {
        return $this->accrualsGeneratedAt;
    }

    public function markAccrualsGenerated(?User $generatedBy = null): static
    {
        $this->accrualsGeneratedAt = new DateTimeImmutable();
        $this->accrualsGeneratedBy = $generatedBy;

        return $this;
    }

    public function getAccrualsGeneratedBy(): ?User
    {
        return $this->accrualsGeneratedBy;
    }

    public function getPostedAt(): ?DateTimeImmutable
    {
        return $this->postedAt;
    }

    public function post(?User $postedBy = null): static
    {
        $this->postedAt = new DateTimeImmutable();
        $this->postedBy = $postedBy;

        return $this;
    }

    public function getPostedBy(): ?User
    {
        return $this->postedBy;
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

        return $this;
    }

    public function getCancelledBy(): ?User
    {
        return $this->cancelledBy;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function isDraft(): bool
    {
        return $this->postedAt === null && $this->cancelledAt === null;
    }

    public function isPosted(): bool
    {
        return $this->postedAt !== null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }
}
