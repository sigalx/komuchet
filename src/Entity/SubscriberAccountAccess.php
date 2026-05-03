<?php

namespace App\Entity;

use App\Enum\SubscriberAccountAccessRole;
use App\Repository\SubscriberAccountAccessRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SubscriberAccountAccessRepository::class)]
#[ORM\Table(name: 'subscriber_account_accesses')]
#[ORM\UniqueConstraint(name: 'ux_subscriber_account_accesses_active', columns: ['workspace_uuid', 'subscriber_uuid', 'account_uuid'], options: ['where' => 'revoked_at IS NULL'])]
#[ORM\Index(name: 'ix_subscriber_account_accesses_account', columns: ['workspace_uuid', 'account_uuid'], options: ['where' => 'revoked_at IS NULL'])]
class SubscriberAccountAccess
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Subscriber::class)]
    #[ORM\JoinColumn(name: 'subscriber_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Subscriber $subscriber = null;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(name: 'account_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Account $account = null;

    #[ORM\Column(name: 'granted_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $grantedAt;

    #[ORM\Column(name: 'access_role', enumType: SubscriberAccountAccessRole::class, options: ['default' => 'owner'], columnDefinition: 'subscriber_account_access_role')]
    private SubscriberAccountAccessRole $accessRole = SubscriberAccountAccessRole::Owner;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'granted_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $grantedBy = null;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'revoked_by', referencedColumnName: 'uuid', nullable: true)]
    private ?User $revokedBy = null;

    #[ORM\Column(name: 'revoked_reason', type: Types::TEXT, nullable: true)]
    private ?string $revokedReason = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    public function __construct(
        ?Workspace $workspace = null,
        ?Subscriber $subscriber = null,
        ?Account $account = null,
        SubscriberAccountAccessRole $accessRole = SubscriberAccountAccessRole::Owner,
        ?User $grantedBy = null,
    ) {
        $this->workspace = $workspace;
        $this->subscriber = $subscriber;
        $this->account = $account;
        $this->accessRole = $accessRole;
        $this->grantedAt = new DateTimeImmutable();
        $this->grantedBy = $grantedBy;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function getSubscriber(): ?Subscriber
    {
        return $this->subscriber;
    }

    public function getAccount(): ?Account
    {
        return $this->account;
    }

    public function getAccessRole(): SubscriberAccountAccessRole
    {
        return $this->accessRole;
    }

    public function setAccessRole(SubscriberAccountAccessRole $accessRole): static
    {
        $this->accessRole = $accessRole;

        return $this;
    }

    public function getGrantedAt(): DateTimeImmutable
    {
        return $this->grantedAt;
    }

    public function getGrantedBy(): ?User
    {
        return $this->grantedBy;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function revoke(string $reason, ?User $revokedBy = null): static
    {
        $this->revokedAt = new DateTimeImmutable();
        $this->revokedBy = $revokedBy;
        $this->revokedReason = $reason;

        return $this;
    }

    public function getRevokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function getRevokedReason(): ?string
    {
        return $this->revokedReason;
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
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
}
