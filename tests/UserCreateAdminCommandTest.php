<?php

namespace App\Tests;

use App\Entity\User;
use App\Entity\UserEmailIdentity;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UserCreateAdminCommandTest extends FunctionalWebTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    public function testCreateAdminFailsOnExistingEmailByDefault(): void
    {
        $this->createAdminUser('existing-admin@example.test');

        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([
            'email' => 'existing-admin@example.test',
            '--password' => 'new-password-123',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('already bound to user', $commandTester->getDisplay());
        self::assertSame(1, $this->entityManager()->getRepository(User::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(UserEmailIdentity::class)->count([]));
    }

    public function testCreateAdminCanSkipExistingEmail(): void
    {
        $this->createAdminUser('existing-admin@example.test');

        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([
            'email' => 'existing-admin@example.test',
            '--password' => 'new-password-123',
            '--if-exists' => 'skip',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('skipped', $commandTester->getDisplay());
        self::assertSame(1, $this->entityManager()->getRepository(User::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(UserEmailIdentity::class)->count([]));
    }

    public function testCreateAdminRejectsInvalidIfExistsMode(): void
    {
        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([
            'email' => 'new-admin@example.test',
            '--password' => 'new-password-123',
            '--if-exists' => 'replace',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Invalid --if-exists value "replace"', $commandTester->getDisplay());
        self::assertSame(0, $this->entityManager()->getRepository(User::class)->count([]));
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application(static::getContainer()->get('kernel'));

        return new CommandTester($application->find('app:user:create-admin'));
    }
}
