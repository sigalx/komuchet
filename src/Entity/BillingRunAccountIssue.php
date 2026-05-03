<?php

namespace App\Entity;

use App\Enum\BillingRunAccountIssueCloseReason;
use App\Enum\BillingRunAccountIssueType;
use App\Repository\BillingRunAccountIssueRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: BillingRunAccountIssueRepository::class)]
#[ORM\Table(name: 'billing_run_account_issues')]
#[ORM\UniqueConstraint(name: 'ux_billing_run_account_issues_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_billing_run_account_issues_open', columns: ['workspace_uuid', 'billing_run_uuid', 'account_uuid', 'issue_type'], options: ['where' => 'closed_at IS NULL'])]
#[ORM\Index(name: 'ix_billing_run_account_issues_account', columns: ['workspace_uuid', 'account_uuid', 'created_at'])]
#[ORM\Index(name: 'ix_billing_run_account_issues_run', columns: ['workspace_uuid', 'billing_run_uuid'])]
class BillingRunAccountIssue
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne(targetEntity: BillingRun::class)]
    #[ORM\JoinColumn(name: 'billing_run_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?BillingRun $billingRun = null;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Account $account = null;

    #[ORM\Column(name: 'issue_type', enumType: BillingRunAccountIssueType::class, columnDefinition: 'billing_run_account_issue_type')]
    private BillingRunAccountIssueType $issueType;

    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column(name: 'closed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $closedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'closed_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $closedBy = null;

    #[ORM\Column(name: 'close_reason', enumType: BillingRunAccountIssueCloseReason::class, nullable: true, columnDefinition: 'billing_run_account_issue_close_reason')]
    private ?BillingRunAccountIssueCloseReason $closeReason = null;

    #[ORM\Column(name: 'close_comment', type: Types::TEXT, nullable: true)]
    private ?string $closeComment = null;

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
        ?BillingRun $billingRun = null,
        ?Account $account = null,
        BillingRunAccountIssueType $issueType = BillingRunAccountIssueType::CalculationError,
        string $message = '',
        ?User $createdBy = null,
    ) {
        $now = new DateTimeImmutable();

        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->billingRun = $billingRun;
        $this->account = $account;
        $this->issueType = $issueType;
        $this->message = $message;
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

    public function getBillingRun(): ?BillingRun
    {
        return $this->billingRun;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getIssueType(): BillingRunAccountIssueType
    {
        return $this->issueType;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message, ?User $updatedBy = null): static
    {
        $this->message = trim($message);

        return $this->touch($updatedBy);
    }

    public function getClosedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function close(BillingRunAccountIssueCloseReason $reason, ?string $comment = null, ?User $closedBy = null): static
    {
        $comment = $comment === null ? null : trim($comment);

        $this->closedAt = new DateTimeImmutable();
        $this->closedBy = $closedBy;
        $this->closeReason = $reason;
        $this->closeComment = $comment === '' ? null : $comment;

        return $this->touch($closedBy);
    }

    public function getClosedBy(): ?User
    {
        return $this->closedBy;
    }

    public function getCloseReason(): ?BillingRunAccountIssueCloseReason
    {
        return $this->closeReason;
    }

    public function getCloseComment(): ?string
    {
        return $this->closeComment;
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

    public function isOpen(): bool
    {
        return $this->closedAt === null;
    }
}
