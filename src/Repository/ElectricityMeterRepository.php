<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\ElectricityMeter;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ElectricityMeter>
 */
class ElectricityMeterRepository extends ServiceEntityRepository
{
    public const STATUS_FILTER_ALL = 'all';
    public const STATUS_FILTER_ACTIVE = 'active';
    public const STATUS_FILTER_REMOVED = 'removed';

    public const SORT_ACCOUNT_NUMBER = 'account_number';
    public const SORT_SERIAL_NUMBER = 'serial_number';
    public const SORT_MODEL = 'model';
    public const SORT_INSTALLED_ON = 'installed_on';
    public const SORT_REMOVED_ON = 'removed_on';
    public const SORT_VERIFICATION_VALID_UNTIL = 'verification_valid_until';
    public const SORT_UPDATED_AT = 'updated_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityMeter::class);
    }

    /**
     * @return list<ElectricityMeter>
     */
    public function findNonDeletedByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('electricityMeter')
            ->addSelect('account')
            ->innerJoin('electricityMeter.account', 'account')
            ->andWhere('electricityMeter.workspace = :workspace')
            ->andWhere('electricityMeter.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->orderBy('account.number', 'ASC')
            ->addOrderBy('electricityMeter.installedOn', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ElectricityMeter>
     */
    public function findNonDeletedByWorkspaceForAdminList(
        Workspace $workspace,
        string $search = '',
        string $statusFilter = self::STATUS_FILTER_ALL,
        string $sort = self::SORT_ACCOUNT_NUMBER,
        string $direction = self::SORT_ASC,
    ): array
    {
        return $this->createNonDeletedByWorkspaceForAdminListQuery($workspace, $search, $statusFilter, $sort, $direction)
            ->getQuery()
            ->getResult();
    }

    public function createNonDeletedByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $search = '',
        string $statusFilter = self::STATUS_FILTER_ALL,
        string $sort = self::SORT_ACCOUNT_NUMBER,
        string $direction = self::SORT_ASC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('electricityMeter')
            ->addSelect('account')
            ->innerJoin('electricityMeter.account', 'account')
            ->andWhere('electricityMeter.workspace = :workspace')
            ->andWhere('electricityMeter.deletedAt IS NULL')
            ->setParameter('workspace', $workspace);

        $search = trim($search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere(<<<'DQL'
                    LOWER(account.number) LIKE :search
                    OR LOWER(electricityMeter.serialNumber) LIKE :search
                    OR LOWER(electricityMeter.model) LIKE :search
                    OR LOWER(electricityMeter.notes) LIKE :search
                    DQL)
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ($statusFilter === self::STATUS_FILTER_ACTIVE) {
            $queryBuilder->andWhere('electricityMeter.removedOn IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_REMOVED) {
            $queryBuilder->andWhere('electricityMeter.removedOn IS NOT NULL');
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeStatusFilter(string $statusFilter): string
    {
        return in_array($statusFilter, [
            self::STATUS_FILTER_ALL,
            self::STATUS_FILTER_ACTIVE,
            self::STATUS_FILTER_REMOVED,
        ], true) ? $statusFilter : self::STATUS_FILTER_ALL;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_ACCOUNT_NUMBER,
            self::SORT_SERIAL_NUMBER,
            self::SORT_MODEL,
            self::SORT_INSTALLED_ON,
            self::SORT_REMOVED_ON,
            self::SORT_VERIFICATION_VALID_UNTIL,
            self::SORT_UPDATED_AT,
        ], true) ? $sort : self::SORT_ACCOUNT_NUMBER;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_DESC ? self::SORT_DESC : self::SORT_ASC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_DESC ? 'DESC' : 'ASC';

        match ($sort) {
            self::SORT_SERIAL_NUMBER => $queryBuilder
                ->orderBy('electricityMeter.serialNumber', $dqlDirection)
                ->addOrderBy('account.number', 'ASC')
                ->addOrderBy('electricityMeter.installedOn', 'DESC'),
            self::SORT_MODEL => $queryBuilder
                ->orderBy('electricityMeter.model', $dqlDirection)
                ->addOrderBy('account.number', 'ASC')
                ->addOrderBy('electricityMeter.installedOn', 'DESC'),
            self::SORT_INSTALLED_ON => $queryBuilder
                ->orderBy('electricityMeter.installedOn', $dqlDirection)
                ->addOrderBy('account.number', 'ASC'),
            self::SORT_REMOVED_ON => $queryBuilder
                ->orderBy('electricityMeter.removedOn', $dqlDirection)
                ->addOrderBy('account.number', 'ASC')
                ->addOrderBy('electricityMeter.installedOn', 'DESC'),
            self::SORT_VERIFICATION_VALID_UNTIL => $queryBuilder
                ->orderBy('electricityMeter.verificationValidUntil', $dqlDirection)
                ->addOrderBy('account.number', 'ASC')
                ->addOrderBy('electricityMeter.installedOn', 'DESC'),
            self::SORT_UPDATED_AT => $queryBuilder
                ->orderBy('electricityMeter.updatedAt', $dqlDirection)
                ->addOrderBy('account.number', 'ASC'),
            default => $queryBuilder
                ->orderBy('account.number', $dqlDirection)
                ->addOrderBy('electricityMeter.installedOn', 'DESC'),
        };
    }

    /**
     * @return list<ElectricityMeter>
     */
    public function findActiveByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('electricityMeter')
            ->addSelect('account')
            ->innerJoin('electricityMeter.account', 'account')
            ->andWhere('electricityMeter.workspace = :workspace')
            ->andWhere('electricityMeter.removedOn IS NULL')
            ->andWhere('electricityMeter.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->orderBy('account.number', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneNonDeletedByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?ElectricityMeter
    {
        return $this->createQueryBuilder('electricityMeter')
            ->addSelect('account')
            ->innerJoin('electricityMeter.account', 'account')
            ->andWhere('electricityMeter.workspace = :workspace')
            ->andWhere('electricityMeter.uuid = :uuid')
            ->andWhere('electricityMeter.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByWorkspaceAndAccount(Workspace $workspace, Account $account): ?ElectricityMeter
    {
        return $this->createQueryBuilder('electricityMeter')
            ->andWhere('electricityMeter.workspace = :workspace')
            ->andWhere('electricityMeter.account = :account')
            ->andWhere('electricityMeter.removedOn IS NULL')
            ->andWhere('electricityMeter.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ElectricityMeter>
     */
    public function findNonDeletedByWorkspaceAndAccount(Workspace $workspace, Account $account): array
    {
        return $this->createQueryBuilder('electricityMeter')
            ->andWhere('electricityMeter.workspace = :workspace')
            ->andWhere('electricityMeter.account = :account')
            ->andWhere('electricityMeter.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('electricityMeter.installedOn', 'DESC')
            ->addOrderBy('electricityMeter.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<Account> $accounts
     *
     * @return array<string, ElectricityMeter>
     */
    public function findActiveIndexedByWorkspaceAndAccounts(Workspace $workspace, array $accounts): array
    {
        if ($accounts === []) {
            return [];
        }

        $meters = $this->createQueryBuilder('electricityMeter')
            ->addSelect('account')
            ->innerJoin('electricityMeter.account', 'account')
            ->andWhere('electricityMeter.workspace = :workspace')
            ->andWhere('electricityMeter.account IN (:accounts)')
            ->andWhere('electricityMeter.removedOn IS NULL')
            ->andWhere('electricityMeter.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('accounts', $accounts)
            ->orderBy('account.number', 'ASC')
            ->getQuery()
            ->getResult();

        $indexedMeters = [];

        foreach ($meters as $meter) {
            if (!$meter instanceof ElectricityMeter || !$meter->getAccount() instanceof Account) {
                continue;
            }

            $indexedMeters[$meter->getAccount()->getUuid()->toRfc4122()] = $meter;
        }

        return $indexedMeters;
    }
}
