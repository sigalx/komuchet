<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Payment;
use App\Entity\Workspace;
use App\Enum\PaymentSource;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public const STATUS_FILTER_ALL = 'all';
    public const STATUS_FILTER_ACTIVE = 'active';
    public const STATUS_FILTER_CANCELLED = 'cancelled';
    public const STATUS_FILTER_SUPERSEDED = 'superseded';

    public const SORT_PAID_ON = 'paid_on';
    public const SORT_ACCOUNT_NUMBER = 'account_number';
    public const SORT_AMOUNT = 'amount';
    public const SORT_PAYER_NAME = 'payer_name';
    public const SORT_SOURCE = 'source';
    public const SORT_CREATED_AT = 'created_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * @return list<Payment>
     */
    public function findByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('payment')
            ->addSelect('account')
            ->innerJoin('payment.account', 'account')
            ->andWhere('payment.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('payment.paidOn', 'DESC')
            ->addOrderBy('payment.createdAt', 'DESC')
            ->setMaxResults(300)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Payment>
     */
    public function findByWorkspaceForAdminList(
        Workspace $workspace,
        string $search = '',
        string $statusFilter = self::STATUS_FILTER_ALL,
        ?PaymentSource $source = null,
        ?DateTimeImmutable $paidOnFrom = null,
        ?DateTimeImmutable $paidOnTo = null,
        string $sort = self::SORT_PAID_ON,
        string $direction = self::SORT_DESC,
    ): array {
        return $this->createByWorkspaceForAdminListQuery($workspace, $search, $statusFilter, $source, $paidOnFrom, $paidOnTo, $sort, $direction)
            ->getQuery()
            ->getResult();
    }

    public function createByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $search = '',
        string $statusFilter = self::STATUS_FILTER_ALL,
        ?PaymentSource $source = null,
        ?DateTimeImmutable $paidOnFrom = null,
        ?DateTimeImmutable $paidOnTo = null,
        string $sort = self::SORT_PAID_ON,
        string $direction = self::SORT_DESC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('payment')
            ->addSelect('account')
            ->innerJoin('payment.account', 'account')
            ->andWhere('payment.workspace = :workspace')
            ->setParameter('workspace', $workspace);

        $search = trim($search);

        if ($search !== '') {
            $searchConditions = [
                'LOWER(account.number) LIKE :search',
                'LOWER(payment.payerName) LIKE :search',
                'LOWER(payment.purpose) LIKE :search',
                'LOWER(payment.externalReference) LIKE :search',
            ];

            $searchAmount = $this->normalizeSearchAmount($search);

            if ($searchAmount !== null) {
                $searchConditions[] = 'payment.amount = :searchAmount';
                $queryBuilder->setParameter('searchAmount', $searchAmount);
            }

            $queryBuilder
                ->andWhere('('.implode(' OR ', $searchConditions).')')
                ->setParameter('search', '%'.mb_strtolower($search).'%');
        }

        if ($statusFilter === self::STATUS_FILTER_ACTIVE) {
            $queryBuilder
                ->andWhere('payment.cancelledAt IS NULL')
                ->andWhere('payment.replacingPayment IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_CANCELLED) {
            $queryBuilder->andWhere('payment.cancelledAt IS NOT NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_SUPERSEDED) {
            $queryBuilder->andWhere('payment.replacingPayment IS NOT NULL');
        }

        if ($source instanceof PaymentSource) {
            $queryBuilder
                ->andWhere('payment.source = :source')
                ->setParameter('source', $source);
        }

        if ($paidOnFrom instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('payment.paidOn >= :paidOnFrom')
                ->setParameter('paidOnFrom', $paidOnFrom);
        }

        if ($paidOnTo instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('payment.paidOn <= :paidOnTo')
                ->setParameter('paidOnTo', $paidOnTo);
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_PAID_ON,
            self::SORT_ACCOUNT_NUMBER,
            self::SORT_AMOUNT,
            self::SORT_PAYER_NAME,
            self::SORT_SOURCE,
            self::SORT_CREATED_AT,
        ], true) ? $sort : self::SORT_PAID_ON;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_ASC ? self::SORT_ASC : self::SORT_DESC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_ASC ? 'ASC' : 'DESC';

        match ($sort) {
            self::SORT_ACCOUNT_NUMBER => $queryBuilder
                ->orderBy('account.number', $dqlDirection)
                ->addOrderBy('payment.paidOn', 'DESC')
                ->addOrderBy('payment.createdAt', 'DESC'),
            self::SORT_AMOUNT => $queryBuilder
                ->orderBy('payment.amount', $dqlDirection)
                ->addOrderBy('payment.paidOn', 'DESC')
                ->addOrderBy('payment.createdAt', 'DESC'),
            self::SORT_PAYER_NAME => $queryBuilder
                ->orderBy('payment.payerName', $dqlDirection)
                ->addOrderBy('payment.paidOn', 'DESC')
                ->addOrderBy('payment.createdAt', 'DESC'),
            self::SORT_SOURCE => $queryBuilder
                ->orderBy('payment.source', $dqlDirection)
                ->addOrderBy('payment.paidOn', 'DESC')
                ->addOrderBy('payment.createdAt', 'DESC'),
            self::SORT_CREATED_AT => $queryBuilder
                ->orderBy('payment.createdAt', $dqlDirection)
                ->addOrderBy('payment.paidOn', 'DESC'),
            default => $queryBuilder
                ->orderBy('payment.paidOn', $dqlDirection)
                ->addOrderBy('payment.createdAt', $dqlDirection)
                ->addOrderBy('account.number', 'ASC'),
        };
    }

    public function normalizeStatusFilter(string $statusFilter): string
    {
        return in_array($statusFilter, [
            self::STATUS_FILTER_ALL,
            self::STATUS_FILTER_ACTIVE,
            self::STATUS_FILTER_CANCELLED,
            self::STATUS_FILTER_SUPERSEDED,
        ], true) ? $statusFilter : self::STATUS_FILTER_ALL;
    }

    public function normalizeSourceFilter(string $sourceFilter): ?PaymentSource
    {
        return PaymentSource::tryFrom($sourceFilter);
    }

    /**
     * @return list<Payment>
     */
    public function findLatestByWorkspace(Workspace $workspace, int $limit = 5): array
    {
        return $this->createQueryBuilder('payment')
            ->addSelect('account')
            ->innerJoin('payment.account', 'account')
            ->andWhere('payment.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->orderBy('payment.paidOn', 'DESC')
            ->addOrderBy('payment.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Payment>
     */
    public function findByAccount(Workspace $workspace, Account $account): array
    {
        return $this->createByAccountForPortalListQuery($workspace, $account)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Payment>
     */
    public function findActiveByAccount(Workspace $workspace, Account $account): array
    {
        return $this->createQueryBuilder('payment')
            ->andWhere('payment.workspace = :workspace')
            ->andWhere('payment.account = :account')
            ->andWhere('payment.cancelledAt IS NULL')
            ->andWhere('payment.replacingPayment IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('payment.paidOn', 'DESC')
            ->addOrderBy('payment.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function createByAccountForPortalListQuery(
        Workspace $workspace,
        Account $account,
        string $statusFilter = self::STATUS_FILTER_ALL,
        ?DateTimeImmutable $paidOnFrom = null,
        ?DateTimeImmutable $paidOnTo = null,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('payment')
            ->andWhere('payment.workspace = :workspace')
            ->andWhere('payment.account = :account')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->orderBy('payment.paidOn', 'DESC')
            ->addOrderBy('payment.createdAt', 'DESC');

        if ($statusFilter === self::STATUS_FILTER_ACTIVE) {
            $queryBuilder
                ->andWhere('payment.cancelledAt IS NULL')
                ->andWhere('payment.replacingPayment IS NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_CANCELLED) {
            $queryBuilder->andWhere('payment.cancelledAt IS NOT NULL');
        } elseif ($statusFilter === self::STATUS_FILTER_SUPERSEDED) {
            $queryBuilder->andWhere('payment.replacingPayment IS NOT NULL');
        }

        if ($paidOnFrom instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('payment.paidOn >= :accountPaidOnFrom')
                ->setParameter('accountPaidOnFrom', $paidOnFrom);
        }

        if ($paidOnTo instanceof DateTimeImmutable) {
            $queryBuilder
                ->andWhere('payment.paidOn <= :accountPaidOnTo')
                ->setParameter('accountPaidOnTo', $paidOnTo);
        }

        return $queryBuilder;
    }

    public function findOneByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?Payment
    {
        return $this->createQueryBuilder('payment')
            ->addSelect('account')
            ->innerJoin('payment.account', 'account')
            ->andWhere('payment.workspace = :workspace')
            ->andWhere('payment.uuid = :uuid')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByWorkspaceAndExternalReference(Workspace $workspace, string $externalReference): ?Payment
    {
        return $this->createQueryBuilder('payment')
            ->addSelect('account')
            ->innerJoin('payment.account', 'account')
            ->andWhere('payment.workspace = :workspace')
            ->andWhere('payment.externalReference = :externalReference')
            ->setParameter('workspace', $workspace)
            ->setParameter('externalReference', $externalReference)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function sumActiveAmountByAccount(Workspace $workspace, Account $account): string
    {
        $amount = $this->createQueryBuilder('payment')
            ->select('COALESCE(SUM(payment.amount), 0)')
            ->andWhere('payment.workspace = :workspace')
            ->andWhere('payment.account = :account')
            ->andWhere('payment.cancelledAt IS NULL')
            ->andWhere('payment.replacingPayment IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();

        return (string) $amount;
    }

    private function normalizeSearchAmount(string $search): ?string
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($search));

        if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
            return null;
        }

        [$rubles, $kopecks] = array_pad(explode('.', $normalized, 2), 2, '0');

        return $rubles.'.'.str_pad($kopecks, 2, '0');
    }
}
