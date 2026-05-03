<?php

namespace App\Repository;

use App\Entity\Workspace;
use App\Entity\ZavetyMichurinaStatementImportBatch;
use App\Entity\ZavetyMichurinaStatementImportFile;
use App\Enum\ZavetyMichurinaStatementImportFileStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ZavetyMichurinaStatementImportFile>
 */
class ZavetyMichurinaStatementImportFileRepository extends ServiceEntityRepository
{
    public const SORT_CREATED_AT = 'created_at';
    public const SORT_ORIGINAL_FILENAME = 'original_filename';
    public const SORT_STATUS = 'status';
    public const SORT_DETECTED_ACCOUNT_NUMBER = 'detected_account_number';
    public const SORT_DETECTED_SUBSCRIBER_FULL_NAME = 'detected_subscriber_full_name';
    public const SORT_PARSED_AT = 'parsed_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ZavetyMichurinaStatementImportFile::class);
    }

    public function createByBatchQuery(
        Workspace $workspace,
        ZavetyMichurinaStatementImportBatch $batch,
        string $sort = self::SORT_CREATED_AT,
        string $direction = self::SORT_ASC,
    ): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilder('file')
            ->andWhere('file.workspace = :workspace')
            ->andWhere('file.batch = :batch')
            ->setParameter('workspace', $workspace)
            ->setParameter('batch', $batch);

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_CREATED_AT,
            self::SORT_ORIGINAL_FILENAME,
            self::SORT_STATUS,
            self::SORT_DETECTED_ACCOUNT_NUMBER,
            self::SORT_DETECTED_SUBSCRIBER_FULL_NAME,
            self::SORT_PARSED_AT,
        ], true) ? $sort : self::SORT_CREATED_AT;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_DESC ? self::SORT_DESC : self::SORT_ASC;
    }

    public function compareForAdminList(
        ZavetyMichurinaStatementImportFile $left,
        ZavetyMichurinaStatementImportFile $right,
        string $sort,
        string $direction,
    ): int {
        $sort = $this->normalizeSort($sort);
        $direction = $this->normalizeSortDirection($direction);
        $multiplier = $direction === self::SORT_DESC ? -1 : 1;

        $leftValue = $this->adminListSortValue($left, $sort);
        $rightValue = $this->adminListSortValue($right, $sort);
        $result = $leftValue <=> $rightValue;

        if ($result === 0) {
            $result = $left->getCreatedAt() <=> $right->getCreatedAt();
        }

        if ($result === 0) {
            $result = $left->getUuid()->toRfc4122() <=> $right->getUuid()->toRfc4122();
        }

        return $result * $multiplier;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_DESC ? 'DESC' : 'ASC';

        match ($sort) {
            self::SORT_ORIGINAL_FILENAME => $queryBuilder
                ->orderBy('file.originalFilename', $dqlDirection)
                ->addOrderBy('file.createdAt', 'ASC'),
            self::SORT_STATUS => $queryBuilder
                ->orderBy('file.status', $dqlDirection)
                ->addOrderBy('file.createdAt', 'ASC'),
            self::SORT_DETECTED_ACCOUNT_NUMBER => $queryBuilder
                ->orderBy('file.detectedAccountNumber', $dqlDirection)
                ->addOrderBy('file.createdAt', 'ASC'),
            self::SORT_DETECTED_SUBSCRIBER_FULL_NAME => $queryBuilder
                ->orderBy('file.detectedSubscriberFullName', $dqlDirection)
                ->addOrderBy('file.createdAt', 'ASC'),
            self::SORT_PARSED_AT => $queryBuilder
                ->orderBy('file.parsedAt', $dqlDirection)
                ->addOrderBy('file.createdAt', 'ASC'),
            default => $queryBuilder
                ->orderBy('file.createdAt', $dqlDirection)
                ->addOrderBy('file.originalFilename', 'ASC'),
        };
    }

    private function adminListSortValue(ZavetyMichurinaStatementImportFile $file, string $sort): int|string
    {
        return match ($sort) {
            self::SORT_ORIGINAL_FILENAME => mb_strtolower($file->getOriginalFilename()),
            self::SORT_STATUS => $file->getStatus()->value,
            self::SORT_DETECTED_ACCOUNT_NUMBER => $file->getDetectedAccountNumber() ?? '',
            self::SORT_DETECTED_SUBSCRIBER_FULL_NAME => mb_strtolower($file->getDetectedSubscriberFullName() ?? ''),
            self::SORT_PARSED_AT => $file->getParsedAt()?->getTimestamp() ?? 0,
            default => $file->getCreatedAt()->getTimestamp(),
        };
    }

    /**
     * @return list<ZavetyMichurinaStatementImportFile>
     */
    public function findByBatchAndStatus(
        Workspace $workspace,
        ZavetyMichurinaStatementImportBatch $batch,
        ZavetyMichurinaStatementImportFileStatus $status,
    ): array {
        return $this->createByBatchQuery($workspace, $batch)
            ->andWhere('file.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getResult();
    }

    public function findOneByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?ZavetyMichurinaStatementImportFile
    {
        return $this->createQueryBuilder('file')
            ->addSelect('batch')
            ->innerJoin('file.batch', 'batch')
            ->andWhere('file.workspace = :workspace')
            ->andWhere('file.uuid = :uuid')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{total: int, pending: int, parsed: int, failed: int, applied: int, cancelled: int}
     */
    public function summarizeBatch(ZavetyMichurinaStatementImportBatch $batch): array
    {
        $summary = [
            'total' => 0,
            ZavetyMichurinaStatementImportFileStatus::Pending->value => 0,
            ZavetyMichurinaStatementImportFileStatus::Parsed->value => 0,
            ZavetyMichurinaStatementImportFileStatus::Failed->value => 0,
            ZavetyMichurinaStatementImportFileStatus::Applied->value => 0,
            ZavetyMichurinaStatementImportFileStatus::Cancelled->value => 0,
        ];
        $rows = $this->createQueryBuilder('file')
            ->select('file.status AS status, COUNT(file.uuid) AS file_count')
            ->andWhere('file.batch = :batch')
            ->setParameter('batch', $batch)
            ->groupBy('file.status')
            ->getQuery()
            ->getArrayResult();

        foreach ($rows as $row) {
            $status = $row['status'];

            if ($status instanceof ZavetyMichurinaStatementImportFileStatus) {
                $status = $status->value;
            }

            $count = (int) $row['file_count'];
            $summary['total'] += $count;

            if (array_key_exists($status, $summary)) {
                $summary[$status] = $count;
            }
        }

        return $summary;
    }
}
