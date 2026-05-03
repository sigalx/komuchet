<?php

namespace App\Entity;

use App\Repository\AccountStatementDeliveryAttemptRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AccountStatementDeliveryAttemptRepository::class)]
#[ORM\Table(name: 'account_statement_delivery_attempts')]
#[ORM\Index(name: 'ix_account_statement_delivery_attempts_queued', columns: ['workspace_uuid', 'queued_at'], options: ['where' => 'started_at IS NULL AND succeeded_at IS NULL AND failed_at IS NULL'])]
#[ORM\Index(name: 'ix_account_statement_delivery_attempts_finished', columns: ['workspace_uuid', 'succeeded_at', 'failed_at'])]
class AccountStatementDeliveryAttempt
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: AccountStatementDelivery::class, inversedBy: 'attempts')]
    #[ORM\JoinColumn(name: 'delivery_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?AccountStatementDelivery $delivery = null;

    #[ORM\Id]
    #[ORM\Column(name: 'attempt_number', type: Types::INTEGER)]
    private int $attemptNumber = 1;

    #[ORM\Column(name: 'queued_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $queuedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'queued_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $queuedBy = null;

    #[ORM\Column(name: 'started_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $startedAt = null;

    #[ORM\Column(name: 'succeeded_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $succeededAt = null;

    #[ORM\Column(name: 'failed_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $failedAt = null;

    #[ORM\Column(name: 'failure_reason', type: Types::TEXT, nullable: true)]
    private ?string $failureReason = null;

    #[ORM\Column(name: 'provider_message_id', type: Types::TEXT, nullable: true)]
    private ?string $providerMessageId = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?AccountStatementDelivery $delivery = null,
        int $attemptNumber = 1,
        ?User $queuedBy = null,
    ) {
        $this->workspace = $workspace;
        $this->delivery = $delivery;
        $this->attemptNumber = $attemptNumber;
        $this->queuedAt = new DateTimeImmutable();
        $this->queuedBy = $queuedBy;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getDelivery(): ?AccountStatementDelivery
    {
        return $this->delivery;
    }

    public function getAttemptNumber(): int
    {
        return $this->attemptNumber;
    }

    public function getQueuedAt(): DateTimeImmutable
    {
        return $this->queuedAt;
    }

    public function getQueuedBy(): ?User
    {
        return $this->queuedBy;
    }

    public function getStartedAt(): ?DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function markStarted(): static
    {
        $this->startedAt ??= new DateTimeImmutable();

        return $this;
    }

    public function getSucceededAt(): ?DateTimeImmutable
    {
        return $this->succeededAt;
    }

    public function markSucceeded(?string $providerMessageId = null): static
    {
        $this->markStarted();
        $this->succeededAt = new DateTimeImmutable();
        $this->failedAt = null;
        $this->failureReason = null;
        $this->setProviderMessageId($providerMessageId);

        return $this;
    }

    public function getFailedAt(): ?DateTimeImmutable
    {
        return $this->failedAt;
    }

    public function markFailed(string $reason): static
    {
        $this->markStarted();
        $this->failedAt = new DateTimeImmutable();
        $this->failureReason = trim($reason);
        $this->succeededAt = null;
        $this->providerMessageId = null;

        return $this;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    public function getProviderMessageId(): ?string
    {
        return $this->providerMessageId;
    }

    public function setProviderMessageId(?string $providerMessageId): static
    {
        $providerMessageId = $providerMessageId === null ? null : trim($providerMessageId);
        $this->providerMessageId = $providerMessageId === '' ? null : $providerMessageId;

        return $this;
    }

    public function getStatusCode(): string
    {
        if ($this->failedAt !== null) {
            return 'failed';
        }

        if ($this->succeededAt !== null) {
            return 'sent';
        }

        if ($this->startedAt !== null) {
            return 'sending';
        }

        return 'queued';
    }

    public function getStatusLabel(): string
    {
        return match ($this->getStatusCode()) {
            'failed' => 'Ошибка',
            'sent' => 'Отправлено',
            'sending' => 'Отправляется',
            default => 'В очереди',
        };
    }

    public function getStatusBadgeClass(): string
    {
        return match ($this->getStatusCode()) {
            'failed' => 'text-bg-danger',
            'sent' => 'text-bg-success',
            'sending' => 'text-bg-primary',
            default => 'text-bg-warning',
        };
    }
}
