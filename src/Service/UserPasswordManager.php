<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserPasswordCredential;
use App\Repository\UserPasswordHistoryRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserPasswordManager
{
    public const MIN_PASSWORD_LENGTH = 12;
    public const TEMPORARY_PASSWORD_EXPIRES_AT = '@0';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserPasswordHistoryRepository $passwordHistoryRepository,
    ) {
    }

    public function setPassword(
        User $user,
        string $plainPassword,
        ?User $changedBy = null,
        ?DateTimeImmutable $expiresAt = null,
    ): void {
        $changedAt = new DateTimeImmutable();
        $passwordHash = $this->passwordHasher->hashPassword($user, $plainPassword);
        $credential = $user->getPasswordCredential();

        if (!$credential instanceof UserPasswordCredential) {
            $credential = new UserPasswordCredential($user, $passwordHash, $changedAt);
            $user->setPasswordCredential($credential);
            $this->entityManager->persist($credential);
        } else {
            $credential->setPasswordHash($passwordHash, $changedAt);
        }

        $credential->setExpiresAt($expiresAt);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $this->entityManager->flush();
            $this->passwordHistoryRepository->append($user, $passwordHash, $changedAt, $changedBy);
            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }
    }

    public function generateTemporaryPassword(): string
    {
        return bin2hex(random_bytes(9));
    }
}
