<?php

namespace App\Entity;

use App\Repository\PaymentRequisiteProfileRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: PaymentRequisiteProfileRepository::class)]
#[ORM\Table(name: 'payment_requisite_profiles')]
#[ORM\UniqueConstraint(name: 'ux_payment_requisite_profiles_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_payment_requisite_profiles_code_active', columns: ['workspace_uuid', 'code'], options: ['where' => 'deleted_at IS NULL'])]
#[ORM\Index(name: 'ix_payment_requisite_profiles_validity', columns: ['workspace_uuid', 'valid_from', 'valid_to'])]
class PaymentRequisiteProfile
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $code = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $name = '';

    #[ORM\Column(name: 'recipient_name', type: Types::TEXT)]
    private string $recipientName = '';

    #[ORM\Column(name: 'recipient_inn', type: Types::TEXT, nullable: true)]
    private ?string $recipientInn = null;

    #[ORM\Column(name: 'recipient_kpp', type: Types::TEXT, nullable: true)]
    private ?string $recipientKpp = null;

    #[ORM\Column(name: 'bank_name', type: Types::TEXT)]
    private string $bankName = '';

    #[ORM\Column(name: 'bank_bik', type: Types::TEXT)]
    private string $bankBik = '';

    #[ORM\Column(name: 'bank_correspondent_account', type: Types::TEXT, nullable: true)]
    private ?string $bankCorrespondentAccount = null;

    #[ORM\Column(name: 'bank_account', type: Types::TEXT)]
    private string $bankAccount = '';

    #[ORM\Column(name: 'payment_purpose_template', type: Types::TEXT, nullable: true)]
    private ?string $paymentPurposeTemplate = null;

    #[ORM\Column(name: 'valid_from', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $validFrom;

    #[ORM\Column(name: 'valid_to', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $validTo = null;

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

    public function __construct(?Workspace $workspace = null, ?DateTimeImmutable $validFrom = null)
    {
        $now = new DateTimeImmutable();

        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->validFrom = $validFrom ?? new DateTimeImmutable('today');
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

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = trim($code);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getRecipientName(): string
    {
        return $this->recipientName;
    }

    public function setRecipientName(string $recipientName): static
    {
        $this->recipientName = trim($recipientName);

        return $this;
    }

    public function getRecipientInn(): ?string
    {
        return $this->recipientInn;
    }

    public function setRecipientInn(?string $recipientInn): static
    {
        $this->recipientInn = $this->normalizeOptionalText($recipientInn);

        return $this;
    }

    public function getRecipientKpp(): ?string
    {
        return $this->recipientKpp;
    }

    public function setRecipientKpp(?string $recipientKpp): static
    {
        $this->recipientKpp = $this->normalizeOptionalText($recipientKpp);

        return $this;
    }

    public function getBankName(): string
    {
        return $this->bankName;
    }

    public function setBankName(string $bankName): static
    {
        $this->bankName = trim($bankName);

        return $this;
    }

    public function getBankBik(): string
    {
        return $this->bankBik;
    }

    public function setBankBik(string $bankBik): static
    {
        $this->bankBik = trim($bankBik);

        return $this;
    }

    public function getBankCorrespondentAccount(): ?string
    {
        return $this->bankCorrespondentAccount;
    }

    public function setBankCorrespondentAccount(?string $bankCorrespondentAccount): static
    {
        $this->bankCorrespondentAccount = $this->normalizeOptionalText($bankCorrespondentAccount);

        return $this;
    }

    public function getBankAccount(): string
    {
        return $this->bankAccount;
    }

    public function setBankAccount(string $bankAccount): static
    {
        $this->bankAccount = trim($bankAccount);

        return $this;
    }

    public function getPaymentPurposeTemplate(): ?string
    {
        return $this->paymentPurposeTemplate;
    }

    public function setPaymentPurposeTemplate(?string $paymentPurposeTemplate): static
    {
        $this->paymentPurposeTemplate = $this->normalizeOptionalText($paymentPurposeTemplate);

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

    public function isValidOn(DateTimeImmutable $date): bool
    {
        $dateValue = $date->format('Y-m-d');

        return $this->validFrom->format('Y-m-d') <= $dateValue
            && ($this->validTo === null || $this->validTo->format('Y-m-d') > $dateValue);
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

    private function normalizeOptionalText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
