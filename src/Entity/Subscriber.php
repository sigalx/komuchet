<?php

namespace App\Entity;

use App\Repository\SubscriberRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: SubscriberRepository::class)]
#[ORM\Table(name: 'subscribers')]
#[ORM\UniqueConstraint(name: 'ux_subscribers_workspace_uuid', columns: ['workspace_uuid', 'uuid'])]
#[ORM\UniqueConstraint(name: 'ux_subscribers_user_active', columns: ['workspace_uuid', 'user_uuid'], options: ['where' => 'user_uuid IS NOT NULL AND deleted_at IS NULL'])]
#[ORM\Index(name: 'ix_subscribers_name', columns: ['workspace_uuid', 'last_name', 'first_name', 'second_name'], options: ['where' => 'deleted_at IS NULL'])]
class Subscriber
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?User $user = null;

    #[ORM\Column(name: 'last_name', type: Types::TEXT)]
    private string $lastName = '';

    #[ORM\Column(name: 'first_name', type: Types::TEXT)]
    private string $firstName = '';

    #[ORM\Column(name: 'second_name', type: Types::TEXT, nullable: true)]
    private ?string $secondName = null;

    #[ORM\Column(name: 'contact_email', type: Types::TEXT, nullable: true)]
    private ?string $contactEmail = null;

    #[ORM\Column(name: 'contact_phone', type: Types::TEXT, nullable: true)]
    private ?string $contactPhone = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

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

    public function __construct(?Workspace $workspace = null)
    {
        $now = new DateTimeImmutable();

        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
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

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = trim($lastName);

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = trim($firstName);

        return $this;
    }

    public function getSecondName(): ?string
    {
        return $this->secondName;
    }

    public function setSecondName(?string $secondName): static
    {
        $secondName = $secondName === null ? null : trim($secondName);
        $this->secondName = $secondName === '' ? null : $secondName;

        return $this;
    }

    public function getDisplayName(): string
    {
        return trim(implode(' ', array_filter([
            $this->lastName,
            $this->firstName,
            $this->secondName,
        ])));
    }

    public function getContactEmail(): ?string
    {
        return $this->contactEmail;
    }

    public function setContactEmail(?string $contactEmail): static
    {
        $contactEmail = $contactEmail === null ? null : trim($contactEmail);
        $this->contactEmail = $contactEmail === '' ? null : $contactEmail;

        return $this;
    }

    public function getContactPhone(): ?string
    {
        return $this->contactPhone;
    }

    public function setContactPhone(?string $contactPhone): static
    {
        $contactPhone = $contactPhone === null ? null : trim($contactPhone);
        $this->contactPhone = $contactPhone === '' ? null : $contactPhone;

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
}
