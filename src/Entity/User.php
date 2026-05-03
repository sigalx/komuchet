<?php

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(name: 'ix_users_lifecycle', columns: ['approved_at', 'blocked_at', 'deleted_at'])]
#[ORM\Index(name: 'ix_users_admin_active', columns: ['admin_granted_at'], options: ['where' => 'admin_granted_at IS NOT NULL AND admin_revoked_at IS NULL'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(name: 'approved_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'approved_by', referencedColumnName: 'uuid', nullable: true)]
    private ?self $approvedBy = null;

    #[ORM\Column(name: 'blocked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $blockedAt = null;

    #[ORM\Column(name: 'blocked_reason', type: Types::TEXT, nullable: true)]
    private ?string $blockedReason = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'blocked_by', referencedColumnName: 'uuid', nullable: true)]
    private ?self $blockedBy = null;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'deleted_by', referencedColumnName: 'uuid', nullable: true)]
    private ?self $deletedBy = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'uuid', nullable: true)]
    private ?self $createdBy = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'updated_by', referencedColumnName: 'uuid', nullable: true)]
    private ?self $updatedBy = null;

    #[ORM\Column(name: 'admin_granted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $adminGrantedAt = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'admin_granted_by', referencedColumnName: 'uuid', nullable: true)]
    private ?self $adminGrantedBy = null;

    #[ORM\Column(name: 'admin_revoked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $adminRevokedAt = null;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(name: 'admin_revoked_by', referencedColumnName: 'uuid', nullable: true)]
    private ?self $adminRevokedBy = null;

    #[ORM\Column(name: 'admin_revoked_reason', type: Types::TEXT, nullable: true)]
    private ?string $adminRevokedReason = null;

    /**
     * @var Collection<int, UserEmailIdentity>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: UserEmailIdentity::class, cascade: ['persist'])]
    private Collection $emailIdentities;

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: UserPasswordCredential::class, cascade: ['persist'])]
    private ?UserPasswordCredential $passwordCredential = null;

    /**
     * @var Collection<int, WorkspaceUserRoleAssignment>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: WorkspaceUserRoleAssignment::class, cascade: ['persist'])]
    private Collection $workspaceRoleAssignments;

    public function __construct()
    {
        $now = new DateTimeImmutable();

        $this->uuid = Uuid::v7();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->emailIdentities = new ArrayCollection();
        $this->workspaceRoleAssignments = new ArrayCollection();
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(?self $updatedBy = null): static
    {
        $this->updatedAt = new DateTimeImmutable();
        $this->updatedBy = $updatedBy;

        return $this;
    }

    public function getApprovedAt(): ?DateTimeImmutable
    {
        return $this->approvedAt;
    }

    public function approve(?self $approvedBy = null): static
    {
        $this->approvedAt = new DateTimeImmutable();
        $this->approvedBy = $approvedBy;

        return $this->touch($approvedBy);
    }

    public function getApprovedBy(): ?self
    {
        return $this->approvedBy;
    }

    public function getBlockedAt(): ?DateTimeImmutable
    {
        return $this->blockedAt;
    }

    public function getBlockedReason(): ?string
    {
        return $this->blockedReason;
    }

    public function block(string $reason, ?self $blockedBy = null): static
    {
        $this->blockedAt = new DateTimeImmutable();
        $this->blockedReason = $reason;
        $this->blockedBy = $blockedBy;

        return $this->touch($blockedBy);
    }

    public function unblock(?self $updatedBy = null): static
    {
        $this->blockedAt = null;
        $this->blockedReason = null;
        $this->blockedBy = null;

        return $this->touch($updatedBy);
    }

    public function getBlockedBy(): ?self
    {
        return $this->blockedBy;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function delete(?self $deletedBy = null): static
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->deletedBy = $deletedBy;

        return $this->touch($deletedBy);
    }

    public function getDeletedBy(): ?self
    {
        return $this->deletedBy;
    }

    public function isLoginAllowed(): bool
    {
        return $this->approvedAt !== null
            && $this->blockedAt === null
            && $this->deletedAt === null;
    }

    public function getStatusCode(): string
    {
        if ($this->deletedAt !== null) {
            return 'deleted';
        }

        if ($this->blockedAt !== null) {
            return 'blocked';
        }

        if ($this->approvedAt === null) {
            return 'pending';
        }

        return 'active';
    }

    public function getStatusLabel(): string
    {
        return match ($this->getStatusCode()) {
            'deleted' => 'Удален',
            'blocked' => 'Заблокирован',
            'pending' => 'Ожидает одобрения',
            default => 'Активен',
        };
    }

    public function getCreatedBy(): ?self
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?self $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getUpdatedBy(): ?self
    {
        return $this->updatedBy;
    }

    public function getAdminGrantedAt(): ?DateTimeImmutable
    {
        return $this->adminGrantedAt;
    }

    public function getAdminGrantedBy(): ?self
    {
        return $this->adminGrantedBy;
    }

    public function getAdminRevokedAt(): ?DateTimeImmutable
    {
        return $this->adminRevokedAt;
    }

    public function getAdminRevokedBy(): ?self
    {
        return $this->adminRevokedBy;
    }

    public function getAdminRevokedReason(): ?string
    {
        return $this->adminRevokedReason;
    }

    public function grantAdmin(?self $grantedBy = null): static
    {
        $this->adminGrantedAt = new DateTimeImmutable();
        $this->adminGrantedBy = $grantedBy;
        $this->adminRevokedAt = null;
        $this->adminRevokedBy = null;
        $this->adminRevokedReason = null;

        return $this->touch($grantedBy);
    }

    public function revokeAdmin(string $reason, ?self $revokedBy = null): static
    {
        $this->adminRevokedAt = new DateTimeImmutable();
        $this->adminRevokedBy = $revokedBy;
        $this->adminRevokedReason = trim($reason);

        return $this->touch($revokedBy);
    }

    public function isAdmin(): bool
    {
        return $this->adminGrantedAt !== null && $this->adminRevokedAt === null;
    }

    public function getPrimaryEmail(): ?string
    {
        foreach ($this->emailIdentities as $identity) {
            if ($identity->isActive()) {
                return $identity->getEmail();
            }
        }

        return null;
    }

    public function getUserIdentifier(): string
    {
        return $this->uuid->toRfc4122();
    }

    /**
     * @return Collection<int, UserEmailIdentity>
     */
    public function getEmailIdentities(): Collection
    {
        return $this->emailIdentities;
    }

    public function addEmailIdentity(UserEmailIdentity $identity): static
    {
        if (!$this->emailIdentities->contains($identity)) {
            $this->emailIdentities->add($identity);
            $identity->setUser($this);
        }

        return $this;
    }

    public function getPasswordCredential(): ?UserPasswordCredential
    {
        return $this->passwordCredential;
    }

    public function setPasswordCredential(?UserPasswordCredential $passwordCredential): static
    {
        $this->passwordCredential = $passwordCredential;

        if ($passwordCredential !== null && $passwordCredential->getUser() !== $this) {
            $passwordCredential->setUser($this);
        }

        return $this;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];

        if ($this->isAdmin()) {
            $roles[] = 'ROLE_ADMIN';
        }

        return array_unique($roles);
    }

    /**
     * @return Collection<int, WorkspaceUserRoleAssignment>
     */
    public function getWorkspaceRoleAssignments(): Collection
    {
        return $this->workspaceRoleAssignments;
    }

    public function addWorkspaceRoleAssignment(WorkspaceUserRoleAssignment $assignment): static
    {
        if (!$this->workspaceRoleAssignments->contains($assignment)) {
            $this->workspaceRoleAssignments->add($assignment);
            $assignment->setUser($this);
        }

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->passwordCredential?->getPasswordHash();
    }

    public function eraseCredentials(): void
    {
    }

    /**
     * Ensure the session doesn't contain actual password hashes by CRC32C-hashing them, as supported since Symfony 7.3.
     */
    public function __serialize(): array
    {
        $data = (array) $this;
        $data["\0".self::class."\0passwordCredential"] = null;

        return $data;
    }
}
