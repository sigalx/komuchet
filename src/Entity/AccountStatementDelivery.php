<?php

namespace App\Entity;

use App\Enum\AccountStatementDeliveryChannel;
use App\Repository\AccountStatementDeliveryRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccountStatementDeliveryRepository::class)]
#[ORM\Table(name: 'account_statement_deliveries')]
#[ORM\UniqueConstraint(name: 'ux_account_statement_deliveries_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_account_statement_deliveries_active_recipient', columns: ['workspace_uuid', 'account_statement_uuid', 'channel', 'recipient_email_normalized'], options: ['where' => 'cancelled_at IS NULL'])]
#[ORM\Index(name: 'ix_account_statement_deliveries_statement', columns: ['workspace_uuid', 'account_statement_uuid', 'created_at'])]
#[ORM\Index(name: 'ix_account_statement_deliveries_subscriber', columns: ['workspace_uuid', 'recipient_subscriber_uuid'], options: ['where' => 'recipient_subscriber_uuid IS NOT NULL'])]
class AccountStatementDelivery
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne(targetEntity: AccountStatementSnapshot::class)]
    #[ORM\JoinColumn(name: 'account_statement_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?AccountStatementSnapshot $accountStatement = null;

    #[ORM\ManyToOne(targetEntity: Subscriber::class)]
    #[ORM\JoinColumn(name: 'recipient_subscriber_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?Subscriber $recipientSubscriber = null;

    #[ORM\Column(enumType: AccountStatementDeliveryChannel::class, columnDefinition: 'account_statement_delivery_channel')]
    private AccountStatementDeliveryChannel $channel = AccountStatementDeliveryChannel::Email;

    #[ORM\Column(name: 'recipient_email', type: Types::TEXT)]
    private string $recipientEmail = '';

    #[ORM\Column(name: 'recipient_email_normalized', type: Types::TEXT)]
    private string $recipientEmailNormalized = '';

    #[ORM\Column(name: 'recipient_name', type: Types::TEXT, nullable: true)]
    private ?string $recipientName = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $createdBy = null;

    #[ORM\Column(name: 'cancelled_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'cancelled_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $cancelledBy = null;

    #[ORM\Column(name: 'cancellation_reason', type: Types::TEXT, nullable: true)]
    private ?string $cancellationReason = null;

    /**
     * @var Collection<int, AccountStatementDeliveryAttempt>
     */
    #[ORM\OneToMany(mappedBy: 'delivery', targetEntity: AccountStatementDeliveryAttempt::class)]
    #[ORM\OrderBy(['attemptNumber' => 'DESC'])]
    private Collection $attempts;

    public function __construct(
        ?Workspace $workspace = null,
        ?AccountStatementSnapshot $accountStatement = null,
        AccountStatementDeliveryChannel $channel = AccountStatementDeliveryChannel::Email,
        string $recipientEmail = '',
        ?string $recipientName = null,
        ?Subscriber $recipientSubscriber = null,
        ?User $createdBy = null,
    ) {
        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->accountStatement = $accountStatement;
        $this->channel = $channel;
        $this->setRecipientEmail($recipientEmail);
        $this->setRecipientName($recipientName);
        $this->recipientSubscriber = $recipientSubscriber;
        $this->createdAt = new DateTimeImmutable();
        $this->createdBy = $createdBy;
        $this->attempts = new ArrayCollection();
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getAccountStatement(): ?AccountStatementSnapshot
    {
        return $this->accountStatement;
    }

    public function getRecipientSubscriber(): ?Subscriber
    {
        return $this->recipientSubscriber;
    }

    public function getChannel(): AccountStatementDeliveryChannel
    {
        return $this->channel;
    }

    public function getRecipientEmail(): string
    {
        return $this->recipientEmail;
    }

    public function setRecipientEmail(string $recipientEmail): static
    {
        $this->recipientEmail = trim($recipientEmail);
        $this->recipientEmailNormalized = strtolower($this->recipientEmail);

        return $this;
    }

    public function getRecipientEmailNormalized(): string
    {
        return $this->recipientEmailNormalized;
    }

    public function getRecipientName(): ?string
    {
        return $this->recipientName;
    }

    public function setRecipientName(?string $recipientName): static
    {
        $recipientName = $recipientName === null ? null : trim($recipientName);
        $this->recipientName = $recipientName === '' ? null : $recipientName;

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function getCancelledAt(): ?DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCancelledBy(): ?User
    {
        return $this->cancelledBy;
    }

    public function getCancellationReason(): ?string
    {
        return $this->cancellationReason;
    }

    public function cancel(string $reason, ?User $cancelledBy = null): static
    {
        $this->cancelledAt = new DateTimeImmutable();
        $this->cancelledBy = $cancelledBy;
        $this->cancellationReason = trim($reason);

        return $this;
    }

    public function isCancelled(): bool
    {
        return $this->cancelledAt !== null;
    }

    public function addAttempt(AccountStatementDeliveryAttempt $attempt): static
    {
        if (!$this->attempts->contains($attempt)) {
            $this->attempts->add($attempt);
        }

        return $this;
    }

    /**
     * @return Collection<int, AccountStatementDeliveryAttempt>
     */
    public function getAttempts(): Collection
    {
        return $this->attempts;
    }

    public function getLatestAttempt(): ?AccountStatementDeliveryAttempt
    {
        foreach ($this->attempts as $attempt) {
            return $attempt;
        }

        return null;
    }

    public function getStatusLabel(): string
    {
        if ($this->isCancelled()) {
            return $this->getLatestAttempt()?->getStatusCode() === 'sent'
                ? 'Отменена после отправки'
                : 'Отменена';
        }

        return $this->getLatestAttempt()?->getStatusLabel() ?? 'Без попыток';
    }

    public function getStatusBadgeClass(): string
    {
        if ($this->isCancelled()) {
            return 'text-bg-secondary';
        }

        return $this->getLatestAttempt()?->getStatusBadgeClass() ?? 'text-bg-secondary';
    }
}
