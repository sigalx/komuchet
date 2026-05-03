<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\WorkspaceUserRoleCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Workspace>
 */
class WorkspaceRepository extends ServiceEntityRepository
{
    public const SORT_CODE = 'code';
    public const SORT_NAME = 'name';
    public const SORT_UPDATED_AT = 'updated_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Workspace::class);
    }

    /**
     * @return list<Workspace>
     */
    public function findAllOrderedByCode(): array
    {
        return $this->createForAdminListQuery()
            ->getQuery()
            ->getResult();
    }

    public function createForAdminListQuery(
        string $sort = self::SORT_CODE,
        string $direction = self::SORT_ASC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('workspace');

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_CODE,
            self::SORT_NAME,
            self::SORT_UPDATED_AT,
        ], true) ? $sort : self::SORT_CODE;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_DESC ? self::SORT_DESC : self::SORT_ASC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_DESC ? 'DESC' : 'ASC';

        match ($sort) {
            self::SORT_NAME => $queryBuilder
                ->orderBy('workspace.name', $dqlDirection)
                ->addOrderBy('workspace.code', 'ASC'),
            self::SORT_UPDATED_AT => $queryBuilder
                ->orderBy('workspace.updatedAt', $dqlDirection)
                ->addOrderBy('workspace.code', 'ASC'),
            default => $queryBuilder
                ->orderBy('workspace.code', $dqlDirection)
                ->addOrderBy('workspace.name', 'ASC'),
        };
    }

    /**
     * @param list<WorkspaceUserRoleCode> $roleCodes
     *
     * @return list<Workspace>
     */
    public function findAccessibleByUser(User $user, array $roleCodes): array
    {
        if ($roleCodes === []) {
            return [];
        }

        return $this->createQueryBuilder('workspace')
            ->distinct()
            ->innerJoin(
                WorkspaceUserRoleAssignment::class,
                'assignment',
                'WITH',
                'assignment.workspace = workspace',
            )
            ->andWhere('assignment.user = :user')
            ->andWhere('assignment.roleCode IN (:roleCodes)')
            ->andWhere('assignment.revokedAt IS NULL')
            ->setParameter('user', $user)
            ->setParameter(
                'roleCodes',
                array_map(static fn (WorkspaceUserRoleCode $roleCode): string => $roleCode->value, $roleCodes),
            )
            ->orderBy('workspace.code', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
