<?php

namespace App\Entity;

use App\Enum\ElectricityMeterReadingSource;
use App\Repository\ElectricityMeterReadingRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ElectricityMeterReadingRepository::class)]
#[ORM\Table(name: 'electricity_meter_readings')]
#[ORM\UniqueConstraint(name: 'ux_electricity_meter_readings_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_electricity_meter_readings_uuid_meter_zone', columns: ['workspace_uuid', 'uuid', 'electricity_meter_uuid', 'tariff_zone_uuid'])]
#[ORM\UniqueConstraint(name: 'ux_electricity_meter_readings_replacing', columns: ['workspace_uuid', 'replacing_reading_uuid'], options: ['where' => 'replacing_reading_uuid IS NOT NULL'])]
#[ORM\Index(name: 'ix_electricity_meter_readings_active_meter_zone_taken', columns: ['workspace_uuid', 'electricity_meter_uuid', 'tariff_zone_uuid', 'taken_on', 'submitted_at'], options: ['where' => 'cancelled_at IS NULL AND replacing_reading_uuid IS NULL'])]
#[ORM\Index(name: 'ix_electricity_meter_readings_provider', columns: ['workspace_uuid', 'provided_by_subscriber_uuid', 'submitted_at'], options: ['where' => 'provided_by_subscriber_uuid IS NOT NULL'])]
class ElectricityMeterReading
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne(targetEntity: ElectricityMeter::class)]
    #[ORM\JoinColumn(name: 'electricity_meter_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityMeter $electricityMeter = null;

    #[ORM\ManyToOne(targetEntity: ElectricityTariffZone::class)]
    #[ORM\JoinColumn(name: 'tariff_zone_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?ElectricityTariffZone $tariffZone = null;

    #[ORM\Column(name: 'reading_value', type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $readingValue = '0';

    #[ORM\Column(name: 'taken_on', type: Types::DATE_IMMUTABLE)]
    private DateTimeImmutable $takenOn;

    #[ORM\Column(name: 'submitted_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $submittedAt;

    #[ORM\Column(enumType: ElectricityMeterReadingSource::class, columnDefinition: 'electricity_meter_reading_source')]
    private ElectricityMeterReadingSource $source = ElectricityMeterReadingSource::Subscriber;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'submitted_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $submittedBy = null;

    #[ORM\ManyToOne(targetEntity: Subscriber::class)]
    #[ORM\JoinColumn(name: 'provided_by_subscriber_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?Subscriber $providedBySubscriber = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'replacing_reading_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?self $replacingReading = null;

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
        ?ElectricityMeter $electricityMeter = null,
        ?ElectricityTariffZone $tariffZone = null,
        string $readingValue = '0',
        ?DateTimeImmutable $takenOn = null,
        ElectricityMeterReadingSource $source = ElectricityMeterReadingSource::Subscriber,
        ?User $submittedBy = null,
    ) {
        $now = new DateTimeImmutable();

        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->electricityMeter = $electricityMeter;
        $this->tariffZone = $tariffZone;
        $this->readingValue = $readingValue;
        $this->takenOn = $takenOn ?? new DateTimeImmutable('today');
        $this->submittedAt = $now;
        $this->source = $source;
        $this->submittedBy = $submittedBy;
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

    public function getElectricityMeter(): ?ElectricityMeter
    {
        return $this->electricityMeter;
    }

    public function setElectricityMeter(ElectricityMeter $electricityMeter): static
    {
        $this->electricityMeter = $electricityMeter;

        return $this;
    }

    public function getTariffZone(): ?ElectricityTariffZone
    {
        return $this->tariffZone;
    }

    public function setTariffZone(ElectricityTariffZone $tariffZone): static
    {
        $this->tariffZone = $tariffZone;

        return $this;
    }

    public function getReadingValue(): string
    {
        return $this->readingValue;
    }

    public function setReadingValue(string $readingValue): static
    {
        $this->readingValue = trim(str_replace(',', '.', $readingValue));

        return $this;
    }

    public function getTakenOn(): DateTimeImmutable
    {
        return $this->takenOn;
    }

    public function setTakenOn(DateTimeImmutable $takenOn): static
    {
        $this->takenOn = $takenOn;

        return $this;
    }

    public function getSubmittedAt(): DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getSource(): ElectricityMeterReadingSource
    {
        return $this->source;
    }

    public function setSource(ElectricityMeterReadingSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getSubmittedBy(): ?User
    {
        return $this->submittedBy;
    }

    public function setSubmittedBy(?User $submittedBy): static
    {
        $this->submittedBy = $submittedBy;

        return $this;
    }

    public function getProvidedBySubscriber(): ?Subscriber
    {
        return $this->providedBySubscriber;
    }

    public function setProvidedBySubscriber(?Subscriber $providedBySubscriber): static
    {
        $this->providedBySubscriber = $providedBySubscriber;

        return $this;
    }

    public function getReplacingReading(): ?self
    {
        return $this->replacingReading;
    }

    public function markReplacedBy(self $replacement, string $reason, ?User $replacedBy = null): static
    {
        $this->replacingReading = $replacement;
        $this->replacedAt = new DateTimeImmutable();
        $this->replacedBy = $replacedBy;
        $this->replacementReason = $reason;

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
        $this->cancellationReason = $reason;

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

    public function isActive(): bool
    {
        return $this->cancelledAt === null && $this->replacingReading === null;
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

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }
}
