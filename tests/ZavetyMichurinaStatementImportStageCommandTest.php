<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\Subscriber;
use App\Entity\Workspace;
use App\Entity\ZavetyMichurinaStatementImportBatch;
use App\Entity\ZavetyMichurinaStatementImportFile;
use App\Enum\ZavetyMichurinaStatementImportFileStatus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ZavetyMichurinaStatementImportStageCommandTest extends FunctionalWebTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    public function testCommandStagesParsedStatementTextWithoutCreatingDomainEntities(): void
    {
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $fixturePath = __DIR__.'/Fixtures/zavety_michurina/electricity-statement-layout.txt';
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            '--workspace' => 'zm',
            '--name' => 'Исторические PDF',
            '--from-text' => true,
            'files' => [$fixturePath],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Import batch staged', $commandTester->getDisplay());
        self::assertStringContainsString('parsed', $commandTester->getDisplay());
        self::assertStringContainsString('9-123', $commandTester->getDisplay());

        $batch = $this->entityManager()
            ->getRepository(ZavetyMichurinaStatementImportBatch::class)
            ->findOneBy(['workspace' => $workspace]);

        self::assertInstanceOf(ZavetyMichurinaStatementImportBatch::class, $batch);
        self::assertSame('Исторические PDF', $batch->getName());

        $importFile = $this->entityManager()
            ->getRepository(ZavetyMichurinaStatementImportFile::class)
            ->findOneBy(['batch' => $batch]);

        self::assertInstanceOf(ZavetyMichurinaStatementImportFile::class, $importFile);
        self::assertSame($workspace->getUuid()->toRfc4122(), $importFile->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Parsed, $importFile->getStatus());
        self::assertSame('9-123', $importFile->getDetectedAccountNumber());
        self::assertSame('Иванов Иван Иванович', $importFile->getDetectedSubscriberFullName());
        self::assertSame(hash_file('sha256', $fixturePath), $importFile->getSourceSha256());
        self::assertSame(filesize($fixturePath), $importFile->getFileSizeBytes());
        self::assertNotNull($importFile->getParsedAt());
        self::assertNull($importFile->getParseError());

        $parsedResult = $importFile->getParsedResult();
        self::assertIsArray($parsedResult);
        self::assertSame('dry_run', $parsedResult['source']['mode']);
        self::assertSame('15232.53', $parsedResult['totals']['balance']);
        self::assertCount(6, $parsedResult['rows']);

        self::assertSame(0, $this->entityManager()->getRepository(Account::class)->count([]));
        self::assertSame(0, $this->entityManager()->getRepository(Subscriber::class)->count([]));
    }

    public function testCommandStagesStatementTextsFromDirectory(): void
    {
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $fixturePath = __DIR__.'/Fixtures/zavety_michurina/electricity-statement-layout.txt';
        $directory = sys_get_temp_dir().'/zm-stage-command-'.bin2hex(random_bytes(8));

        mkdir($directory);
        copy($fixturePath, $directory.'/statement-a.txt');
        file_put_contents($directory.'/statement-b.txt', ((string) file_get_contents($fixturePath))."\n");
        file_put_contents($directory.'/ignored.csv', 'ignored');

        try {
            $commandTester = $this->createCommandTester();
            $exitCode = $commandTester->execute([
                '--workspace' => 'zm',
                '--name' => 'Directory import',
                '--from-text' => true,
                '--pattern' => '*.txt',
                'files' => [$directory],
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('statement-a.txt', $commandTester->getDisplay());
            self::assertStringContainsString('statement-b.txt', $commandTester->getDisplay());
            self::assertStringNotContainsString('ignored.csv', $commandTester->getDisplay());

            $batch = $this->entityManager()
                ->getRepository(ZavetyMichurinaStatementImportBatch::class)
                ->findOneBy(['workspace' => $workspace, 'name' => 'Directory import']);

            self::assertInstanceOf(ZavetyMichurinaStatementImportBatch::class, $batch);
            self::assertSame(2, $this->entityManager()->getRepository(ZavetyMichurinaStatementImportFile::class)->count(['batch' => $batch]));
            self::assertSame(0, $this->entityManager()->getRepository(Account::class)->count([]));
            self::assertSame(0, $this->entityManager()->getRepository(Subscriber::class)->count([]));
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testCommandRejectsUnknownWorkspace(): void
    {
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            '--workspace' => 'missing',
            '--from-text' => true,
            'files' => [__DIR__.'/Fixtures/zavety_michurina/electricity-statement-layout.txt'],
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Workspace "missing" was not found.', $commandTester->getDisplay());
        self::assertSame(0, $this->entityManager()->getRepository(ZavetyMichurinaStatementImportBatch::class)->count([]));
        self::assertSame(0, $this->entityManager()->getRepository(ZavetyMichurinaStatementImportFile::class)->count([]));
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application(static::getContainer()->get('kernel'));

        return new CommandTester($application->find('app:zm:stage-electricity-statements'));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof \SplFileInfo) {
                continue;
            }

            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getPathname());
            } else {
                unlink($fileInfo->getPathname());
            }
        }

        rmdir($directory);
    }
}
