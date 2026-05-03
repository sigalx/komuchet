<?php

namespace App\Entity;

use App\Enum\AuditLogSource;
use App\Repository\AuditLogRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'audit_logs')]
#[ORM\Index(name: 'ix_audit_logs_entity_uuid', columns: ['entity_table', 'entity_uuid', 'occurred_at'], options: ['where' => 'entity_uuid IS NOT NULL'])]
#[ORM\Index(name: 'ix_audit_logs_actor_user', columns: ['actor_user_uuid', 'occurred_at'], options: ['where' => 'actor_user_uuid IS NOT NULL'])]
#[ORM\Index(name: 'ix_audit_logs_workspace_occurred', columns: ['workspace_uuid', 'occurred_at'], options: ['where' => 'workspace_uuid IS NOT NULL'])]
#[ORM\Index(name: 'ix_audit_logs_request', columns: ['request_id'], options: ['where' => 'request_id IS NOT NULL'])]
class AuditLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?Workspace $workspace = null;

    #[ORM\Column(name: 'occurred_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $occurredAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_user_uuid', referencedColumnName: 'uuid', nullable: true)]
    private ?User $actorUser = null;

    #[ORM\Column(enumType: AuditLogSource::class, options: ['default' => 'app'], columnDefinition: 'audit_log_source')]
    private AuditLogSource $source = AuditLogSource::App;

    #[ORM\Column(name: 'db_user', type: Types::TEXT, nullable: true)]
    private ?string $dbUser = null;

    #[ORM\Column(type: Types::TEXT)]
    private string $action = '';

    #[ORM\Column(name: 'entity_table', type: Types::TEXT, nullable: true)]
    private ?string $entityTable = null;

    #[ORM\Column(name: 'entity_uuid', type: 'uuid', nullable: true)]
    private ?Uuid $entityUuid = null;

    #[ORM\Column(name: 'entity_pk', type: Types::JSONB, nullable: true)]
    private ?array $entityPk = null;

    #[ORM\Column(name: 'old_values', type: Types::JSONB, nullable: true)]
    private ?array $oldValues = null;

    #[ORM\Column(name: 'new_values', type: Types::JSONB, nullable: true)]
    private ?array $newValues = null;

    #[ORM\Column(name: 'changed_fields', type: 'text_array', nullable: true)]
    private ?array $changedFields = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(name: 'request_id', type: Types::TEXT, nullable: true)]
    private ?string $requestId = null;

    #[ORM\Column(name: 'ip_address', type: Types::STRING, nullable: true, columnDefinition: 'inet')]
    private ?string $ipAddress = null;

    #[ORM\Column(name: 'user_agent', type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    public function __construct(string $action = '', AuditLogSource $source = AuditLogSource::App)
    {
        $this->uuid = Uuid::v7();
        $this->occurredAt = new DateTimeImmutable();
        $this->action = trim($action);
        $this->source = $source;
    }

    public function getUuid(): Uuid
    {
        return $this->uuid;
    }

    public function getWorkspace(): ?Workspace
    {
        return $this->workspace;
    }

    public function setWorkspace(?Workspace $workspace): static
    {
        $this->workspace = $workspace;

        return $this;
    }

    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getActorUser(): ?User
    {
        return $this->actorUser;
    }

    public function setActorUser(?User $actorUser): static
    {
        $this->actorUser = $actorUser;

        return $this;
    }

    public function getSource(): AuditLogSource
    {
        return $this->source;
    }

    public function setSource(AuditLogSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getDbUser(): ?string
    {
        return $this->dbUser;
    }

    public function setDbUser(?string $dbUser): static
    {
        $dbUser = $dbUser === null ? null : trim($dbUser);
        $this->dbUser = $dbUser === '' ? null : $dbUser;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = trim($action);

        return $this;
    }

    public function getEntityTable(): ?string
    {
        return $this->entityTable;
    }

    public function setEntityTable(?string $entityTable): static
    {
        $entityTable = $entityTable === null ? null : trim($entityTable);
        $this->entityTable = $entityTable === '' ? null : $entityTable;

        return $this;
    }

    public function getEntityUuid(): ?Uuid
    {
        return $this->entityUuid;
    }

    public function setEntityUuid(?Uuid $entityUuid): static
    {
        $this->entityUuid = $entityUuid;

        return $this;
    }

    public function getEntityPk(): ?array
    {
        return $this->entityPk;
    }

    public function setEntityPk(?array $entityPk): static
    {
        $this->entityPk = $entityPk;

        return $this;
    }

    public function getOldValues(): ?array
    {
        return $this->oldValues;
    }

    public function setOldValues(?array $oldValues): static
    {
        $this->oldValues = $oldValues;

        return $this;
    }

    public function getNewValues(): ?array
    {
        return $this->newValues;
    }

    public function setNewValues(?array $newValues): static
    {
        $this->newValues = $newValues;

        return $this;
    }

    /**
     * @return list<string>|null
     */
    public function getChangedFields(): ?array
    {
        return $this->changedFields;
    }

    /**
     * @param list<string>|null $changedFields
     */
    public function setChangedFields(?array $changedFields): static
    {
        $this->changedFields = $changedFields;

        return $this;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): static
    {
        $reason = $reason === null ? null : trim($reason);
        $this->reason = $reason === '' ? null : $reason;

        return $this;
    }

    public function getRequestId(): ?string
    {
        return $this->requestId;
    }

    public function setRequestId(?string $requestId): static
    {
        $requestId = $requestId === null ? null : trim($requestId);
        $this->requestId = $requestId === '' ? null : $requestId;

        return $this;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function setIpAddress(?string $ipAddress): static
    {
        $ipAddress = $ipAddress === null ? null : trim($ipAddress);
        $this->ipAddress = $ipAddress === '' ? null : $ipAddress;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $userAgent = $userAgent === null ? null : trim($userAgent);
        $this->userAgent = $userAgent === '' ? null : $userAgent;

        return $this;
    }
}
