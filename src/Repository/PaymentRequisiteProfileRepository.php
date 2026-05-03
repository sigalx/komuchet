<?php

namespace App\Repository;

use App\Entity\PaymentRequisiteProfile;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PaymentRequisiteProfile>
 */
class PaymentRequisiteProfileRepository extends ServiceEntityRepository
{
    public const SORT_CODE = 'code';
    public const SORT_NAME = 'name';
    public const SORT_RECIPIENT_NAME = 'recipient_name';
    public const SORT_BANK_NAME = 'bank_name';
    public const SORT_VALID_FROM = 'valid_from';
    public const SORT_UPDATED_AT = 'updated_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentRequisiteProfile::class);
    }

    /**
     * @return list<PaymentRequisiteProfile>
     */
    public function findActiveByWorkspace(Workspace $workspace): array
    {
        return $this->createActiveByWorkspaceForAdminListQuery($workspace)
            ->getQuery()
            ->getResult();
    }

    public function createActiveByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $sort = self::SORT_NAME,
        string $direction = self::SORT_ASC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('profile')
            ->andWhere('profile.workspace = :workspace')
            ->andWhere('profile.deletedAt IS NULL')
            ->setParameter('workspace', $workspace);

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_CODE,
            self::SORT_NAME,
            self::SORT_RECIPIENT_NAME,
            self::SORT_BANK_NAME,
            self::SORT_VALID_FROM,
            self::SORT_UPDATED_AT,
        ], true) ? $sort : self::SORT_NAME;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_DESC ? self::SORT_DESC : self::SORT_ASC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_DESC ? 'DESC' : 'ASC';

        match ($sort) {
            self::SORT_CODE => $queryBuilder
                ->orderBy('profile.code', $dqlDirection)
                ->addOrderBy('profile.name', 'ASC'),
            self::SORT_RECIPIENT_NAME => $queryBuilder
                ->orderBy('profile.recipientName', $dqlDirection)
                ->addOrderBy('profile.name', 'ASC'),
            self::SORT_BANK_NAME => $queryBuilder
                ->orderBy('profile.bankName', $dqlDirection)
                ->addOrderBy('profile.name', 'ASC'),
            self::SORT_VALID_FROM => $queryBuilder
                ->orderBy('profile.validFrom', $dqlDirection)
                ->addOrderBy('profile.name', 'ASC'),
            self::SORT_UPDATED_AT => $queryBuilder
                ->orderBy('profile.updatedAt', $dqlDirection)
                ->addOrderBy('profile.name', 'ASC'),
            default => $queryBuilder
                ->orderBy('profile.name', $dqlDirection)
                ->addOrderBy('profile.code', 'ASC'),
        };
    }

    public function findOneActiveByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?PaymentRequisiteProfile
    {
        return $this->createQueryBuilder('profile')
            ->andWhere('profile.workspace = :workspace')
            ->andWhere('profile.uuid = :uuid')
            ->andWhere('profile.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByWorkspaceAndCode(Workspace $workspace, string $code): ?PaymentRequisiteProfile
    {
        return $this->createQueryBuilder('profile')
            ->andWhere('profile.workspace = :workspace')
            ->andWhere('profile.code = :code')
            ->andWhere('profile.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('code', trim($code))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
