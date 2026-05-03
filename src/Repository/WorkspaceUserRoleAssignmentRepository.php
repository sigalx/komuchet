<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\WorkspaceUserRoleCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<WorkspaceUserRoleAssignment>
 */
class WorkspaceUserRoleAssignmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkspaceUserRoleAssignment::class);
    }

    public function findOneActiveByWorkspaceUserAndRole(Workspace $workspace, User $user, WorkspaceUserRoleCode $roleCode): ?WorkspaceUserRoleAssignment
    {
        return $this->createQueryBuilder('assignment')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.user = :user')
            ->andWhere('assignment.roleCode = :roleCode')
            ->andWhere('assignment.revokedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('user', $user)
            ->setParameter('roleCode', $roleCode->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param list<WorkspaceUserRoleCode> $roleCodes
     */
    public function hasActiveRole(Workspace $workspace, User $user, array $roleCodes): bool
    {
        if ($roleCodes === []) {
            return false;
        }

        $roleCodeValues = array_map(
            static fn (WorkspaceUserRoleCode $roleCode): string => $roleCode->value,
            $roleCodes,
        );

        return (int) $this->createQueryBuilder('assignment')
            ->select('COUNT(assignment.uuid)')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.user = :user')
            ->andWhere('assignment.roleCode IN (:roleCodes)')
            ->andWhere('assignment.revokedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('user', $user)
            ->setParameter('roleCodes', $roleCodeValues)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @param list<User> $users
     *
     * @return array<string, list<WorkspaceUserRoleAssignment>>
     */
    public function findActiveByWorkspaceAndUsers(Workspace $workspace, array $users): array
    {
        if ($users === []) {
            return [];
        }

        $assignments = $this->createQueryBuilder('assignment')
            ->addSelect('user')
            ->innerJoin('assignment.user', 'user')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.user IN (:users)')
            ->andWhere('assignment.revokedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('users', $users)
            ->orderBy('assignment.grantedAt', 'ASC')
            ->getQuery()
            ->getResult();

        $byUser = [];

        foreach ($assignments as $assignment) {
            if (!$assignment instanceof WorkspaceUserRoleAssignment || !$assignment->getUser() instanceof User) {
                continue;
            }

            $byUser[$assignment->getUser()->getUuid()->toRfc4122()][] = $assignment;
        }

        return $byUser;
    }

    /**
     * @return list<WorkspaceUserRoleAssignment>
     */
    public function findByWorkspaceAndUser(Workspace $workspace, User $user): array
    {
        return $this->createQueryBuilder('assignment')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.user = :user')
            ->setParameter('workspace', $workspace)
            ->setParameter('user', $user)
            ->orderBy('assignment.grantedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<WorkspaceUserRoleAssignment>
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('assignment')
            ->addSelect('workspace')
            ->innerJoin('assignment.workspace', 'workspace')
            ->andWhere('assignment.user = :user')
            ->andWhere('assignment.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('workspace.code', 'ASC')
            ->addOrderBy('assignment.roleCode', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByWorkspaceUserAndUuid(Workspace $workspace, User $user, Uuid $uuid): ?WorkspaceUserRoleAssignment
    {
        return $this->createQueryBuilder('assignment')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.user = :user')
            ->andWhere('assignment.uuid = :uuid')
            ->setParameter('workspace', $workspace)
            ->setParameter('user', $user)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countActiveWorkspaceAdmins(Workspace $workspace): int
    {
        return (int) $this->createQueryBuilder('assignment')
            ->select('COUNT(assignment.uuid)')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.roleCode = :roleCode')
            ->andWhere('assignment.revokedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('roleCode', WorkspaceUserRoleCode::Admin->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByUserAndUuid(User $user, Uuid $uuid): ?WorkspaceUserRoleAssignment
    {
        return $this->createQueryBuilder('assignment')
            ->andWhere('assignment.user = :user')
            ->andWhere('assignment.uuid = :uuid')
            ->setParameter('user', $user)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
