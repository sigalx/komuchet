<?php

namespace App\Repository;

use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\Workspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Subscriber>
 */
class SubscriberRepository extends ServiceEntityRepository
{
    public const PORTAL_FILTER_ALL = 'all';
    public const PORTAL_FILTER_LINKED = 'linked';
    public const PORTAL_FILTER_UNLINKED = 'unlinked';

    public const ACCOUNT_FILTER_ALL = 'all';
    public const ACCOUNT_FILTER_WITH_ACCOUNTS = 'with_accounts';
    public const ACCOUNT_FILTER_WITHOUT_ACCOUNTS = 'without_accounts';

    public const SORT_FULL_NAME = 'full_name';
    public const SORT_EMAIL = 'email';
    public const SORT_PHONE = 'phone';
    public const SORT_UPDATED_AT = 'updated_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscriber::class);
    }

    /**
     * @return list<Subscriber>
     */
    public function findActiveByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('subscriber')
            ->andWhere('subscriber.workspace = :workspace')
            ->andWhere('subscriber.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->orderBy('subscriber.lastName', 'ASC')
            ->addOrderBy('subscriber.firstName', 'ASC')
            ->addOrderBy('subscriber.secondName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Subscriber>
     */
    public function findActiveByWorkspaceForAdminList(
        Workspace $workspace,
        string $search = '',
        string $portalFilter = self::PORTAL_FILTER_ALL,
        string $accountFilter = self::ACCOUNT_FILTER_ALL,
        string $sort = self::SORT_FULL_NAME,
        string $direction = self::SORT_ASC,
    ): array {
        return $this->createActiveByWorkspaceForAdminListQuery($workspace, $search, $portalFilter, $accountFilter, $sort, $direction)
            ->getQuery()
            ->getResult();
    }

    public function createActiveByWorkspaceForAdminListQuery(
        Workspace $workspace,
        string $search = '',
        string $portalFilter = self::PORTAL_FILTER_ALL,
        string $accountFilter = self::ACCOUNT_FILTER_ALL,
        string $sort = self::SORT_FULL_NAME,
        string $direction = self::SORT_ASC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('subscriber')
            ->andWhere('subscriber.workspace = :workspace')
            ->andWhere('subscriber.deletedAt IS NULL')
            ->setParameter('workspace', $workspace);

        $search = trim($search);

        if ($search !== '') {
            $queryBuilder
                ->andWhere(<<<'DQL'
                    SEARCH_COLLATE(subscriber.lastName) LIKE :search
                    OR SEARCH_COLLATE(subscriber.firstName) LIKE :search
                    OR SEARCH_COLLATE(subscriber.secondName) LIKE :search
                    OR SEARCH_COLLATE(subscriber.contactEmail) LIKE :search
                    OR SEARCH_COLLATE(subscriber.contactPhone) LIKE :search
                    OR SEARCH_COLLATE(CONCAT(CONCAT(CONCAT(subscriber.lastName, ' '), subscriber.firstName), CONCAT(' ', COALESCE(subscriber.secondName, '')))) LIKE :search
                    DQL)
                ->setParameter('search', '%'.$search.'%');
        }

        if ($portalFilter === self::PORTAL_FILTER_LINKED) {
            $queryBuilder->andWhere('subscriber.user IS NOT NULL');
        } elseif ($portalFilter === self::PORTAL_FILTER_UNLINKED) {
            $queryBuilder->andWhere('subscriber.user IS NULL');
        }

        if ($accountFilter === self::ACCOUNT_FILTER_WITH_ACCOUNTS) {
            $queryBuilder->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s access WHERE access.subscriber = subscriber AND access.workspace = :workspace AND access.revokedAt IS NULL)',
                SubscriberAccountAccess::class,
            ));
        } elseif ($accountFilter === self::ACCOUNT_FILTER_WITHOUT_ACCOUNTS) {
            $queryBuilder->andWhere(sprintf(
                'NOT EXISTS (SELECT 1 FROM %s access WHERE access.subscriber = subscriber AND access.workspace = :workspace AND access.revokedAt IS NULL)',
                SubscriberAccountAccess::class,
            ));
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_FULL_NAME,
            self::SORT_EMAIL,
            self::SORT_PHONE,
            self::SORT_UPDATED_AT,
        ], true) ? $sort : self::SORT_FULL_NAME;
    }

    public function normalizeSortDirection(string $direction): string
    {
        return strtolower($direction) === self::SORT_DESC ? self::SORT_DESC : self::SORT_ASC;
    }

    private function applyAdminListSort(QueryBuilder $queryBuilder, string $sort, string $direction): void
    {
        $dqlDirection = $direction === self::SORT_DESC ? 'DESC' : 'ASC';

        match ($sort) {
            self::SORT_EMAIL => $queryBuilder
                ->orderBy('subscriber.contactEmail', $dqlDirection)
                ->addOrderBy('subscriber.lastName', 'ASC')
                ->addOrderBy('subscriber.firstName', 'ASC')
                ->addOrderBy('subscriber.secondName', 'ASC'),
            self::SORT_PHONE => $queryBuilder
                ->orderBy('subscriber.contactPhone', $dqlDirection)
                ->addOrderBy('subscriber.lastName', 'ASC')
                ->addOrderBy('subscriber.firstName', 'ASC')
                ->addOrderBy('subscriber.secondName', 'ASC'),
            self::SORT_UPDATED_AT => $queryBuilder
                ->orderBy('subscriber.updatedAt', $dqlDirection)
                ->addOrderBy('subscriber.lastName', 'ASC')
                ->addOrderBy('subscriber.firstName', 'ASC')
                ->addOrderBy('subscriber.secondName', 'ASC'),
            default => $queryBuilder
                ->orderBy('subscriber.lastName', $dqlDirection)
                ->addOrderBy('subscriber.firstName', $dqlDirection)
                ->addOrderBy('subscriber.secondName', $dqlDirection),
        };
    }

    public function normalizePortalFilter(string $portalFilter): string
    {
        return in_array($portalFilter, [
            self::PORTAL_FILTER_ALL,
            self::PORTAL_FILTER_LINKED,
            self::PORTAL_FILTER_UNLINKED,
        ], true) ? $portalFilter : self::PORTAL_FILTER_ALL;
    }

    public function normalizeAccountFilter(string $accountFilter): string
    {
        return in_array($accountFilter, [
            self::ACCOUNT_FILTER_ALL,
            self::ACCOUNT_FILTER_WITH_ACCOUNTS,
            self::ACCOUNT_FILTER_WITHOUT_ACCOUNTS,
        ], true) ? $accountFilter : self::ACCOUNT_FILTER_ALL;
    }

    public function findOneActiveByWorkspaceAndUuid(Workspace $workspace, Uuid $uuid): ?Subscriber
    {
        return $this->createQueryBuilder('subscriber')
            ->andWhere('subscriber.workspace = :workspace')
            ->andWhere('subscriber.uuid = :uuid')
            ->andWhere('subscriber.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByWorkspaceAndUser(Workspace $workspace, User $user): ?Subscriber
    {
        return $this->createQueryBuilder('subscriber')
            ->andWhere('subscriber.workspace = :workspace')
            ->andWhere('subscriber.user = :user')
            ->andWhere('subscriber.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('user', $user)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByWorkspaceAndContactEmail(Workspace $workspace, string $contactEmail): ?Subscriber
    {
        return $this->createQueryBuilder('subscriber')
            ->andWhere('subscriber.workspace = :workspace')
            ->andWhere('LOWER(subscriber.contactEmail) = :contactEmail')
            ->andWhere('subscriber.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('contactEmail', mb_strtolower(trim($contactEmail)))
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneActiveByWorkspaceAndName(
        Workspace $workspace,
        string $lastName,
        string $firstName,
        ?string $secondName,
    ): ?Subscriber {
        $queryBuilder = $this->createQueryBuilder('subscriber')
            ->andWhere('subscriber.workspace = :workspace')
            ->andWhere('subscriber.lastName = :lastName')
            ->andWhere('subscriber.firstName = :firstName')
            ->andWhere('subscriber.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('lastName', trim($lastName))
            ->setParameter('firstName', trim($firstName))
            ->setMaxResults(1);

        $secondName = $secondName === null ? null : trim($secondName);

        if ($secondName === null || $secondName === '') {
            $queryBuilder->andWhere('subscriber.secondName IS NULL');
        } else {
            $queryBuilder
                ->andWhere('subscriber.secondName = :secondName')
                ->setParameter('secondName', $secondName);
        }

        return $queryBuilder
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<Subscriber>
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('subscriber')
            ->addSelect('workspace')
            ->innerJoin('subscriber.workspace', 'workspace')
            ->andWhere('subscriber.user = :user')
            ->andWhere('subscriber.deletedAt IS NULL')
            ->setParameter('user', $user)
            ->orderBy('workspace.code', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Subscriber>
     */
    public function findUnlinkedActiveByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('subscriber')
            ->andWhere('subscriber.workspace = :workspace')
            ->andWhere('subscriber.deletedAt IS NULL')
            ->andWhere('subscriber.user IS NULL')
            ->setParameter('workspace', $workspace)
            ->orderBy('subscriber.lastName', 'ASC')
            ->addOrderBy('subscriber.firstName', 'ASC')
            ->addOrderBy('subscriber.secondName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<User> $users
     *
     * @return array<string, Subscriber>
     */
    public function findActiveByWorkspaceAndUsers(Workspace $workspace, array $users): array
    {
        if ($users === []) {
            return [];
        }

        $subscribers = $this->createQueryBuilder('subscriber')
            ->addSelect('user')
            ->innerJoin('subscriber.user', 'user')
            ->andWhere('subscriber.workspace = :workspace')
            ->andWhere('subscriber.user IN (:users)')
            ->andWhere('subscriber.deletedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('users', $users)
            ->getQuery()
            ->getResult();

        $byUser = [];

        foreach ($subscribers as $subscriber) {
            if (!$subscriber instanceof Subscriber || !$subscriber->getUser() instanceof User) {
                continue;
            }

            $byUser[$subscriber->getUser()->getUuid()->toRfc4122()] = $subscriber;
        }

        return $byUser;
    }
}
