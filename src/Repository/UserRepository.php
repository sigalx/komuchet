<?php

namespace App\Repository;

use App\Entity\Subscriber;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\UserPasswordCredential;
use App\Entity\Workspace;
use DateTimeImmutable;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public const SORT_EMAIL = 'email';
    public const SORT_STATUS = 'status';
    public const SORT_ADMIN = 'admin';
    public const SORT_CREATED_AT = 'created_at';

    public const SORT_ASC = 'asc';
    public const SORT_DESC = 'desc';

    public function __construct(
        ManagerRegistry $registry,
        private readonly UserPasswordHistoryRepository $passwordHistoryRepository,
    ) {
        parent::__construct($registry, User::class);
    }

    /**
     * @return list<User>
     */
    public function findForAdminList(
        Workspace $workspace,
        ?string $email = null,
        ?bool $admin = null,
        ?string $status = null,
        ?bool $linkedToSubscriber = null,
        string $sort = self::SORT_CREATED_AT,
        string $direction = self::SORT_DESC,
    ): array {
        return $this->createForAdminListQuery($workspace, $email, $admin, $status, $linkedToSubscriber, $sort, $direction)
            ->getQuery()
            ->getResult();
    }

    public function createForAdminListQuery(
        Workspace $workspace,
        ?string $email = null,
        ?bool $admin = null,
        ?string $status = null,
        ?bool $linkedToSubscriber = null,
        string $sort = self::SORT_CREATED_AT,
        string $direction = self::SORT_DESC,
    ): QueryBuilder {
        $queryBuilder = $this->createQueryBuilder('user')
            ->distinct()
            ->addSelect('emailIdentity', 'passwordCredential')
            ->leftJoin('user.emailIdentities', 'emailIdentity')
            ->leftJoin('user.passwordCredential', 'passwordCredential')
            ->leftJoin(Subscriber::class, 'subscriber', 'WITH', 'subscriber.user = user AND subscriber.workspace = :workspace AND subscriber.deletedAt IS NULL')
            ->setParameter('workspace', $workspace);

        $email = $email === null ? null : trim($email);

        if ($email !== null && $email !== '') {
            $queryBuilder
                ->andWhere('emailIdentity.emailNormalized LIKE :email')
                ->andWhere('emailIdentity.deletedAt IS NULL')
                ->setParameter('email', '%'.strtolower($email).'%');
        }

        if ($admin === true) {
            $queryBuilder
                ->andWhere('user.adminGrantedAt IS NOT NULL')
                ->andWhere('user.adminRevokedAt IS NULL');
        } elseif ($admin === false) {
            $queryBuilder->andWhere('user.adminGrantedAt IS NULL OR user.adminRevokedAt IS NOT NULL');
        }

        match ($status) {
            'pending' => $queryBuilder
                ->andWhere('user.approvedAt IS NULL')
                ->andWhere('user.deletedAt IS NULL'),
            'active' => $queryBuilder
                ->andWhere('user.approvedAt IS NOT NULL')
                ->andWhere('user.blockedAt IS NULL')
                ->andWhere('user.deletedAt IS NULL'),
            'blocked' => $queryBuilder
                ->andWhere('user.blockedAt IS NOT NULL')
                ->andWhere('user.deletedAt IS NULL'),
            'deleted' => $queryBuilder
                ->andWhere('user.deletedAt IS NOT NULL'),
            default => $queryBuilder
                ->andWhere('user.deletedAt IS NULL'),
        };

        if ($linkedToSubscriber === true) {
            $queryBuilder->andWhere('subscriber.uuid IS NOT NULL');
        } elseif ($linkedToSubscriber === false) {
            $queryBuilder->andWhere('subscriber.uuid IS NULL');
        }

        $this->applyAdminListSort($queryBuilder, $this->normalizeSort($sort), $this->normalizeSortDirection($direction));

        return $queryBuilder;
    }

    public function normalizeSort(string $sort): string
    {
        return in_array($sort, [
            self::SORT_EMAIL,
            self::SORT_STATUS,
            self::SORT_ADMIN,
            self::SORT_CREATED_AT,
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
            self::SORT_EMAIL => $queryBuilder
                ->leftJoin(
                    UserEmailIdentity::class,
                    'activeEmailIdentity',
                    Join::WITH,
                    'activeEmailIdentity.user = user AND activeEmailIdentity.deletedAt IS NULL',
                )
                ->addSelect('activeEmailIdentity.emailNormalized AS HIDDEN active_email_sort')
                ->orderBy('active_email_sort', $dqlDirection)
                ->addOrderBy('user.createdAt', 'DESC'),
            self::SORT_STATUS => $queryBuilder
                ->addSelect(<<<'DQL'
                    CASE
                        WHEN user.deletedAt IS NOT NULL THEN 4
                        WHEN user.blockedAt IS NOT NULL THEN 3
                        WHEN user.approvedAt IS NULL THEN 1
                        ELSE 2
                    END AS HIDDEN status_order
                    DQL)
                ->orderBy('status_order', $dqlDirection)
                ->addOrderBy('user.createdAt', 'DESC'),
            self::SORT_ADMIN => $queryBuilder
                ->addSelect(<<<'DQL'
                    CASE
                        WHEN user.adminGrantedAt IS NOT NULL AND user.adminRevokedAt IS NULL THEN 1
                        ELSE 0
                    END AS HIDDEN admin_order
                    DQL)
                ->orderBy('admin_order', $dqlDirection)
                ->addOrderBy('user.createdAt', 'DESC'),
            default => $queryBuilder
                ->orderBy('user.createdAt', $dqlDirection)
                ->addOrderBy('user.uuid', $dqlDirection),
        };
    }

    public function findOneForAdminShow(Uuid $uuid): ?User
    {
        return $this->createQueryBuilder('user')
            ->addSelect('emailIdentity', 'passwordCredential')
            ->leftJoin('user.emailIdentities', 'emailIdentity')
            ->leftJoin('user.passwordCredential', 'passwordCredential')
            ->andWhere('user.uuid = :uuid')
            ->setParameter('uuid', $uuid)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function isLastLoginAllowedAdmin(User $user): bool
    {
        if (!$user->isLoginAllowed() || !$user->isAdmin()) {
            return false;
        }

        return $this->countLoginAllowedAdmins() <= 1;
    }

    public function countLoginAllowedAdmins(): int
    {
        return (int) $this->createQueryBuilder('user')
            ->select('COUNT(DISTINCT user.uuid)')
            ->andWhere('user.approvedAt IS NOT NULL')
            ->andWhere('user.blockedAt IS NULL')
            ->andWhere('user.deletedAt IS NULL')
            ->andWhere('user.adminGrantedAt IS NOT NULL')
            ->andWhere('user.adminRevokedAt IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<User>
     */
    public function findNonDeletedWithoutActiveSubscriberByWorkspace(Workspace $workspace): array
    {
        return $this->createQueryBuilder('user')
            ->distinct()
            ->addSelect('emailIdentity')
            ->leftJoin('user.emailIdentities', 'emailIdentity', 'WITH', 'emailIdentity.deletedAt IS NULL')
            ->leftJoin(Subscriber::class, 'subscriber', 'WITH', 'subscriber.user = user AND subscriber.workspace = :workspace AND subscriber.deletedAt IS NULL')
            ->andWhere('user.deletedAt IS NULL')
            ->andWhere('subscriber.uuid IS NULL')
            ->setParameter('workspace', $workspace)
            ->orderBy('user.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Used to upgrade (rehash) the user's password automatically over time.
     */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $credential = $user->getPasswordCredential();

        $changedAt = new DateTimeImmutable();
        $entityManager = $this->getEntityManager();
        $connection = $entityManager->getConnection();

        $connection->beginTransaction();

        try {
            if ($credential === null) {
                $credential = new UserPasswordCredential($user, $newHashedPassword, $changedAt);
                $user->setPasswordCredential($credential);
                $entityManager->persist($credential);
            } else {
                $credential->setPasswordHash($newHashedPassword, $changedAt);
            }

            $entityManager->flush();
            $this->passwordHistoryRepository->append($user, $newHashedPassword, $changedAt);

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }
}
