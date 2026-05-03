<?php

namespace App\Entity;

use App\Repository\UserPasswordHistoryRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserPasswordHistoryRepository::class)]
#[ORM\Table(name: 'user_password_history')]
#[ORM\Index(name: 'ix_user_password_history_changed_by', columns: ['changed_by', 'changed_at'], options: ['where' => 'changed_by IS NOT NULL'])]
class UserPasswordHistory
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_uuid', referencedColumnName: 'uuid', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'password_hash', type: Types::TEXT)]
    private string $passwordHash = '';

    #[ORM\Id]
    #[ORM\Column(name: 'changed_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $changedAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'changed_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $changedBy = null;

    public function __construct(?User $user = null, string $passwordHash = '', ?DateTimeImmutable $changedAt = null, ?User $changedBy = null)
    {
        $this->user = $user;
        $this->passwordHash = $passwordHash;
        $this->changedAt = $changedAt ?? new DateTimeImmutable();
        $this->changedBy = $changedBy;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function getChangedAt(): DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function getChangedBy(): ?User
    {
        return $this->changedBy;
    }
}
