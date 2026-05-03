<?php

namespace App\Entity;

use App\Enum\WorkspaceUserRoleCode;
use App\Repository\WorkspaceUserRoleAssignmentRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: WorkspaceUserRoleAssignmentRepository::class)]
#[ORM\Table(name: 'workspace_user_role_assignments')]
#[ORM\UniqueConstraint(name: 'ux_workspace_user_role_assignments_active', columns: ['workspace_uuid', 'user_uuid', 'role_code'], options: ['where' => 'revoked_at IS NULL'])]
#[ORM\Index(name: 'ix_workspace_user_role_assignments_user', columns: ['user_uuid', 'workspace_uuid'], options: ['where' => 'revoked_at IS NULL'])]
#[ORM\Index(name: 'ix_workspace_user_role_assignments_role', columns: ['workspace_uuid', 'role_code'], options: ['where' => 'revoked_at IS NULL'])]
class WorkspaceUserRoleAssignment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $uuid;

    #[ORM\ManyToOne(targetEntity: Workspace::class)]
    #[ORM\JoinColumn(name: 'workspace_uuid', referencedColumnName: 'uuid', nullable: false)]
    private ?Workspace $workspace = null;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'workspaceRoleAssignments')]
    #[ORM\JoinColumn(name: 'user_uuid', referencedColumnName: 'uuid', nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(name: 'role_code', enumType: WorkspaceUserRoleCode::class, columnDefinition: 'workspace_user_role_code')]
    private WorkspaceUserRoleCode $roleCode;

    #[ORM\Column(name: 'granted_at', type: Types::DATETIMETZ_IMMUTABLE)]
    private DateTimeImmutable $grantedAt;

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

    public function __construct(
        ?Workspace $workspace = null,
        ?User $user = null,
        WorkspaceUserRoleCode $roleCode = WorkspaceUserRoleCode::Operator,
        ?User $grantedBy = null,
    ) {
        $this->uuid = Uuid::v7();
        $this->workspace = $workspace;
        $this->user = $user;
        $this->roleCode = $roleCode;
        $this->grantedAt = new DateTimeImmutable();
        $this->grantedBy = $grantedBy;
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

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRoleCode(): string
    {
        return $this->roleCode->value;
    }

    public function getRoleCodeEnum(): WorkspaceUserRoleCode
    {
        return $this->roleCode;
    }

    public function setRoleCode(WorkspaceUserRoleCode $roleCode): static
    {
        $this->roleCode = $roleCode;

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

    public function getRevokedBy(): ?User
    {
        return $this->revokedBy;
    }

    public function getRevokedReason(): ?string
    {
        return $this->revokedReason;
    }

    public function revoke(string $reason, ?User $revokedBy = null): static
    {
        $this->revokedAt = new DateTimeImmutable();
        $this->revokedBy = $revokedBy;
        $this->revokedReason = trim($reason);

        return $this;
    }

    public function isActive(): bool
    {
        return $this->revokedAt === null;
    }
}
