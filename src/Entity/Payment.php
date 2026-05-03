<?php

namespace App\Entity;

use App\Enum\PaymentSource;
use App\Repository\PaymentRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaymentRepository::class)]
#[ORM\Table(name: 'payments')]
#[ORM\UniqueConstraint(name: 'ux_payments_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_payments_replacing', columns: ['workspace_uuid', 'replacing_payment_uuid'], options: ['where' => 'replacing_payment_uuid IS NOT NULL'])]
#[ORM\Index(name: 'ix_payments_account_paid_on', columns: ['workspace_uuid', 'account_uuid', 'paid_on'])]
#[ORM\Index(name: 'ix_payments_external_reference', columns: ['workspace_uuid', 'external_reference'], options: ['where' => 'external_reference IS NOT NULL'])]
class Payment
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

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2)]
    private string $amount = '0';

    #[ORM\Column(name: 'paid_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $paidOn;

    #[ORM\Column(name: 'paid_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $paidAt = null;

    #[ORM\Column(enumType: PaymentSource::class, options: ['default' => 'manual'], columnDefinition: 'payment_source')]
    private PaymentSource $source = PaymentSource::Manual;

    #[ORM\Column(name: 'payer_name', type: Types::TEXT, nullable: true)]
    private ?string $payerName = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $purpose = null;

    #[ORM\Column(name: 'external_reference', type: Types::TEXT, nullable: true)]
    private ?string $externalReference = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'replacing_payment_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?self $replacingPayment = null;

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
        string $amount = '0',
        ?DateTimeImmutable $paidOn = null,
        PaymentSource $source = PaymentSource::Manual,
        ?User $createdBy = null,
    ) {
        $now = new DateTimeImmutable();

        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->account = $account;
        $this->setAmount($amount);
        $this->paidOn = $paidOn ?? new DateTimeImmutable('today');
        $this->source = $source;
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

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function setAmount(string $amount): static
    {
        $this->amount = trim(str_replace(',', '.', $amount));

        return $this;
    }

    public function getPaidOn(): DateTimeImmutable
    {
        return $this->paidOn;
    }

    public function setPaidOn(DateTimeImmutable $paidOn): static
    {
        $this->paidOn = $paidOn;

        return $this;
    }

    public function getPaidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function setPaidAt(?DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
    }

    public function getSource(): PaymentSource
    {
        return $this->source;
    }

    public function setSource(PaymentSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getPayerName(): ?string
    {
        return $this->payerName;
    }

    public function setPayerName(?string $payerName): static
    {
        $payerName = $payerName === null ? null : trim($payerName);
        $this->payerName = $payerName === '' ? null : $payerName;

        return $this;
    }

    public function getPurpose(): ?string
    {
        return $this->purpose;
    }

    public function setPurpose(?string $purpose): static
    {
        $purpose = $purpose === null ? null : trim($purpose);
        $this->purpose = $purpose === '' ? null : $purpose;

        return $this;
    }

    public function getExternalReference(): ?string
    {
        return $this->externalReference;
    }

    public function setExternalReference(?string $externalReference): static
    {
        $externalReference = $externalReference === null ? null : trim($externalReference);
        $this->externalReference = $externalReference === '' ? null : $externalReference;

        return $this;
    }

    public function getReplacingPayment(): ?self
    {
        return $this->replacingPayment;
    }

    public function markReplacedBy(self $replacement, string $reason, ?User $replacedBy = null): static
    {
        $this->replacingPayment = $replacement;
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

    public function isActive(): bool
    {
        return $this->cancelledAt === null && $this->replacingPayment === null;
    }
}
