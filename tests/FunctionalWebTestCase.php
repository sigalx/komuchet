<?php

namespace App\Tests;

use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\UserPasswordCredential;
use App\Entity\Workspace;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class FunctionalWebTestCase extends WebTestCase
{
    protected function resetDatabase(): void
    {
        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();
        $tables = $connection->fetchFirstColumn(<<<'SQL'
            SELECT quote_ident(schemaname) || '.' || quote_ident(tablename)
            FROM pg_tables
            WHERE schemaname = 'public'
              AND tablename <> 'doctrine_migration_versions'
            SQL);

        if ($tables !== []) {
            $connection->executeStatement(sprintf('TRUNCATE TABLE %s RESTART IDENTITY CASCADE', implode(', ', $tables)));
        }

        $entityManager->clear();
    }

    protected function createAdminUser(string $email = 'admin@example.test', ?DateTimeImmutable $passwordExpiresAt = null): User
    {
        $entityManager = $this->entityManager();
        $user = new User();
        $user->approve();
        $user->grantAdmin();

        $emailIdentity = new UserEmailIdentity($user, $email);
        $emailIdentity->markVerified();
        $user->addEmailIdentity($emailIdentity);

        $passwordHash = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, 'test-password-123');

        $passwordCredential = new UserPasswordCredential($user, $passwordHash);
        $passwordCredential->setExpiresAt($passwordExpiresAt);
        $user->setPasswordCredential($passwordCredential);

        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    protected function createWorkspace(string $code = 'main', string $name = 'Основное хозяйство'): Workspace
    {
        $workspace = (new Workspace())
            ->setCode($code)
            ->setName($name);

        $this->entityManager()->persist($workspace);
        $this->entityManager()->flush();

        return $workspace;
    }

    protected function entityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
