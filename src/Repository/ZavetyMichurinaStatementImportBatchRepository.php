<?php

namespace App\Repository;

use App\Entity\Workspace;
use App\Entity\ZavetyMichurinaStatementImportBatch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ZavetyMichurinaStatementImportBatch>
 */
class ZavetyMichurinaStatementImportBatchRepository extends ServiceEntityRepository
{
    public const SORT_CREATED_AT = 'created_at';
    public const SORT_NAME = 'name';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ZavetyMichurinaStatementImportBatch::class);
    }

    public function createByWorkspaceQuery(
        Workspace $workspace,
        string $sort = self::SORT_CREATED_AT,
        string $direction = self::SORT_DESC,
    ): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('batch')
            ->andWhere('batch.workspace = :workspace')
            ->setParameter('workspace', $workspace);

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_CREATED_AT,
            self::SORT_NAME,
        ], true) ? $sort : self::SORT_CREATED_AT;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_ASC ? self::SORT_ASC : self::SORT_DESC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_ASC ? 'ASC' : 'DESC';

        match ($sort) {
            self::SORT_NAME => $queryBuilder
                ->orderBy('batch.name', $dqlDirection)
                ->addOrderBy('batch.createdAt', 'DESC'),
            default => $queryBuilder
                ->orderBy('batch.createdAt', $dqlDirection)
                ->addOrderBy('batch.name', 'ASC'),
        };
    }

    public function findOneByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?ZavetyMichurinaStatementImportBatch
    {
        return $this->createQueryBuilder('batch')
            ->andWhere('batch.workspace = :workspace')
            ->andWhere('batch.uuid = :uuid')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
