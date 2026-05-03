<?php

namespace App\Tests;

use App\Command\DemoSeedCommand;
use App\Entity\Account;
use App\Entity\AccountElectricityTariffProfileAssignment;
use App\Entity\AccountGroup;
use App\Entity\AccountGroupMember;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementDeliveryAttempt;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Accrual;
use App\Entity\BillingRun;
use App\Entity\BillingRunAccountIssue;
use App\Entity\BillingSettings;
use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleAllScope;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\Payment;
use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\UserEmailIdentity;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\AccrualType;
use App\Enum\BillingRunAccountIssueType;
use App\Enum\BillingRunKind;
use App\Enum\SubscriberAccountAccessRole;
use App\Enum\WorkspaceUserRoleCode;
use App\Repository\AccountElectricityTariffProfileAssignmentRepository;
use App\Repository\AccountGroupMemberRepository;
use App\Repository\AccountGroupRepository;
use App\Repository\AccountRepository;
use App\Repository\AccountStatementDeliveryRepository;
use App\Repository\AccountStatementSnapshotRepository;
use App\Repository\AccrualRepository;
use App\Repository\BillingRunAccountIssueRepository;
use App\Repository\BillingRunRepository;
use App\Repository\BillingSettingsRepository;
use App\Repository\ElectricityConsumptionBandRepository;
use App\Repository\ElectricityConsumptionBandRuleAllScopeRepository;
use App\Repository\ElectricityConsumptionBandRuleRangeRepository;
use App\Repository\ElectricityConsumptionBandRuleRepository;
use App\Repository\ElectricityMeterReadingRepository;
use App\Repository\ElectricityMeterRegisterRepository;
use App\Repository\ElectricityMeterRepository;
use App\Repository\ElectricityTariffPeriodRepository;
use App\Repository\ElectricityTariffProfileRepository;
use App\Repository\ElectricityTariffRateRepository;
use App\Repository\ElectricityTariffZoneRepository;
use App\Repository\PaymentRepository;
use App\Repository\PaymentRequisiteAssignmentRepository;
use App\Repository\PaymentRequisiteProfileRepository;
use App\Repository\SubscriberAccountAccessRepository;
use App\Repository\SubscriberRepository;
use App\Repository\UserEmailIdentityRepository;
use App\Repository\WorkspaceRepository;
use App\Repository\WorkspaceUserRoleAssignmentRepository;
use App\Service\AccountStatementDeliveryEnqueuer;
use App\Service\AccountStatementPaymentQrCodeGenerator;
use App\Service\AccountStatementSnapshotGenerator;
use App\Service\BillingRunIssueGenerator;
use App\Service\ElectricityBillingRunAccrualGenerator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class DemoSeedCommandTest extends FunctionalWebTestCase
{
    public function testCommandCreatesDemoWorkspaceAndBillingSettings(): void
    {
        static::createClient();
        $this->resetDatabase();
        $commandTester = $this->createCommandTester();

        $commandTester->execute([
            '--workspace-code' => 'demo',
            '--workspace-name' => 'Демо-хозяйство КомУчёт',
            '--size' => 'medium',
            '--as-of' => '2026-05-14',
            '--seed' => 'test-seed',
        ]);

        $commandTester->assertCommandIsSuccessful();
        $display = $commandTester->getDisplay();

        self::assertStringContainsString('Demo seed', $display);
        self::assertStringContainsString('demo', $display);
        self::assertStringContainsString('Демо-хозяйство КомУчёт', $display);
        self::assertStringContainsString('Хозяйство', $display);
        self::assertStringContainsString('created', $display);
        self::assertStringContainsString('Настройки расчетов', $display);
        self::assertStringContainsString('Платежные реквизиты', $display);
        self::assertStringContainsString('40-50', $display);
        self::assertStringContainsString('участок с долгом', $display);

        $workspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->findOneBy(['code' => 'demo']);

        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame('Демо-хозяйство КомУчёт', $workspace->getName());
        self::assertSame('Europe/Moscow', $workspace->getTimezone());

        $billingSettings = $this->entityManager()
            ->getRepository(BillingSettings::class)
            ->find($workspace);

        self::assertInstanceOf(BillingSettings::class, $billingSettings);
        self::assertSame('Демо-хозяйство КомУчёт', $billingSettings->getAssociationName());
        self::assertSame(5, $billingSettings->getInvoiceGenerationDay());
        self::assertSame(15, $billingSettings->getReadingFreshnessWindowDays());

        self::assertSame(2, $this->entityManager()->getRepository(AccountGroup::class)->count([]));
        self::assertNotNull($this->entityManager()->getRepository(AccountGroup::class)->findOneBy(['code' => 'summer']));
        self::assertNotNull($this->entityManager()->getRepository(AccountGroup::class)->findOneBy(['code' => 'year_round']));
        self::assertSame(40, $this->entityManager()->getRepository(Account::class)->count([]));
        self::assertSame(40, $this->entityManager()->getRepository(Subscriber::class)->count([]));
        self::assertSame(48, $this->entityManager()->getRepository(SubscriberAccountAccess::class)->count([]));
        self::assertSame(40, $this->entityManager()->getRepository(AccountGroupMember::class)->count([]));
        self::assertSame(40, $this->entityManager()->getRepository(ElectricityMeter::class)->count([]));
        self::assertSame(48, $this->entityManager()->getRepository(ElectricityMeterRegister::class)->count([]));
        self::assertSame(575, $this->entityManager()->getRepository(ElectricityMeterReading::class)->count([]));
        self::assertSame(574, $this->countActiveElectricityMeterReadings());
        self::assertSame(1, $this->countSupersededElectricityMeterReadings());
        self::assertSame(40, $this->entityManager()->getRepository(AccountElectricityTariffProfileAssignment::class)->count([]));
        self::assertSame(159, $this->entityManager()->getRepository(Accrual::class)->count([]));
        self::assertSame(30, $this->entityManager()->getRepository(Payment::class)->count([]));
        self::assertSame(3, $this->entityManager()->getRepository(BillingRun::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(BillingRunAccountIssue::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(PaymentRequisiteProfile::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(PaymentRequisiteAssignment::class)->count([]));
        self::assertSame(80, $this->entityManager()->getRepository(AccountStatementSnapshot::class)->count([]));
        self::assertSame(96, $this->entityManager()->getRepository(AccountStatementDelivery::class)->count([]));
        self::assertSame(96, $this->entityManager()->getRepository(AccountStatementDeliveryAttempt::class)->count([]));
        self::assertSame(
            ['queued' => 24, 'sent' => 24, 'failed' => 24, 'cancelled' => 24],
            $this->countDeliveryStatuses(),
        );

        self::assertSame(3, $this->entityManager()->getRepository(ElectricityTariffZone::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityTariffProfile::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityConsumptionBand::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityTariffPeriod::class)->count([]));
        self::assertSame(6, $this->entityManager()->getRepository(ElectricityTariffRate::class)->count([]));
        self::assertSame(24, $this->entityManager()->getRepository(ElectricityConsumptionBandRule::class)->count([]));
        self::assertSame(24, $this->entityManager()->getRepository(ElectricityConsumptionBandRuleAllScope::class)->count([]));
        self::assertSame(48, $this->entityManager()->getRepository(ElectricityConsumptionBandRuleRange::class)->count([]));

        $singleProfile = $this->entityManager()
            ->getRepository(ElectricityTariffProfile::class)
            ->findOneBy(['code' => 'single_rate']);
        $singleZone = $this->entityManager()
            ->getRepository(ElectricityTariffZone::class)
            ->findOneBy(['code' => 'single']);
        $socialBand = $this->entityManager()
            ->getRepository(ElectricityConsumptionBand::class)
            ->findOneBy(['code' => 'social_norm']);
        $aboveBand = $this->entityManager()
            ->getRepository(ElectricityConsumptionBand::class)
            ->findOneBy(['code' => 'above_social_norm']);
        $paymentRequisiteProfile = $this->entityManager()
            ->getRepository(PaymentRequisiteProfile::class)
            ->findOneBy(['code' => 'demo-default']);

        self::assertInstanceOf(ElectricityTariffProfile::class, $singleProfile);
        self::assertInstanceOf(ElectricityTariffZone::class, $singleZone);
        self::assertInstanceOf(ElectricityConsumptionBand::class, $socialBand);
        self::assertInstanceOf(ElectricityConsumptionBand::class, $aboveBand);
        self::assertInstanceOf(PaymentRequisiteProfile::class, $paymentRequisiteProfile);
        self::assertSame('НКО "Демо-хозяйство КомУчёт"', $paymentRequisiteProfile->getRecipientName());
        self::assertSame('40703810000000000001', $paymentRequisiteProfile->getBankAccount());

        $singlePeriod = $this->entityManager()
            ->getRepository(ElectricityTariffPeriod::class)
            ->findOneBy(['tariffProfile' => $singleProfile]);

        self::assertInstanceOf(ElectricityTariffPeriod::class, $singlePeriod);
        self::assertSame('2025-01-01', $singlePeriod->getValidFrom()->format('Y-m-d'));

        $socialRate = $this->entityManager()
            ->getRepository(ElectricityTariffRate::class)
            ->findOneBy([
                'tariffPeriod' => $singlePeriod,
                'tariffZone' => $singleZone,
                'consumptionBand' => $socialBand,
            ]);

        self::assertInstanceOf(ElectricityTariffRate::class, $socialRate);
        self::assertSame('5.500000', $socialRate->getRate());

        $januaryRule = $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRule::class)
            ->findOneBy(['tariffProfile' => $singleProfile, 'month' => 1]);
        $mayRule = $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRule::class)
            ->findOneBy(['tariffProfile' => $singleProfile, 'month' => 5]);

        self::assertInstanceOf(ElectricityConsumptionBandRule::class, $januaryRule);
        self::assertInstanceOf(ElectricityConsumptionBandRule::class, $mayRule);

        $januarySocialRange = $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRuleRange::class)
            ->findOneBy(['rule' => $januaryRule, 'consumptionBand' => $socialBand]);
        $maySocialRange = $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRuleRange::class)
            ->findOneBy(['rule' => $mayRule, 'consumptionBand' => $socialBand]);

        self::assertInstanceOf(ElectricityConsumptionBandRuleRange::class, $januarySocialRange);
        self::assertInstanceOf(ElectricityConsumptionBandRuleRange::class, $maySocialRange);
        self::assertSame('250.000', $januarySocialRange->getUpperBoundKwh());
        self::assertSame('150.000', $maySocialRange->getUpperBoundKwh());

        $accountOne = $this->entityManager()->getRepository(Account::class)->findOneBy(['number' => '9-001']);
        $accountThree = $this->entityManager()->getRepository(Account::class)->findOneBy(['number' => '9-003']);
        $accountFour = $this->entityManager()->getRepository(Account::class)->findOneBy(['number' => '9-004']);
        $accountFive = $this->entityManager()->getRepository(Account::class)->findOneBy(['number' => '9-005']);
        $accountThirtyThree = $this->entityManager()->getRepository(Account::class)->findOneBy(['number' => '9-033']);

        self::assertInstanceOf(Account::class, $accountOne);
        self::assertInstanceOf(Account::class, $accountThree);
        self::assertInstanceOf(Account::class, $accountFour);
        self::assertInstanceOf(Account::class, $accountFive);
        self::assertInstanceOf(Account::class, $accountThirtyThree);

        $meterOne = $this->entityManager()
            ->getRepository(ElectricityMeter::class)
            ->findOneActiveByWorkspaceAndAccount($workspace, $accountOne);
        $meterThree = $this->entityManager()
            ->getRepository(ElectricityMeter::class)
            ->findOneActiveByWorkspaceAndAccount($workspace, $accountThree);
        $meterFour = $this->entityManager()
            ->getRepository(ElectricityMeter::class)
            ->findOneActiveByWorkspaceAndAccount($workspace, $accountFour);
        $meterFive = $this->entityManager()
            ->getRepository(ElectricityMeter::class)
            ->findOneActiveByWorkspaceAndAccount($workspace, $accountFive);

        self::assertInstanceOf(ElectricityMeter::class, $meterOne);
        self::assertInstanceOf(ElectricityMeter::class, $meterThree);
        self::assertInstanceOf(ElectricityMeter::class, $meterFour);
        self::assertInstanceOf(ElectricityMeter::class, $meterFive);
        self::assertSame('DEMO-EL-0001', $meterOne->getSerialNumber());
        self::assertSame('Меркурий 201.8 DEMO', $meterOne->getModel());
        self::assertSame('DEMO-EL-0005', $meterFive->getSerialNumber());
        self::assertSame('Меркурий 200.02 DEMO', $meterFive->getModel());

        $meterOneRegisters = $this->entityManager()
            ->getRepository(ElectricityMeterRegister::class)
            ->findByMeter($workspace, $meterOne);
        $meterFiveRegisters = $this->entityManager()
            ->getRepository(ElectricityMeterRegister::class)
            ->findByMeter($workspace, $meterFive);

        self::assertSame(
            ['single'],
            array_map(
                static fn (ElectricityMeterRegister $register): string => $register->getTariffZone()?->getCode() ?? '',
                $meterOneRegisters,
            ),
        );
        self::assertSame(
            ['day', 'night'],
            array_map(
                static fn (ElectricityMeterRegister $register): string => $register->getTariffZone()?->getCode() ?? '',
                $meterFiveRegisters,
            ),
        );

        $accountOneAssignment = $this->entityManager()
            ->getRepository(AccountElectricityTariffProfileAssignment::class)
            ->findOneOpenEndedByAccount($workspace, $accountOne);
        $accountFiveAssignment = $this->entityManager()
            ->getRepository(AccountElectricityTariffProfileAssignment::class)
            ->findOneOpenEndedByAccount($workspace, $accountFive);

        self::assertInstanceOf(AccountElectricityTariffProfileAssignment::class, $accountOneAssignment);
        self::assertInstanceOf(AccountElectricityTariffProfileAssignment::class, $accountFiveAssignment);
        self::assertSame('single_rate', $accountOneAssignment->getTariffProfile()?->getCode());
        self::assertSame('two_rate', $accountFiveAssignment->getTariffProfile()?->getCode());

        $latestAccountOneReading = $this->entityManager()
            ->getRepository(ElectricityMeterReading::class)
            ->findLatestActiveBeforeOrOn($workspace, $meterOne, $singleZone, new DateTimeImmutable('2026-05-14'));
        $latestAccountThreeReading = $this->entityManager()
            ->getRepository(ElectricityMeterReading::class)
            ->findLatestActiveBeforeOrOn($workspace, $meterThree, $singleZone, new DateTimeImmutable('2026-05-14'));

        self::assertInstanceOf(ElectricityMeterReading::class, $latestAccountOneReading);
        self::assertInstanceOf(ElectricityMeterReading::class, $latestAccountThreeReading);
        self::assertSame('2026-05-04', $latestAccountOneReading->getTakenOn()->format('Y-m-d'));
        self::assertSame('2026-03-04', $latestAccountThreeReading->getTakenOn()->format('Y-m-d'));

        $correctedReading = $this->entityManager()
            ->getRepository(ElectricityMeterReading::class)
            ->findOneActiveByMeterZoneAndTakenOn($workspace, $meterFour, $singleZone, new DateTimeImmutable('2026-03-04'));

        self::assertInstanceOf(ElectricityMeterReading::class, $correctedReading);
        self::assertSame('admin', $correctedReading->getSource()->value);
        self::assertStringContainsString('исправленное', $correctedReading->getNotes() ?? '');

        $supersededReading = $this->entityManager()
            ->getRepository(ElectricityMeterReading::class)
            ->findOneSupersededByReplacement($workspace, $correctedReading);

        self::assertInstanceOf(ElectricityMeterReading::class, $supersededReading);
        self::assertFalse($supersededReading->isActive());
        self::assertSame($correctedReading->getUuid()->toRfc4122(), $supersededReading->getReplacingReading()?->getUuid()->toRfc4122());
        self::assertSame('Демо-исправление ошибочного показания.', $supersededReading->getReplacementReason());

        self::assertSame('2137.00', $this->findFinancialScenarioAccrual($workspace, $accountOne)->getAmount());
        self::assertSame('1637.00', $this->entityManager()->getRepository(Payment::class)->sumActiveAmountByAccount($workspace, $accountOne));
        self::assertSame('2274.00', $this->findFinancialScenarioAccrual($workspace, $accountTwo = $this->findAccount('9-002'))->getAmount());
        self::assertSame('2874.00', $this->entityManager()->getRepository(Payment::class)->sumActiveAmountByAccount($workspace, $accountTwo));
        self::assertSame('2411.00', $this->findFinancialScenarioAccrual($workspace, $accountThree)->getAmount());
        self::assertSame('0', $this->entityManager()->getRepository(Payment::class)->sumActiveAmountByAccount($workspace, $accountThree));
        self::assertSame('2548.00', $this->findFinancialScenarioAccrual($workspace, $accountFour)->getAmount());
        self::assertSame('2548.00', $this->entityManager()->getRepository(Payment::class)->sumActiveAmountByAccount($workspace, $accountFour));

        $accountOnePayment = $this->entityManager()
            ->getRepository(Payment::class)
            ->findOneByWorkspaceAndExternalReference($workspace, 'DEMO-PAYMENT-demo-9-001');

        self::assertInstanceOf(Payment::class, $accountOnePayment);
        self::assertSame('import', $accountOnePayment->getSource()->value);
        self::assertStringContainsString('сценарий debt', $accountOnePayment->getPurpose() ?? '');

        $postedFebruaryRun = $this->entityManager()
            ->getRepository(BillingRun::class)
            ->findOneActiveByKindAndPeriod(
                $workspace,
                BillingRunKind::Electricity,
                new DateTimeImmutable('2026-02-01'),
                new DateTimeImmutable('2026-03-01'),
            );
        $currentDraftRun = $this->entityManager()
            ->getRepository(BillingRun::class)
            ->findOneActiveByKindAndPeriod(
                $workspace,
                BillingRunKind::Electricity,
                new DateTimeImmutable('2026-04-01'),
                new DateTimeImmutable('2026-05-01'),
            );

        self::assertInstanceOf(BillingRun::class, $postedFebruaryRun);
        self::assertInstanceOf(BillingRun::class, $currentDraftRun);
        self::assertTrue($postedFebruaryRun->isPosted());
        self::assertTrue($currentDraftRun->isDraft());
        self::assertNotNull($currentDraftRun->getAccrualsGeneratedAt());
        self::assertCount(40, $this->entityManager()->getRepository(Accrual::class)->findByBillingRun($workspace, $postedFebruaryRun));
        self::assertCount(39, $this->entityManager()->getRepository(Accrual::class)->findByBillingRun($workspace, $currentDraftRun));

        $accountOneStatement = $this->entityManager()
            ->getRepository(AccountStatementSnapshot::class)
            ->findOneActiveByBillingRunAndAccount($workspace, $postedFebruaryRun, $accountOne);

        self::assertInstanceOf(AccountStatementSnapshot::class, $accountOneStatement);
        self::assertTrue($accountOneStatement->hasPaymentRequisites());
        self::assertSame($paymentRequisiteProfile->getUuid()->toRfc4122(), $accountOneStatement->getPaymentRequisiteProfile()?->getUuid()->toRfc4122());
        self::assertSame('НКО "Демо-хозяйство КомУчёт"', $accountOneStatement->getPaymentRecipientName());
        self::assertStringContainsString($accountOneStatement->getNumber(), $accountOneStatement->getPaymentPurpose() ?? '');

        $paymentQrCode = static::getContainer()
            ->get(AccountStatementPaymentQrCodeGenerator::class)
            ->generate($accountOneStatement);

        self::assertNotNull($paymentQrCode);
        self::assertStringStartsWith('ST00012|', $paymentQrCode->payload);
        self::assertStringContainsString('PersonalAcc=40703810000000000001', $paymentQrCode->payload);
        self::assertStringContainsString('Name=НКО "Демо-хозяйство КомУчёт"', $paymentQrCode->payload);
        self::assertStringStartsWith('data:image/png;base64,', $paymentQrCode->dataUri);

        $openIssues = $this->entityManager()
            ->getRepository(BillingRunAccountIssue::class)
            ->findOpenByBillingRun($workspace, $currentDraftRun);

        self::assertCount(1, $openIssues);
        self::assertSame(BillingRunAccountIssueType::StaleReading, $openIssues[0]->getIssueType());
        self::assertSame($accountThree->getUuid()->toRfc4122(), $openIssues[0]->getAccount()?->getUuid()->toRfc4122());

        $accountOneAccesses = $this->entityManager()
            ->getRepository(SubscriberAccountAccess::class)
            ->findBy(['account' => $accountOne]);

        self::assertCount(2, $accountOneAccesses);
        self::assertContains(
            SubscriberAccountAccessRole::Owner,
            array_map(static fn (SubscriberAccountAccess $access): SubscriberAccountAccessRole => $access->getAccessRole(), $accountOneAccesses),
        );
        self::assertContains(
            SubscriberAccountAccessRole::Representative,
            array_map(static fn (SubscriberAccountAccess $access): SubscriberAccountAccessRole => $access->getAccessRole(), $accountOneAccesses),
        );

        $ownerAccess = $this->entityManager()
            ->getRepository(SubscriberAccountAccess::class)
            ->findOneBy(['account' => $accountOne, 'accessRole' => SubscriberAccountAccessRole::Owner]);

        self::assertInstanceOf(SubscriberAccountAccess::class, $ownerAccess);

        $sameOwnerAccess = $this->entityManager()
            ->getRepository(SubscriberAccountAccess::class)
            ->findOneBy(['account' => $accountThirtyThree, 'subscriber' => $ownerAccess->getSubscriber()]);

        self::assertInstanceOf(SubscriberAccountAccess::class, $sameOwnerAccess);
        self::assertSame(SubscriberAccountAccessRole::Owner, $sameOwnerAccess->getAccessRole());
    }

    public function testCommandUpdatesExistingDemoWorkspaceIdempotently(): void
    {
        static::createClient();
        $this->resetDatabase();
        $commandTester = $this->createCommandTester();

        $commandTester->execute([
            '--workspace-code' => 'demo',
            '--workspace-name' => 'Демо 1',
            '--size' => 'small',
            '--as-of' => '2026-05-14',
        ]);
        $commandTester->assertCommandIsSuccessful();

        $workspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->findOneBy(['code' => 'demo']);

        self::assertInstanceOf(Workspace::class, $workspace);
        $workspaceUuid = $workspace->getUuid()->toRfc4122();

        $commandTester->execute([
            '--workspace-code' => 'demo',
            '--workspace-name' => 'Демо 2',
            '--size' => 'small',
            '--as-of' => '2026-05-14',
        ]);
        $commandTester->assertCommandIsSuccessful();

        $this->entityManager()->clear();
        $workspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->findOneBy(['code' => 'demo']);

        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame($workspaceUuid, $workspace->getUuid()->toRfc4122());
        self::assertSame('Демо 2', $workspace->getName());
        self::assertSame(1, $this->entityManager()->getRepository(Workspace::class)->count(['code' => 'demo']));
        self::assertSame(1, $this->entityManager()->getRepository(BillingSettings::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(AccountGroup::class)->count([]));
        self::assertSame(15, $this->entityManager()->getRepository(Account::class)->count([]));
        self::assertSame(15, $this->entityManager()->getRepository(Subscriber::class)->count([]));
        self::assertSame(18, $this->entityManager()->getRepository(SubscriberAccountAccess::class)->count([]));
        self::assertSame(15, $this->entityManager()->getRepository(AccountGroupMember::class)->count([]));
        self::assertSame(15, $this->entityManager()->getRepository(ElectricityMeter::class)->count([]));
        self::assertSame(18, $this->entityManager()->getRepository(ElectricityMeterRegister::class)->count([]));
        self::assertSame(215, $this->entityManager()->getRepository(ElectricityMeterReading::class)->count([]));
        self::assertSame(214, $this->countActiveElectricityMeterReadings());
        self::assertSame(1, $this->countSupersededElectricityMeterReadings());
        self::assertSame(15, $this->entityManager()->getRepository(AccountElectricityTariffProfileAssignment::class)->count([]));
        self::assertSame(59, $this->entityManager()->getRepository(Accrual::class)->count([]));
        self::assertSame(11, $this->entityManager()->getRepository(Payment::class)->count([]));
        self::assertSame(3, $this->entityManager()->getRepository(BillingRun::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(BillingRunAccountIssue::class)->count([]));
        self::assertSame(30, $this->entityManager()->getRepository(AccountStatementSnapshot::class)->count([]));
        self::assertSame(36, $this->entityManager()->getRepository(AccountStatementDelivery::class)->count([]));
        self::assertSame(36, $this->entityManager()->getRepository(AccountStatementDeliveryAttempt::class)->count([]));
        self::assertSame(
            ['queued' => 9, 'sent' => 9, 'failed' => 9, 'cancelled' => 9],
            $this->countDeliveryStatuses(),
        );
        self::assertSame(3, $this->entityManager()->getRepository(ElectricityTariffZone::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityTariffProfile::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityConsumptionBand::class)->count([]));
        self::assertSame(2, $this->entityManager()->getRepository(ElectricityTariffPeriod::class)->count([]));
        self::assertSame(6, $this->entityManager()->getRepository(ElectricityTariffRate::class)->count([]));
        self::assertSame(24, $this->entityManager()->getRepository(ElectricityConsumptionBandRule::class)->count([]));
        self::assertSame(24, $this->entityManager()->getRepository(ElectricityConsumptionBandRuleAllScope::class)->count([]));
        self::assertSame(48, $this->entityManager()->getRepository(ElectricityConsumptionBandRuleRange::class)->count([]));
    }

    public function testCommandGrantsWorkspaceRoleToExistingUser(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createAdminUser('andrey@example.test');
        $commandTester = $this->createCommandTester();

        $commandTester->execute([
            '--workspace-code' => 'demo',
            '--workspace-name' => 'Демо',
            '--size' => 'small',
            '--as-of' => '2026-05-14',
            '--grant-admin-email' => ['andrey@example.test'],
        ]);

        $commandTester->assertCommandIsSuccessful();
        $display = $commandTester->getDisplay();

        self::assertStringContainsString('andrey@example.test', $display);
        self::assertStringContainsString('выдана роль admin', $display);

        $workspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->findOneBy(['code' => 'demo']);

        self::assertInstanceOf(Workspace::class, $workspace);
        $assignment = $this->entityManager()
            ->getRepository(WorkspaceUserRoleAssignment::class)
            ->findOneBy(['workspace' => $workspace, 'user' => $user]);

        self::assertInstanceOf(WorkspaceUserRoleAssignment::class, $assignment);
        self::assertSame(WorkspaceUserRoleCode::Admin->value, $assignment->getRoleCode());
        self::assertTrue($assignment->isActive());

        $commandTester->execute([
            '--workspace-code' => 'demo',
            '--workspace-name' => 'Демо',
            '--size' => 'small',
            '--as-of' => '2026-05-14',
            '--grant-admin-email' => ['andrey@example.test'],
        ]);

        $commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('роль admin уже активна', $commandTester->getDisplay());
        self::assertSame(1, $this->entityManager()->getRepository(WorkspaceUserRoleAssignment::class)->count([]));
    }

    public function testCommandGrantsSubscriberPortalAccessToExistingUser(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createAdminUser('portal@example.test');
        $commandTester = $this->createCommandTester();

        $commandTester->execute([
            '--workspace-code' => 'demo',
            '--workspace-name' => 'Демо',
            '--size' => 'small',
            '--as-of' => '2026-05-14',
            '--grant-subscriber-email' => ['portal@example.test'],
        ]);

        $commandTester->assertCommandIsSuccessful();
        $display = $commandTester->getDisplay();

        self::assertStringContainsString('Доступ к личному кабинету', $display);
        self::assertStringContainsString('portal@example.test', $display);
        self::assertStringContainsString('выдан доступ к личному кабинету', $display);
        self::assertStringContainsString('9-001', $display);
        self::assertStringContainsString('9-013', $display);

        $workspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->findOneBy(['code' => 'demo']);

        self::assertInstanceOf(Workspace::class, $workspace);

        $subscriber = $this->entityManager()
            ->getRepository(Subscriber::class)
            ->findOneActiveByWorkspaceAndUser($workspace, $user);

        self::assertInstanceOf(Subscriber::class, $subscriber);

        $accountNumbers = array_map(
            static fn (SubscriberAccountAccess $access): ?string => $access->getAccount()?->getNumber(),
            $this->entityManager()
                ->getRepository(SubscriberAccountAccess::class)
                ->findActiveBySubscriber($workspace, $subscriber),
        );
        sort($accountNumbers);

        self::assertSame(['9-001', '9-013'], $accountNumbers);

        $commandTester->execute([
            '--workspace-code' => 'demo',
            '--workspace-name' => 'Демо',
            '--size' => 'small',
            '--as-of' => '2026-05-14',
            '--grant-subscriber-email' => ['portal@example.test'],
        ]);

        $commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('личный кабинет уже активен', $commandTester->getDisplay());
        self::assertSame(1, $this->entityManager()->getRepository(Subscriber::class)->count(['user' => $user]));
    }

    public function testCommandRejectsGrantToUnknownEmail(): void
    {
        static::createClient();
        $this->resetDatabase();
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            '--workspace-code' => 'demo',
            '--workspace-name' => 'Демо',
            '--size' => 'small',
            '--as-of' => '2026-05-14',
            '--grant-admin-email' => ['missing@example.test'],
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Пользователь с активным email "missing@example.test" не найден', $commandTester->getDisplay());
        self::assertNull($this->entityManager()->getRepository(Workspace::class)->findOneBy(['code' => 'demo']));
    }

    public function testCommandRejectsInvalidSize(): void
    {
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            '--size' => 'tiny',
            '--as-of' => '2026-05-14',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Размер набора должен быть одним из', $commandTester->getDisplay());
    }

    public function testCommandRejectsInvalidAsOfDate(): void
    {
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            '--as-of' => '14.05.2026',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('Опорная дата --as-of должна быть в формате YYYY-MM-DD', $commandTester->getDisplay());
    }

    public function testResetRequiresDemoConfirmation(): void
    {
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            '--reset' => true,
            '--as-of' => '2026-05-14',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--reset требует явного подтверждения --confirm=demo', $commandTester->getDisplay());
    }

    public function testResetIsAllowedOnlyForDemoWorkspaceCodes(): void
    {
        $commandTester = $this->createCommandTester();

        $exitCode = $commandTester->execute([
            '--workspace-code' => 'main',
            '--reset' => true,
            '--confirm' => 'demo',
            '--as-of' => '2026-05-14',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        self::assertStringContainsString('--reset разрешен только для хозяйств с кодом demo или demo-*', $commandTester->getDisplay());
    }

    public function testProdResetRequiresAdditionalFlag(): void
    {
        $commandTester = new CommandTester($this->createStandaloneCommand('prod'));

        $exitCode = $commandTester->execute([
            '--workspace-code' => 'demo-training',
            '--reset' => true,
            '--confirm' => 'demo',
            '--as-of' => '2026-05-14',
        ]);

        self::assertSame(Command::INVALID, $exitCode);
        $display = $commandTester->getDisplay();
        self::assertStringContainsString('--reset в prod-окружении требует дополнительный флаг', $display);
        self::assertStringContainsString('--allow-prod-reset', $display);
    }

    public function testConfirmedResetRecreatesDemoWorkspaceOnly(): void
    {
        static::createClient();
        $this->resetDatabase();
        $this->createAdminUser('andrey@example.test');
        $commandTester = $this->createCommandTester();

        $commandTester->execute([
            '--workspace-code' => 'demo',
            '--workspace-name' => 'Демо до reset',
            '--size' => 'small',
            '--as-of' => '2026-05-14',
            '--grant-admin-email' => ['andrey@example.test'],
        ]);
        $commandTester->assertCommandIsSuccessful();

        $workspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->findOneBy(['code' => 'demo']);

        self::assertInstanceOf(Workspace::class, $workspace);
        $workspaceUuid = $workspace->getUuid()->toRfc4122();
        self::assertSame(1, $this->entityManager()->getRepository(UserEmailIdentity::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(WorkspaceUserRoleAssignment::class)->count([]));

        $exitCode = $commandTester->execute([
            '--workspace-code' => 'demo',
            '--workspace-name' => 'Демо после reset',
            '--size' => 'small',
            '--reset' => true,
            '--confirm' => 'demo',
            '--as-of' => '2026-05-14',
            '--grant-admin-email' => ['andrey@example.test'],
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $display = $commandTester->getDisplay();
        self::assertStringContainsString('Reset демо-хозяйства', $display);
        self::assertStringContainsString('Удалено строк', $display);
        self::assertStringContainsString('Демо после reset', $display);

        $this->entityManager()->clear();

        $workspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->findOneBy(['code' => 'demo']);

        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertNotSame($workspaceUuid, $workspace->getUuid()->toRfc4122());
        self::assertSame('Демо после reset', $workspace->getName());
        self::assertSame(1, $this->entityManager()->getRepository(Workspace::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(UserEmailIdentity::class)->count([]));
        self::assertSame(1, $this->entityManager()->getRepository(WorkspaceUserRoleAssignment::class)->count([]));
        self::assertSame(15, $this->entityManager()->getRepository(Account::class)->count([]));
        self::assertSame(15, $this->entityManager()->getRepository(Subscriber::class)->count([]));
        self::assertSame(18, $this->entityManager()->getRepository(SubscriberAccountAccess::class)->count([]));
        self::assertSame(59, $this->entityManager()->getRepository(Accrual::class)->count([]));
        self::assertSame(11, $this->entityManager()->getRepository(Payment::class)->count([]));
        self::assertSame(30, $this->entityManager()->getRepository(AccountStatementSnapshot::class)->count([]));
        self::assertSame(36, $this->entityManager()->getRepository(AccountStatementDelivery::class)->count([]));
        self::assertSame(36, $this->entityManager()->getRepository(AccountStatementDeliveryAttempt::class)->count([]));
        self::assertSame(
            ['queued' => 9, 'sent' => 9, 'failed' => 9, 'cancelled' => 9],
            $this->countDeliveryStatuses(),
        );
    }

    private function createCommandTester(): CommandTester
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        return new CommandTester($application->find('app:demo:seed'));
    }

    private function createStandaloneCommand(string $environment): DemoSeedCommand
    {
        static::createClient();

        return new DemoSeedCommand(
            static::getContainer()->get(EntityManagerInterface::class),
            static::getContainer()->get(WorkspaceRepository::class),
            static::getContainer()->get(BillingSettingsRepository::class),
            static::getContainer()->get(AccountRepository::class),
            static::getContainer()->get(AccountGroupMemberRepository::class),
            static::getContainer()->get(AccountGroupRepository::class),
            static::getContainer()->get(SubscriberRepository::class),
            static::getContainer()->get(SubscriberAccountAccessRepository::class),
            static::getContainer()->get(UserEmailIdentityRepository::class),
            static::getContainer()->get(WorkspaceUserRoleAssignmentRepository::class),
            static::getContainer()->get(ElectricityTariffZoneRepository::class),
            static::getContainer()->get(ElectricityTariffProfileRepository::class),
            static::getContainer()->get(ElectricityConsumptionBandRepository::class),
            static::getContainer()->get(ElectricityTariffPeriodRepository::class),
            static::getContainer()->get(ElectricityTariffRateRepository::class),
            static::getContainer()->get(ElectricityConsumptionBandRuleRepository::class),
            static::getContainer()->get(ElectricityConsumptionBandRuleRangeRepository::class),
            static::getContainer()->get(ElectricityConsumptionBandRuleAllScopeRepository::class),
            static::getContainer()->get(AccountElectricityTariffProfileAssignmentRepository::class),
            static::getContainer()->get(ElectricityMeterRepository::class),
            static::getContainer()->get(ElectricityMeterRegisterRepository::class),
            static::getContainer()->get(ElectricityMeterReadingRepository::class),
            static::getContainer()->get(AccrualRepository::class),
            static::getContainer()->get(PaymentRepository::class),
            static::getContainer()->get(PaymentRequisiteProfileRepository::class),
            static::getContainer()->get(PaymentRequisiteAssignmentRepository::class),
            static::getContainer()->get(BillingRunRepository::class),
            static::getContainer()->get(BillingRunAccountIssueRepository::class),
            static::getContainer()->get(BillingRunIssueGenerator::class),
            static::getContainer()->get(ElectricityBillingRunAccrualGenerator::class),
            static::getContainer()->get(AccountStatementSnapshotRepository::class),
            static::getContainer()->get(AccountStatementDeliveryRepository::class),
            static::getContainer()->get(AccountStatementSnapshotGenerator::class),
            static::getContainer()->get(AccountStatementDeliveryEnqueuer::class),
            $environment,
        );
    }

    private function findAccount(string $number): Account
    {
        $account = $this->entityManager()
            ->getRepository(Account::class)
            ->findOneBy(['number' => $number]);

        self::assertInstanceOf(Account::class, $account);

        return $account;
    }

    private function findFinancialScenarioAccrual(Workspace $workspace, Account $account): Accrual
    {
        $accrual = $this->entityManager()
            ->getRepository(Accrual::class)
            ->findOneActivePostedByAccountTypeAndPeriod(
                $workspace,
                $account,
                AccrualType::Electricity,
                new DateTimeImmutable('2025-05-01'),
                new DateTimeImmutable('2026-05-01'),
            );

        self::assertInstanceOf(Accrual::class, $accrual);

        return $accrual;
    }

    private function countActiveElectricityMeterReadings(): int
    {
        return (int) $this->entityManager()
            ->createQueryBuilder()
            ->select('COUNT(reading.uuid)')
            ->from(ElectricityMeterReading::class, 'reading')
            ->andWhere('reading.cancelledAt IS NULL')
            ->andWhere('reading.replacingReading IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countSupersededElectricityMeterReadings(): int
    {
        return (int) $this->entityManager()
            ->createQueryBuilder()
            ->select('COUNT(reading.uuid)')
            ->from(ElectricityMeterReading::class, 'reading')
            ->andWhere('reading.replacingReading IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return array{queued: int, sent: int, failed: int, cancelled: int}
     */
    private function countDeliveryStatuses(): array
    {
        $counts = [
            'queued' => 0,
            'sent' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        foreach ($this->entityManager()->getRepository(AccountStatementDelivery::class)->findAll() as $delivery) {
            self::assertInstanceOf(AccountStatementDelivery::class, $delivery);

            if ($delivery->isCancelled()) {
                ++$counts['cancelled'];

                continue;
            }

            $statusCode = $delivery->getLatestAttempt()?->getStatusCode() ?? 'queued';

            if (isset($counts[$statusCode])) {
                ++$counts[$statusCode];
            }
        }

        return $counts;
    }
}
