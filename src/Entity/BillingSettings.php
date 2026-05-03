<?php

namespace App\Entity;

use App\Repository\BillingSettingsRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: BillingSettingsRepository::class)]
#[ORM\Table(name: 'billing_settings')]
class BillingSettings
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Column(name: 'association_name', type: Types::TEXT)]
    private string $associationName = '';

    #[ORM\Column(name: 'invoice_generation_day', type: Types::SMALLINT, options: ['default' => 5])]
    private int $invoiceGenerationDay = 5;

    #[ORM\Column(name: 'reading_freshness_window_days', options: ['default' => 15])]
    private int $readingFreshnessWindowDays = 15;

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

    public function __construct(?Workspace $workspace = null, string $associationName = '', ?User $createdBy = null)
    {
        $now = new DateTimeImmutable();

        $this->workspace = $workspace;
        $this->associationName = trim($associationName);
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->createdBy = $createdBy;
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

    public function getAssociationName(): string
    {
        return $this->associationName;
    }

    public function setAssociationName(string $associationName): static
    {
        $this->associationName = trim($associationName);

        return $this;
    }

    public function getInvoiceGenerationDay(): int
    {
        return $this->invoiceGenerationDay;
    }

    public function setInvoiceGenerationDay(int $invoiceGenerationDay): static
    {
        $this->invoiceGenerationDay = $invoiceGenerationDay;

        return $this;
    }

    public function getReadingFreshnessWindowDays(): int
    {
        return $this->readingFreshnessWindowDays;
    }

    public function setReadingFreshnessWindowDays(int $readingFreshnessWindowDays): static
    {
        $this->readingFreshnessWindowDays = $readingFreshnessWindowDays;

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
}
