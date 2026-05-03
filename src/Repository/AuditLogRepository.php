<?php

namespace App\Repository;

use App\Entity\AuditLog;
use App\Entity\UserEmailIdentity;
use App\Entity\Workspace;
use App\Enum\AuditLogSource;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public const SORT_OCCURRED_AT = 'occurred_at';
    public const SORT_WORKSPACE = 'workspace';
    public const SORT_ACTOR = 'actor';
    public const SORT_SOURCE = 'source';
    public const SORT_ACTION = 'action';
    public const SORT_ENTITY_TABLE = 'entity_table';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }

    /**
     * @param array{
     *     workspace?: string,
     *     action?: string,
     *     source?: AuditLogSource|null,
     *     actor?: string,
     *     entity_table?: string,
     *     entity_uuid?: Uuid|null,
     *     occurred_from?: DateTimeImmutable|null,
     *     occurred_to?: DateTimeImmutable|null
     * } $filters
     *
     * @return list<AuditLog>
     */
    public function findForAdmin(
        Workspace $currentWorkspace,
        bool $globalAdmin,
        array $filters,
        int $limit = 300,
        string $sort = self::SORT_OCCURRED_AT,
        string $direction = self::SORT_DESC,
    ): array
    {
        return $this->createForAdminQuery($currentWorkspace, $globalAdmin, $filters, $sort, $direction)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<Uuid> $fileUuids
     *
     * @return array<string, AuditLog>
     */
    public function findLatestZavetyMichurinaImportApplyLogsByFileUuids(Workspace $workspace, array $fileUuids): array
    {
        if ($fileUuids === []) {
            return [];
        }

        $logs = $this->createQueryBuilder('auditLog')
            ->andWhere('auditLog.workspace = :workspace')
            ->andWhere('auditLog.action = :action')
            ->andWhere('auditLog.source = :source')
            ->andWhere('auditLog.entityTable = :entityTable')
            ->andWhere('auditLog.entityUuid IN (:fileUuids)')
            ->setParameter('workspace', $workspace)
            ->setParameter('action', 'zavety_michurina_statement_import.applied')
            ->setParameter('source', AuditLogSource::Import)
            ->setParameter('entityTable', 'zavety_michurina_statement_import_files')
            ->setParameter('fileUuids', $fileUuids)
            ->orderBy('auditLog.occurredAt', 'DESC')
            ->addOrderBy('auditLog.uuid', 'DESC')
            ->getQuery()
            ->getResult();

        $indexed = [];

        foreach ($logs as $log) {
            if (!$log instanceof AuditLog || $log->getEntityUuid() === null) {
                continue;
            }

            $entityUuid = $log->getEntityUuid()->toRfc4122();
            $indexed[$entityUuid] ??= $log;
        }

        return $indexed;
    }

    /**
     * @param array{
     *     workspace?: string,
     *     action?: string,
     *     source?: AuditLogSource|null,
     *     actor?: string,
     *     entity_table?: string,
     *     entity_uuid?: Uuid|null,
     *     occurred_from?: DateTimeImmutable|null,
     *     occurred_to?: DateTimeImmutable|null
     * } $filters
     */
    public function createForAdminQuery(
        Workspace $currentWorkspace,
        bool $globalAdmin,
        array $filters,
        string $sort = self::SORT_OCCURRED_AT,
        string $direction = self::SORT_DESC,
    ): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('auditLog')
            ->addSelect('workspace', 'actorUser')
            ->leftJoin('auditLog.workspace', 'workspace')
            ->leftJoin('auditLog.actorUser', 'actorUser');

        if (!$globalAdmin) {
            $queryBuilder
                ->andWhere('auditLog.workspace = :currentWorkspace')
                ->setParameter('currentWorkspace', $currentWorkspace);
        } else {
            $workspaceFilter = $filters['workspace'] ?? 'all';

            if ($workspaceFilter === 'current') {
                $queryBuilder
                    ->andWhere('auditLog.workspace = :currentWorkspace')
                    ->setParameter('currentWorkspace', $currentWorkspace);
            } elseif ($workspaceFilter === 'global') {
                $queryBuilder->andWhere('auditLog.workspace IS NULL');
            } elseif (Uuid::isValid($workspaceFilter)) {
                $queryBuilder
                    ->andWhere('workspace.uuid = :workspaceUuid')
                    ->setParameter('workspaceUuid', Uuid::fromString($workspaceFilter));
            }
        }

        $action = trim((string) ($filters['action'] ?? ''));

        if ($action !== '') {
            $queryBuilder
                ->andWhere('LOWER(auditLog.action) LIKE :action')
                ->setParameter('action', '%'.mb_strtolower($action).'%');
        }

        if (($filters['source'] ?? null) instanceof AuditLogSource) {
            $queryBuilder
                ->andWhere('auditLog.source = :source')
                ->setParameter('source', $filters['source']);
        }

        $actor = trim((string) ($filters['actor'] ?? ''));

        if ($actor !== '') {
            if (Uuid::isValid($actor)) {
                $queryBuilder
                    ->andWhere('actorUser.uuid = :actorUuid')
                    ->setParameter('actorUuid', Uuid::fromString($actor));
            } else {
                $queryBuilder
                    ->leftJoin(
                        UserEmailIdentity::class,
                        'actorEmailIdentity',
                        Join::WITH,
                        'actorEmailIdentity.user = actorUser AND actorEmailIdentity.deletedAt IS NULL',
                    )
                    ->andWhere('actorEmailIdentity.emailNormalized LIKE :actorEmail')
                    ->setParameter('actorEmail', '%'.UserEmailIdentity::normalizeEmail($actor).'%');
            }
        }

        $entityTable = trim((string) ($filters['entity_table'] ?? ''));

        if ($entityTable !== '') {
            $queryBuilder
                ->andWhere('LOWER(auditLog.entityTable) LIKE :entityTable')
                ->setParameter('entityTable', '%'.mb_strtolower($entityTable).'%');
        }

        if (($filters['entity_uuid'] ?? null) instanceof Uuid) {
            $queryBuilder
                ->andWhere('auditLog.entityUuid = :entityUuid')
                ->setParameter('entityUuid', $filters['entity_uuid']);
        }

        if (($filters['occurred_from'] ?? null) instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('auditLog.occurredAt >= :occurredFrom')
                ->setParameter('occurredFrom', $filters['occurred_from']);
        }

        if (($filters['occurred_to'] ?? null) instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('auditLog.occurredAt < :occurredTo')
                ->setParameter('occurredTo', $filters['occurred_to']);
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_OCCURRED_AT,
            self::SORT_WORKSPACE,
            self::SORT_ACTOR,
            self::SORT_SOURCE,
            self::SORT_ACTION,
            self::SORT_ENTITY_TABLE,
        ], true) ? $sort : self::SORT_OCCURRED_AT;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_ASC ? self::SORT_ASC : self::SORT_DESC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_ASC ? 'ASC' : 'DESC';

        match ($sort) {
            self::SORT_WORKSPACE => $queryBuilder
                ->orderBy('workspace.name', $dqlDirection)
                ->addOrderBy('auditLog.occurredAt', 'DESC')
                ->addOrderBy('auditLog.uuid', 'DESC'),
            self::SORT_ACTOR => $queryBuilder
                ->orderBy('actorUser.uuid', $dqlDirection)
                ->addOrderBy('auditLog.occurredAt', 'DESC')
                ->addOrderBy('auditLog.uuid', 'DESC'),
            self::SORT_SOURCE => $queryBuilder
                ->orderBy('auditLog.source', $dqlDirection)
                ->addOrderBy('auditLog.occurredAt', 'DESC')
                ->addOrderBy('auditLog.uuid', 'DESC'),
            self::SORT_ACTION => $queryBuilder
                ->orderBy('auditLog.action', $dqlDirection)
                ->addOrderBy('auditLog.occurredAt', 'DESC')
                ->addOrderBy('auditLog.uuid', 'DESC'),
            self::SORT_ENTITY_TABLE => $queryBuilder
                ->orderBy('auditLog.entityTable', $dqlDirection)
                ->addOrderBy('auditLog.occurredAt', 'DESC')
                ->addOrderBy('auditLog.uuid', 'DESC'),
            default => $queryBuilder
                ->orderBy('auditLog.occurredAt', $dqlDirection)
                ->addOrderBy('auditLog.uuid', $dqlDirection),
        };
    }
}
