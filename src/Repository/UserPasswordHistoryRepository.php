<?php

namespace App\Repository;

use App\Entity\User;
use App\Entity\UserPasswordHistory;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserPasswordHistory>
 */
class UserPasswordHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserPasswordHistory::class);
    }

    public function append(User $user, string $passwordHash, DateTimeImmutable $changedAt, ?User $changedBy = null): void
    {
        $this->getEntityManager()->getConnection()->insert(
            'user_password_history',
            [
                'user_uuid' => $user->getUuid()->toRfc4122(),
                'password_hash' => $passwordHash,
                'changed_at' => $changedAt,
                'changed_by' => $changedBy?->getUuid()->toRfc4122(),
            ],
            [
                'user_uuid' => Types::GUID,
                'password_hash' => Types::STRING,
                'changed_at' => Types::DATETIMETZ_IMMUTABLE,
                'changed_by' => Types::GUID,
            ],
        );
    }
}
