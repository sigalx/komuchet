<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementDeliveryAttempt;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Accrual;
use App\Entity\BillingRun;
use App\Entity\BillingRunAccountIssue;
use App\Entity\BillingSettings;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffZone;
use App\Entity\Payment;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use App\Enum\BillingRunAccountIssueType;
use App\Enum\BillingRunKind;
use App\Enum\ElectricityMeterReadingSource;
use App\Enum\PaymentSource;
use DateTimeImmutable;

final class AdminDashboardControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyDashboard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Рабочий стол');
        $this->assertSelectorTextContains('body', 'Настройки расчетов не найдены.');
        $this->assertSelectorTextContains('body', 'Открытых проблем нет.');
        $this->assertSelectorTextContains('body', 'Доставка квитанций');
        $this->assertSelectorTextContains('body', 'В очереди: 0');
        $this->assertSelectorTextContains('body', 'ошибки: 0');
        $this->assertSelectorTextContains('body', 'Очередь пуста');
        $this->assertSelectorTextContains('body', 'Должников нет.');
        $this->assertSelectorTextContains('body', 'Оплаты пока не внесены.');
        $this->assertSelectorTextContains('body', 'Показания пока не внесены.');
        $this->assertSelectorExists('a[href="/admin"].active');
    }

    public function testAdminCanSeeOperationalDashboardData(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace(name: 'СНТ Тест');
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace);
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);

        $this->createBillingSettings($workspace);
        $billingRun = $this->createBillingRun($workspace, $admin);
        $this->createIssue($workspace, $billingRun, $account, $admin);
        $this->createPostedAccrual($workspace, $account, '2000.00');
        $this->createPayment($workspace, $account, '500.00');
        $this->createReading($workspace, $meter, $tariffZone, '123.456', $admin);
        $this->createStatementDelivery($workspace, $account, 'queued@example.test', 'queued', $admin);
        $this->createStatementDelivery($workspace, $account, 'failed@example.test', 'failed', $admin);
        $client->loginUser($admin);

        $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Рабочий стол');
        $this->assertSelectorTextContains('body', 'СНТ Тест');
        $this->assertSelectorTextContains('body', 'Проблемы расчетов');
        $this->assertSelectorTextContains('body', 'Требуют внимания');
        $this->assertSelectorTextContains('body', 'День месяца: 5');
        $this->assertSelectorTextContains('body', 'актуальность показаний: 15 дн.');
        $this->assertSelectorTextContains('body', 'Есть ошибки');
        $this->assertSelectorTextContains('body', 'В очереди: 1');
        $this->assertSelectorTextContains('body', 'ошибки: 1');
        $this->assertSelectorTextContains('body', 'Нет показаний');
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', '1 500,00 руб.');
        $this->assertSelectorTextContains('body', '500,00 руб.');
        $this->assertSelectorTextContains('body', '123,456');
        $this->assertSelectorExists('a[href="/admin/billing-runs/new"]');
        $this->assertSelectorExists('a[href="/admin/account-balances?state=debt&sort=debt_desc"]');
        $this->assertSelectorExists('a[href="/admin/account-statement-deliveries"]');
    }

    private function createBillingSettings(Workspace $workspace): BillingSettings
    {
        $billingSettings = (new BillingSettings($workspace, 'СНТ Тест'))
            ->setInvoiceGenerationDay(5)
            ->setReadingFreshnessWindowDays(15);

        $this->entityManager()->persist($billingSettings);
        $this->entityManager()->flush();

        return $billingSettings;
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createTariffZone(Workspace $workspace): ElectricityTariffZone
    {
        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode('single')
            ->setName('Однотарифная зона');

        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->flush();

        return $tariffZone;
    }

    private function createElectricityMeter(
        Workspace $workspace,
        Account $account,
        ElectricityTariffZone $tariffZone,
    ): ElectricityMeter {
        $meter = (new ElectricityMeter($workspace, $account, new DateTimeImmutable('2026-05-01')))
            ->setSerialNumber('SN-001');
        $register = new ElectricityMeterRegister($workspace, $meter, $tariffZone);

        $this->entityManager()->persist($meter);
        $this->entityManager()->persist($register);
        $this->entityManager()->flush();

        return $meter;
    }

    private function createBillingRun(Workspace $workspace, User $admin): BillingRun
    {
        $billingRun = new BillingRun(
            $workspace,
            BillingRunKind::Electricity,
            new DateTimeImmutable('2026-05-01'),
            new DateTimeImmutable('2026-06-01'),
            $admin,
        );

        $this->entityManager()->persist($billingRun);
        $this->entityManager()->flush();

        return $billingRun;
    }

    private function createIssue(
        Workspace $workspace,
        BillingRun $billingRun,
        Account $account,
        User $admin,
    ): BillingRunAccountIssue {
        $issue = new BillingRunAccountIssue(
            $workspace,
            $billingRun,
            $account,
            BillingRunAccountIssueType::MissingReading,
            'Нет актуальных показаний по участку.',
            $admin,
        );

        $this->entityManager()->persist($issue);
        $this->entityManager()->flush();

        return $issue;
    }

    private function createPostedAccrual(Workspace $workspace, Account $account, string $amount): Accrual
    {
        $accrual = new Accrual(
            $workspace,
            $account,
            AccrualType::Electricity,
            new DateTimeImmutable('2026-05-01'),
            new DateTimeImmutable('2026-06-01'),
            $amount,
        );
        $accrual->post();

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }

    private function createPayment(Workspace $workspace, Account $account, string $amount): Payment
    {
        $payment = new Payment(
            $workspace,
            $account,
            $amount,
            new DateTimeImmutable('2026-05-09'),
            PaymentSource::Manual,
        );

        $this->entityManager()->persist($payment);
        $this->entityManager()->flush();

        return $payment;
    }

    private function createReading(
        Workspace $workspace,
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
        string $value,
        User $admin,
    ): ElectricityMeterReading {
        $reading = new ElectricityMeterReading(
            $workspace,
            $meter,
            $tariffZone,
            $value,
            new DateTimeImmutable('2026-05-09'),
            ElectricityMeterReadingSource::Admin,
            $admin,
        );

        $this->entityManager()->persist($reading);
        $this->entityManager()->flush();

        return $reading;
    }

    private function createStatementDelivery(
        Workspace $workspace,
        Account $account,
        string $recipientEmail,
        string $status,
        User $admin,
    ): AccountStatementDelivery {
        $statement = new AccountStatementSnapshot(
            workspace: $workspace,
            account: $account,
            statementDate: new DateTimeImmutable('2026-05-13'),
            activeAccrualTotal: '2000.00',
            activePaymentTotal: '500.00',
            balanceAmount: '-1500.00',
            amountToPay: '1500.00',
            overpaymentAmount: '0.00',
            generatedBy: $admin,
        );
        $delivery = new AccountStatementDelivery(
            workspace: $workspace,
            accountStatement: $statement,
            recipientEmail: $recipientEmail,
            recipientName: 'Иванов Иван Иванович',
            createdBy: $admin,
        );
        $attempt = new AccountStatementDeliveryAttempt($workspace, $delivery, 1, $admin);
        $delivery->addAttempt($attempt);

        if ($status === 'failed') {
            $attempt->markFailed('SMTP server is unavailable.');
        } elseif ($status === 'sent') {
            $attempt->markSucceeded('message-id');
        } elseif ($status === 'sending') {
            $attempt->markStarted();
        }

        $this->entityManager()->persist($statement);
        $this->entityManager()->persist($delivery);
        $this->entityManager()->persist($attempt);
        $this->entityManager()->flush();

        return $delivery;
    }
}
