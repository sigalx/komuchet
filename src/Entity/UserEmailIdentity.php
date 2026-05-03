<?php

namespace App\Entity;

use App\Repository\UserEmailIdentityRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserEmailIdentityRepository::class)]
#[ORM\Table(name: 'user_email_identities')]
#[ORM\UniqueConstraint(name: 'ux_user_email_identities_active_email', columns: ['email_normalized'], options: ['where' => 'deleted_at IS NULL'])]
#[ORM\Index(name: 'ix_user_email_identities_user', columns: ['user_uuid'], options: ['where' => 'deleted_at IS NULL'])]
class UserEmailIdentity
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'emailIdentities')]
    #[ORM\JoinColumn(name: 'user_uuid', referencedColumnName: 'uuid', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $email = '';

    #[ORM\Id]
    #[ORM\Column(name: 'email_normalized', type: Types::TEXT)]
    private string $emailNormalized = '';

    #[ORM\Column(name: 'verified_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $verifiedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'deleted_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $deletedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $createdBy = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'deleted_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $deletedBy = null;

    public function __construct(?User $user = null, string $email = '')
    {
        $this->createdAt = new DateTimeImmutable();
        $this->user = $user;

        if ($email !== '') {
            $this->setEmail($email);
        }
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = trim($email);
        $this->emailNormalized = self::normalizeEmail($email);

        return $this;
    }

    public function getEmailNormalized(): string
    {
        return $this->emailNormalized;
    }

    public function getVerifiedAt(): ?DateTimeImmutable
    {
        return $this->verifiedAt;
    }

    public function markVerified(): static
    {
        $this->verifiedAt = new DateTimeImmutable();

        return $this;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDeletedAt(): ?DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function delete(?User $deletedBy = null): static
    {
        $this->deletedAt = new DateTimeImmutable();
        $this->deletedBy = $deletedBy;

        return $this;
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

    public function getDeletedBy(): ?User
    {
        return $this->deletedBy;
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }
}
