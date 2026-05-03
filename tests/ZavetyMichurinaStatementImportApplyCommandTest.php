<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountElectricityTariffProfileAssignment;
use App\Entity\Accrual;
use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleAllScope;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\Payment;
use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\ZavetyMichurinaStatementImportBatch;
use App\Entity\ZavetyMichurinaStatementImportFile;
use App\Enum\AccrualType;
use App\Enum\ZavetyMichurinaStatementImportFileStatus;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ZavetyMichurinaStatementImportApplyCommandTest extends FunctionalWebTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        $this->resetDatabase();
    }

    public function testCommandAppliesParsedFilesFromBatch(): void
    {
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $batch = new ZavetyMichurinaStatementImportBatch($workspace, 'CLI apply test');
        $fileOne = $this->createParsedImportFile($batch, 'statement-9-123.pdf', 'a', '9-123', 'Иванов Иван Иванович', '47371730');
        $fileTwo = $this->createParsedImportFile($batch, 'statement-9-124.pdf', 'b', '9-124', 'Петров Петр Петрович', '47371731');

        $this->entityManager()->persist($batch);
        $this->entityManager()->persist($fileOne);
        $this->entityManager()->persist($fileTwo);
        $this->entityManager()->flush();

        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([
            '--workspace' => 'zm',
            'batch' => $batch->getUuid()->toRfc4122(),
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Batch applied', $commandTester->getDisplay());
        self::assertStringContainsString('statement-9-123.pdf', $commandTester->getDisplay());
        self::assertStringContainsString('statement-9-124.pdf', $commandTester->getDisplay());
        self::assertStringContainsString('applied', $commandTester->getDisplay());

        $this->entityManager()->clear();

        $appliedFileOne = $this->entityManager()->getRepository(ZavetyMichurinaStatementImportFile::class)->find($fileOne->getUuid());
        $appliedFileTwo = $this->entityManager()->getRepository(ZavetyMichurinaStatementImportFile::class)->find($fileTwo->getUuid());

        self::assertInstanceOf(ZavetyMichurinaStatementImportFile::class, $appliedFileOne);
        self::assertInstanceOf(ZavetyMichurinaStatementImportFile::class, $appliedFileTwo);
        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Applied, $appliedFileOne->getStatus());
        self::assertSame(ZavetyMichurinaStatementImportFileStatus::Applied, $appliedFileTwo->getStatus());
        self::assertSame(2, $this->entityManager()->getRepository(Account::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(Subscriber::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(SubscriberAccountAccess::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(AccountElectricityTariffProfileAssignment::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityTariffProfile::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityTariffZone::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityTariffPeriod::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityTariffRate::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityConsumptionBandRule::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(ElectricityConsumptionBandRuleAllScope::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityConsumptionBandRuleRange::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityMeter::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityMeterReading::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(Payment::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(Accrual::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(PaymentRequisiteProfile::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(PaymentRequisiteAssignment::class)->count([]));

        $paymentRequisiteAssignment = $this->entityManager()->getRepository(PaymentRequisiteAssignment::class)->findOneBy([]);
        self::assertInstanceOf(PaymentRequisiteAssignment::class, $paymentRequisiteAssignment);
        self::assertSame(AccrualType::Electricity, $paymentRequisiteAssignment->getAccrualType());

        $reading = $this->entityManager()->getRepository(ElectricityMeterReading::class)->findOneBy(['readingValue' => '1000.000']);
        self::assertInstanceOf(ElectricityMeterReading::class, $reading);
        self::assertSame('2026-04-01', $reading->getTakenOn()->format('Y-m-d'));
    }

    public function testCommandDoesNothingWhenBatchHasNoParsedFiles(): void
    {
        $workspace = $this->createWorkspace('zm', 'Заветы Мичурина');
        $batch = new ZavetyMichurinaStatementImportBatch($workspace, 'Empty batch');
        $this->entityManager()->persist($batch);
        $this->entityManager()->flush();

        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([
            '--workspace' => 'zm',
            'batch' => $batch->getUuid()->toRfc4122(),
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('No parsed files to apply', $commandTester->getDisplay());
    }

    public function testCommandRejectsUnknownWorkspace(): void
    {
        $commandTester = $this->createCommandTester();
        $exitCode = $commandTester->execute([
            '--workspace' => 'missing',
            'batch' => '018f70f3-624e-7b65-ae3f-188e7b9ad227',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Workspace "missing" was not found.', $commandTester->getDisplay());
    }

    private function createParsedImportFile(
        ZavetyMichurinaStatementImportBatch $batch,
        string $filename,
        string $hashCharacter,
        string $accountNumber,
        string $fullName,
        string $serialNumber,
    ): ZavetyMichurinaStatementImportFile {
        $file = new ZavetyMichurinaStatementImportFile(
            batch: $batch,
            originalFilename: $filename,
            sourceSha256: str_repeat($hashCharacter, 64),
            fileSizeBytes: 123456,
        );
        $file->markParsed([
            'source' => [
                'format' => 'zavety_michurina_electricity_statement',
                'mode' => 'dry_run',
            ],
            'account' => [
                'number' => $accountNumber,
            ],
            'subscriber' => [
                'full_name' => $fullName,
            ],
            'electricity_meter' => [
                'installed_on' => '2026-03-01',
                'serial_number' => $serialNumber,
                'initial_reading_kwh' => '0',
            ],
            'rows' => [
                [
                    'year' => 2026,
                    'month' => 3,
                    'period_start' => '2026-03-01',
                    'reading_value_kwh' => '1000',
                    'consumption_kwh' => '1000',
                    'social_norm_kwh' => '400',
                    'social_norm_rate' => '5.56',
                    'above_norm_kwh' => '600',
                    'above_norm_rate' => '8.99',
                    'accrued_amount' => '7618.00',
                    'paid_on' => '2026-03-13',
                    'paid_amount' => '7618.00',
                ],
            ],
            'totals' => [
                'total_accrued' => '7618.00',
                'total_paid' => '7618.00',
                'balance' => '0',
            ],
            'payment_requisites' => [
                'recipient_name' => 'Садоводческое Некоммерческое Товарищество "Заветы Мичурина"',
                'recipient_inn' => '5262083483',
                'recipient_kpp' => '526201001',
                'bank_account' => '40703810842050000900',
                'bank_bik' => '042202603',
                'bank_name' => 'Волго-Вятский банк ПАО Сбербанк',
                'payer_name' => $fullName,
                'payment_purpose' => sprintf('Участок %s взносы свет', $accountNumber),
            ],
            'warnings' => [],
        ]);

        return $file;
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application(static::getContainer()->get('kernel'));

        return new CommandTester($application->find('app:zm:apply-statement-import-batch'));
    }
}
