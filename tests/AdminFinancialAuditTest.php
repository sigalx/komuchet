<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountElectricityTariffProfileAssignment;
use App\Entity\Accrual;
use App\Entity\AuditLog;
use App\Entity\BillingRun;
use App\Entity\BillingRunAccountIssue;
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
use App\Entity\Workspace;
use App\Enum\AccrualType;
use App\Enum\BillingRunAccountIssueCloseReason;
use App\Enum\BillingRunAccountIssueType;
use App\Enum\BillingRunKind;
use App\Enum\ElectricityConsumptionBandRuleScopeMode;
use App\Enum\ElectricityMeterReadingSource;
use DateTimeImmutable;
use Symfony\Component\Uid\Uuid;

final class AdminFinancialAuditTest extends FunctionalWebTestCase
{
    public function testPaymentAndReadingActionsWriteAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/payments/new', $account->getUuid()));
        $client->submitForm('Сохранить', [
            'payment[amount]' => '1234,50',
            'payment[paidOn]' => '09.05.2026',
            'payment[payerName]' => 'Иванов Иван Иванович',
            'payment[purpose]' => 'Оплата света май 2026',
            'payment[externalReference]' => 'audit-payment-1',
        ]);
        $payment = $this->findPaymentByReference('audit-payment-1');

        self::assertInstanceOf(Payment::class, $payment);
        $paymentCreatedLog = $this->findAuditLog('payment.created', $payment->getUuid());

        self::assertInstanceOf(AuditLog::class, $paymentCreatedLog);
        self::assertSame('payments', $paymentCreatedLog->getEntityTable());
        self::assertSame('1234.50', $paymentCreatedLog->getNewValues()['amount'] ?? null);
        self::assertSame('9-123', $paymentCreatedLog->getNewValues()['account_number'] ?? null);

        $client->followRedirect();
        $client->submitForm('Отменить', [
            'payment_cancel[reason]' => 'Аудит: дубль платежа',
        ]);
        $paymentCancelledLog = $this->findAuditLog('payment.cancelled', $payment->getUuid());

        self::assertInstanceOf(AuditLog::class, $paymentCancelledLog);
        self::assertSame('Аудит: дубль платежа', $paymentCancelledLog->getReason());
        self::assertSame(['cancelled_at', 'cancelled_by', 'cancellation_reason'], $paymentCancelledLog->getChangedFields());

        $client->request('GET', sprintf('/admin/electricity-meters/%s/readings/new', $meter->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_meter_reading[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_meter_reading[readingValue]' => '123,456',
            'electricity_meter_reading[takenOn]' => '09.05.2026',
            'electricity_meter_reading[notes]' => 'Аудит показания',
        ]);
        $reading = $this->findReading($meter, $tariffZone, '123.456');

        self::assertInstanceOf(ElectricityMeterReading::class, $reading);
        $readingCreatedLog = $this->findAuditLog('electricity_meter_reading.created', $reading->getUuid());

        self::assertInstanceOf(AuditLog::class, $readingCreatedLog);
        self::assertSame('electricity_meter_readings', $readingCreatedLog->getEntityTable());
        self::assertSame('123.456', $readingCreatedLog->getNewValues()['reading_value'] ?? null);
        self::assertSame('2026-05-09', $readingCreatedLog->getNewValues()['taken_on'] ?? null);

        $client->followRedirect();
        $client->submitForm('Отменить', [
            'electricity_meter_reading_cancel[reason]' => 'Аудит: неверное фото',
        ]);
        $readingCancelledLog = $this->findAuditLog('electricity_meter_reading.cancelled', $reading->getUuid());

        self::assertInstanceOf(AuditLog::class, $readingCancelledLog);
        self::assertSame('Аудит: неверное фото', $readingCancelledLog->getReason());
    }

    public function testAccrualAndBillingRunActionsWriteAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $meter, $tariffZone, '170', '2026-06-04');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffProfileAssignment($workspace, $account, $tariffProfile, '2026-05-01');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $rule = $this->createConsumptionBandRule($workspace, $tariffProfile, 5, '2026-05-01');
        $socialBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма');
        $aboveBand = $this->createConsumptionBand($workspace, 'above_social_norm', 'Сверх социальной нормы');
        $this->createConsumptionBandRuleRange($workspace, $rule, $socialBand, '0', '100');
        $this->createConsumptionBandRuleRange($workspace, $rule, $aboveBand, '100', null);
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $socialBand, '5.000000');
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $aboveBand, '7.000000');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/accruals/new', $account->getUuid()));
        $client->submitForm('Сохранить', [
            'accrual[type]' => AccrualType::MembershipFee->value,
            'accrual[amount]' => '2000,25',
            'accrual[periodStart]' => '01.05.2026',
            'accrual[periodEnd]' => '01.06.2026',
            'accrual[notes]' => 'Аудит начисления',
        ]);
        $manualAccrual = $this->findAccrualByNotes('Аудит начисления');

        self::assertInstanceOf(Accrual::class, $manualAccrual);
        $accrualCreatedLog = $this->findAuditLog('accrual.created', $manualAccrual->getUuid());

        self::assertInstanceOf(AuditLog::class, $accrualCreatedLog);
        self::assertSame('2000.25', $accrualCreatedLog->getNewValues()['amount'] ?? null);
        self::assertSame(AccrualType::MembershipFee->value, $accrualCreatedLog->getNewValues()['type'] ?? null);

        $client->followRedirect();
        $client->submitForm('Отменить', [
            'accrual_cancel[reason]' => 'Аудит: ошибочное начисление',
        ]);
        $accrualCancelledLog = $this->findAuditLog('accrual.cancelled', $manualAccrual->getUuid());

        self::assertInstanceOf(AuditLog::class, $accrualCancelledLog);
        self::assertSame('Аудит: ошибочное начисление', $accrualCancelledLog->getReason());

        $client->request('GET', '/admin/billing-runs/new');
        $client->submitForm('Сохранить', [
            'billing_run[kind]' => BillingRunKind::Electricity->value,
            'billing_run[periodStart]' => '01.05.2026',
            'billing_run[periodEnd]' => '01.06.2026',
        ]);
        $billingRun = $this->findBillingRunByPeriod('2026-05-01', '2026-06-01');

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('billing_run.created', $billingRun->getUuid()));

        $client->followRedirect();
        $client->submitForm('Сгенерировать начисления');
        $client->followRedirect();

        $generationLog = $this->findAuditLog('billing_run.accruals_generated', $billingRun->getUuid());

        self::assertInstanceOf(AuditLog::class, $generationLog);
        self::assertSame(1, $generationLog->getNewValues()['created'] ?? null);

        $client->submitForm('Провести расчет');
        $postedLog = $this->findAuditLog('billing_run.posted', $billingRun->getUuid());

        self::assertInstanceOf(AuditLog::class, $postedLog);
        self::assertSame(1, $postedLog->getNewValues()['posted_accrual_count'] ?? null);
    }

    public function testBillingRunIssueRecheckCloseAndCancelActionsWriteAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-124');
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-runs/new');
        $client->submitForm('Сохранить', [
            'billing_run[kind]' => BillingRunKind::Electricity->value,
            'billing_run[periodStart]' => '01.07.2026',
            'billing_run[periodEnd]' => '01.08.2026',
        ]);
        $billingRun = $this->findBillingRunByPeriod('2026-07-01', '2026-08-01');

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertInstanceOf(AuditLog::class, $this->findAuditLog('billing_run.created', $billingRun->getUuid()));

        $client->followRedirect();
        $client->submitForm('Проверить повторно');
        $recheckedLog = $this->findAuditLog('billing_run.issues_rechecked', $billingRun->getUuid());

        self::assertInstanceOf(AuditLog::class, $recheckedLog);
        self::assertArrayHasKey('updated', $recheckedLog->getNewValues());

        $issue = $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::MissingReading);

        self::assertInstanceOf(BillingRunAccountIssue::class, $issue);

        $client->followRedirect();
        $client->request('GET', '/admin/billing-run-issues', [
            'account_uuid' => $account->getUuid()->toRfc4122(),
        ]);
        $client->submitForm('Закрыть', [
            'billing_run_account_issue_close[reason]' => BillingRunAccountIssueCloseReason::Ignored->value,
            'billing_run_account_issue_close[comment]' => 'Аудит: закрыто вручную',
        ]);
        $issueClosedLog = $this->findAuditLog('billing_run_issue.closed', $issue->getUuid());

        self::assertInstanceOf(AuditLog::class, $issueClosedLog);
        self::assertSame('Аудит: закрыто вручную', $issueClosedLog->getReason());
        self::assertSame(BillingRunAccountIssueCloseReason::Ignored->value, $issueClosedLog->getNewValues()['close_reason'] ?? null);

        $client->request('GET', sprintf('/admin/billing-runs/%s', $billingRun->getUuid()));
        $client->submitForm('Отменить', [
            'billing_run_cancel[reason]' => 'Аудит: ошибочный запуск',
        ]);
        $cancelledLog = $this->findAuditLog('billing_run.cancelled', $billingRun->getUuid());

        self::assertInstanceOf(AuditLog::class, $cancelledLog);
        self::assertSame('Аудит: ошибочный запуск', $cancelledLog->getReason());
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createTariffZone(Workspace $workspace, string $code, string $name): ElectricityTariffZone
    {
        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode($code)
            ->setName($name);

        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->flush();

        return $tariffZone;
    }

    private function createElectricityMeter(Workspace $workspace, Account $account, ElectricityTariffZone $tariffZone): ElectricityMeter
    {
        $meter = new ElectricityMeter($workspace, $account, new DateTimeImmutable('2026-05-01'));
        $register = new ElectricityMeterRegister($workspace, $meter, $tariffZone);

        $this->entityManager()->persist($meter);
        $this->entityManager()->persist($register);
        $this->entityManager()->flush();

        return $meter;
    }

    private function createReading(
        Workspace $workspace,
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
        string $value,
        string $takenOn,
    ): ElectricityMeterReading {
        $reading = new ElectricityMeterReading(
            $workspace,
            $meter,
            $tariffZone,
            $value,
            new DateTimeImmutable($takenOn),
            ElectricityMeterReadingSource::Admin,
        );

        $this->entityManager()->persist($reading);
        $this->entityManager()->flush();

        return $reading;
    }

    private function createTariffProfile(Workspace $workspace, string $code, string $name): ElectricityTariffProfile
    {
        $tariffProfile = (new ElectricityTariffProfile($workspace))
            ->setCode($code)
            ->setName($name);

        $this->entityManager()->persist($tariffProfile);
        $this->entityManager()->flush();

        return $tariffProfile;
    }

    private function createTariffProfileAssignment(
        Workspace $workspace,
        Account $account,
        ElectricityTariffProfile $tariffProfile,
        string $validFrom,
    ): AccountElectricityTariffProfileAssignment {
        $assignment = new AccountElectricityTariffProfileAssignment($workspace, $account, $tariffProfile, new DateTimeImmutable($validFrom));

        $this->entityManager()->persist($assignment);
        $this->entityManager()->flush();

        return $assignment;
    }

    private function createTariffPeriod(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        string $validFrom,
    ): ElectricityTariffPeriod {
        $tariffPeriod = new ElectricityTariffPeriod($workspace, $tariffProfile, new DateTimeImmutable($validFrom));

        $this->entityManager()->persist($tariffPeriod);
        $this->entityManager()->flush();

        return $tariffPeriod;
    }

    private function createConsumptionBandRule(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        int $month,
        string $validFrom,
    ): ElectricityConsumptionBandRule {
        $rule = new ElectricityConsumptionBandRule($workspace, $tariffProfile, new DateTimeImmutable($validFrom), $month);

        $this->entityManager()->persist($rule);
        $this->entityManager()->persist(new ElectricityConsumptionBandRuleAllScope($workspace, $rule, ElectricityConsumptionBandRuleScopeMode::Include));
        $this->entityManager()->flush();

        return $rule;
    }

    private function createConsumptionBand(Workspace $workspace, string $code, string $name): ElectricityConsumptionBand
    {
        $band = (new ElectricityConsumptionBand($workspace))
            ->setCode($code)
            ->setName($name);

        $this->entityManager()->persist($band);
        $this->entityManager()->flush();

        return $band;
    }

    private function createConsumptionBandRuleRange(
        Workspace $workspace,
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBand $band,
        string $lowerBoundKwh,
        ?string $upperBoundKwh,
    ): ElectricityConsumptionBandRuleRange {
        $range = new ElectricityConsumptionBandRuleRange($workspace, $rule, $band, $lowerBoundKwh, $upperBoundKwh);

        $this->entityManager()->persist($range);
        $this->entityManager()->flush();

        return $range;
    }

    private function createTariffRate(
        Workspace $workspace,
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffZone $tariffZone,
        ElectricityConsumptionBand $band,
        string $rate,
    ): ElectricityTariffRate {
        $tariffRate = new ElectricityTariffRate($workspace, $tariffPeriod, $tariffZone, $band, $rate);

        $this->entityManager()->persist($tariffRate);
        $this->entityManager()->flush();

        return $tariffRate;
    }

    private function findPaymentByReference(string $externalReference): ?Payment
    {
        return $this->entityManager()
            ->getRepository(Payment::class)
            ->findOneBy(['externalReference' => $externalReference]);
    }

    private function findReading(ElectricityMeter $meter, ElectricityTariffZone $tariffZone, string $readingValue): ?ElectricityMeterReading
    {
        return $this->entityManager()
            ->getRepository(ElectricityMeterReading::class)
            ->findOneBy([
                'electricityMeter' => $meter,
                'tariffZone' => $tariffZone,
                'readingValue' => $readingValue,
            ]);
    }

    private function findAccrualByNotes(string $notes): ?Accrual
    {
        return $this->entityManager()
            ->getRepository(Accrual::class)
            ->findOneBy(['notes' => $notes]);
    }

    private function findBillingRunByPeriod(string $periodStart, string $periodEnd): ?BillingRun
    {
        return $this->entityManager()
            ->getRepository(BillingRun::class)
            ->findOneBy([
                'periodStart' => new DateTimeImmutable($periodStart),
                'periodEnd' => new DateTimeImmutable($periodEnd),
            ]);
    }

    private function findBillingRunIssue(BillingRun $billingRun, BillingRunAccountIssueType $issueType): ?BillingRunAccountIssue
    {
        return $this->entityManager()
            ->getRepository(BillingRunAccountIssue::class)
            ->findOneBy([
                'billingRun' => $billingRun,
                'issueType' => $issueType,
            ]);
    }

    private function findAuditLog(string $action, Uuid $entityUuid): ?AuditLog
    {
        return $this->entityManager()
            ->getRepository(AuditLog::class)
            ->findOneBy(['action' => $action, 'entityUuid' => $entityUuid]);
    }
}
