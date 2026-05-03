<?php

namespace App\Entity;

use App\Repository\UserPasswordCredentialRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserPasswordCredentialRepository::class)]
#[ORM\Table(name: 'user_password_credentials')]
class UserPasswordCredential
{
    #[ORM\Id]
    #[ORM\OneToOne(inversedBy: 'passwordCredential', targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_uuid', referencedColumnName: 'uuid', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'password_hash', type: Types::TEXT)]
    private string $passwordHash = '';

    #[ORM\Column(name: 'changed_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $changedAt;

    #[ORM\Column(name: 'expires_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $expiresAt = null;

    public function __construct(?User $user = null, string $passwordHash = '', ?DateTimeImmutable $changedAt = null)
    {
        $this->changedAt = $changedAt ?? new DateTimeImmutable();
        $this->user = $user;
        $this->passwordHash = $passwordHash;
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

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $passwordHash, ?DateTimeImmutable $changedAt = null): static
    {
        $this->passwordHash = $passwordHash;
        $this->changedAt = $changedAt ?? new DateTimeImmutable();

        return $this;
    }

    public function getChangedAt(): DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function getExpiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }
}
