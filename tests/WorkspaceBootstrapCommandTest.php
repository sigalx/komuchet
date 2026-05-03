<?php

namespace App\Tests;

use App\Entity\BillingSettings;
use App\Entity\Workspace;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class WorkspaceBootstrapCommandTest extends FunctionalWebTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    public function testCreateWorkspaceAndBillingSettings(): void
    {
        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([
            'code' => 'pilot',
            'name' => 'Пилотное хозяйство',
            '--description' => 'Первичный pilot bootstrap',
            '--association-name' => 'ТСН Пилот',
            '--timezone' => 'Europe/Moscow',
            '--invoice-generation-day' => '7',
            '--reading-freshness-window-days' => '20',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Создано хозяйство', $commandTester->getDisplay());

        $workspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->findOneBy(['code' => 'pilot']);

        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame('Пилотное хозяйство', $workspace->getName());
        self::assertSame('Первичный pilot bootstrap', $workspace->getDescription());
        self::assertSame('Europe/Moscow', $workspace->getTimezone());

        $settings = $this->entityManager()
            ->getRepository(BillingSettings::class)
            ->findOneBy(['workspace' => $workspace]);

        self::assertInstanceOf(BillingSettings::class, $settings);
        self::assertSame('ТСН Пилот', $settings->getAssociationName());
        self::assertSame(7, $settings->getInvoiceGenerationDay());
        self::assertSame(20, $settings->getReadingFreshnessWindowDays());
    }

    public function testUpdateExistingWorkspaceAndBillingSettings(): void
    {
        $commandTester = $this->createCommandTester();
        $firstExitCode = $commandTester->execute([
            'code' => 'pilot',
            'name' => 'Пилотное хозяйство',
        ]);

        self::assertSame(Command::SUCCESS, $firstExitCode);

        $secondCommandTester = $this->createCommandTester();
        $secondExitCode = $secondCommandTester->execute([
            'code' => 'pilot',
            'name' => 'Пилотное хозяйство 2',
            '--description' => 'Обновлено',
            '--association-name' => 'ТСН Пилот 2',
            '--timezone' => 'Asia/Yekaterinburg',
            '--invoice-generation-day' => '10',
            '--reading-freshness-window-days' => '30',
        ]);

        self::assertSame(Command::SUCCESS, $secondExitCode);
        self::assertStringContainsString('Обновлено хозяйство', $secondCommandTester->getDisplay());
        self::assertSame(1, $this->entityManager()->getRepository(Workspace::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(BillingSettings::class)->count([]));

        $workspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->findOneBy(['code' => 'pilot']);

        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame('Пилотное хозяйство 2', $workspace->getName());
        self::assertSame('Обновлено', $workspace->getDescription());
        self::assertSame('Asia/Yekaterinburg', $workspace->getTimezone());

        $settings = $this->entityManager()
            ->getRepository(BillingSettings::class)
            ->findOneBy(['workspace' => $workspace]);

        self::assertInstanceOf(BillingSettings::class, $settings);
        self::assertSame('ТСН Пилот 2', $settings->getAssociationName());
        self::assertSame(10, $settings->getInvoiceGenerationDay());
        self::assertSame(30, $settings->getReadingFreshnessWindowDays());
    }

    public function testRejectsInvalidOptions(): void
    {
        $invalidCode = $this->createCommandTester();
        self::assertSame(Command::INVALID, $invalidCode->execute([
            'code' => 'Invalid Code',
            'name' => 'Пилотное хозяйство',
        ]));
        self::assertStringContainsString('Код хозяйства должен соответствовать', $invalidCode->getDisplay());

        $invalidTimezone = $this->createCommandTester();
        self::assertSame(Command::INVALID, $invalidTimezone->execute([
            'code' => 'pilot',
            'name' => 'Пилотное хозяйство',
            '--timezone' => 'Not/A_Zone',
        ]));
        self::assertStringContainsString('Invalid timezone', $invalidTimezone->getDisplay());

        $invalidInvoiceDay = $this->createCommandTester();
        self::assertSame(Command::INVALID, $invalidInvoiceDay->execute([
            'code' => 'pilot',
            'name' => 'Пилотное хозяйство',
            '--invoice-generation-day' => '31',
        ]));
        self::assertStringContainsString('Invoice generation day must be between 1 and 28.', $invalidInvoiceDay->getDisplay());

        self::assertSame(0, $this->entityManager()->getRepository(Workspace::class)->count([]));
        self::assertSame(0, $this->entityManager()->getRepository(BillingSettings::class)->count([]));
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application(static::getContainer()->get('kernel'));

        return new CommandTester($application->find('app:workspace:bootstrap'));
    }
}
