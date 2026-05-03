<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountElectricityTariffProfileAssignment;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementDeliveryAttempt;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Accrual;
use App\Entity\AuditLog;
use App\Entity\ElectricityAccrualLine;
use App\Entity\ElectricityAccrualRegister;
use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffZone;
use App\Entity\Payment;
use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use App\Enum\ElectricityMeterReadingSource;
use App\Enum\PaymentSource;
use App\Enum\SubscriberAccountAccessRole;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminAccountControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/accounts');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyAccountsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/accounts');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Участки');
        $this->assertSelectorTextContains('td', 'Участки пока не созданы.');
    }

    public function testAdminCanCreateAccount(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/accounts/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'account[number]' => '9-123',
            'account[notes]' => 'Тестовая заметка',
        ]);

        $account = $this->findAccountByNumber('9-123');

        self::assertInstanceOf(Account::class, $account);
        $this->assertResponseRedirects(sprintf('/admin/accounts/%s', $account->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $account->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame('Тестовая заметка', $account->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $account->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertNull($account->getDeletedAt());
    }

    public function testAdminCanFilterAccountsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $accountWithSubscriber = $this->createAccount($workspace, '9-001', 'Летний участок');
        $accountWithoutSubscriber = $this->createAccount($workspace, '9-002', 'Зимний участок');
        $hiddenAccount = $this->createAccount($workspace, '8-999', 'Удаленная запись');
        $hiddenAccount->delete();
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $this->createAccess($workspace, $subscriber, $accountWithSubscriber);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/accounts?q=9-00');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '8-999');
        $this->assertSelectorExists('input[name="q"][value="9-00"]');

        $client->request('GET', '/admin/accounts?q=летний');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');

        $client->request('GET', '/admin/accounts?access=with_subscribers');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');

        $client->request('GET', '/admin/accounts?access=without_subscribers');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/accounts?sort=number&dir=desc');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $accountTwoPosition = strpos($content, '9-002');
        $accountOnePosition = strpos($content, '9-001');
        self::assertNotFalse($accountTwoPosition);
        self::assertNotFalse($accountOnePosition);
        self::assertLessThan($accountOnePosition, $accountTwoPosition);
        $this->assertSelectorExists('a[href="/admin/accounts?sort=number&dir=asc&page=1"]');
    }

    public function testAdminCannotCreateDuplicateActiveAccountNumber(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $client->request('GET', '/admin/accounts/new');
        $client->submitForm('Сохранить', [
            'account[number]' => '9-123',
            'account[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Активный участок с таким номером уже существует.');
        self::assertSame(1, $this->countAccountsByNumber('9-123'));
    }

    public function testAdminCanEditAccount(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123', 'До исправления');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/edit', $account->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'account[number]' => '9-124',
            'account[notes]' => 'После исправления',
        ]);

        $updatedAccount = $this->findAccountByUuid($account->getUuid());

        self::assertInstanceOf(Account::class, $updatedAccount);
        $this->assertResponseRedirects(sprintf('/admin/accounts/%s', $updatedAccount->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('9-124', $updatedAccount->getNumber());
        self::assertSame('После исправления', $updatedAccount->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $updatedAccount->getUpdatedBy()?->getUuid()->toRfc4122());
    }

    public function testAdminCanSeeElectricityMetersAndLatestReadingsOnAccountCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode('day')
            ->setName('Дневная зона');
        $activeMeter = (new ElectricityMeter($workspace, $account, new DateTimeImmutable('2026-05-01')))
            ->setSerialNumber('SN-ACTIVE')
            ->setModel('Меркурий 201')
            ->setVerificationValidUntil(new DateTimeImmutable('2032-05-01'));
        $removedMeter = (new ElectricityMeter($workspace, $account, new DateTimeImmutable('2020-01-01')))
            ->setSerialNumber('SN-OLD')
            ->setModel('Старый счетчик')
            ->setRemovedOn(new DateTimeImmutable('2026-04-30'));
        $deletedMeter = (new ElectricityMeter($workspace, $account, new DateTimeImmutable('2018-01-01')))
            ->setSerialNumber('SN-DELETED')
            ->setRemovedOn(new DateTimeImmutable('2019-01-01'))
            ->delete();
        $activeReading = new ElectricityMeterReading(
            $workspace,
            $activeMeter,
            $tariffZone,
            '123.456',
            new DateTimeImmutable('2026-05-17'),
            ElectricityMeterReadingSource::Admin,
        );
        $cancelledReading = new ElectricityMeterReading(
            $workspace,
            $activeMeter,
            $tariffZone,
            '999.000',
            new DateTimeImmutable('2026-05-18'),
            ElectricityMeterReadingSource::Admin,
        );
        $cancelledReading->cancel('Ошибочное показание');
        $removedMeterReading = new ElectricityMeterReading(
            $workspace,
            $removedMeter,
            $tariffZone,
            '44.000',
            new DateTimeImmutable('2026-04-30'),
            ElectricityMeterReadingSource::Admin,
        );

        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->persist($activeMeter);
        $this->entityManager()->persist($removedMeter);
        $this->entityManager()->persist($deletedMeter);
        $this->entityManager()->persist(new ElectricityMeterRegister($workspace, $activeMeter, $tariffZone));
        $this->entityManager()->persist(new ElectricityMeterRegister($workspace, $removedMeter, $tariffZone));
        $this->entityManager()->persist($activeReading);
        $this->entityManager()->persist($cancelledReading);
        $this->entityManager()->persist($removedMeterReading);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Электросчетчики');
        $this->assertSelectorTextContains('body', 'SN-ACTIVE');
        $this->assertSelectorTextContains('body', 'Меркурий 201');
        $this->assertSelectorTextContains('body', 'Активен');
        $this->assertSelectorTextContains('body', 'Дневная зона');
        $this->assertSelectorTextContains('body', '123,456 кВт*ч');
        $this->assertSelectorTextContains('body', '17.05.2026');
        $this->assertSelectorTextContains('body', 'SN-OLD');
        $this->assertSelectorTextContains('body', 'Старый счетчик');
        $this->assertSelectorTextContains('body', 'Снят');
        $this->assertSelectorTextContains('body', '44,000 кВт*ч');
        $this->assertSelectorTextNotContains('body', 'SN-DELETED');
        $this->assertSelectorTextNotContains('body', '999,000 кВт*ч');
        $this->assertSelectorExists(sprintf('a[href="/admin/electricity-meters/%s"]', $activeMeter->getUuid()));
        $this->assertSelectorExists(sprintf('a[href="/admin/electricity-meter-readings/%s"]', $activeReading->getUuid()));
        $this->assertSelectorExists(sprintf('a[href="/admin/electricity-meters/%s/readings/new"]', $activeMeter->getUuid()));
        $this->assertSelectorNotExists(sprintf('a[href="/admin/electricity-meters/%s/readings/new"]', $removedMeter->getUuid()));
    }

    public function testAdminCanSoftDeleteAccount(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $accountUuid = $account->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/edit', $accountUuid));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Удалить');
        $this->assertResponseRedirects('/admin/accounts', Response::HTTP_SEE_OTHER);

        $deletedAccount = $this->findAccountByUuid($accountUuid);

        self::assertInstanceOf(Account::class, $deletedAccount);
        self::assertNotNull($deletedAccount->getDeletedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $deletedAccount->getDeletedBy()?->getUuid()->toRfc4122());

        $client->request('GET', '/admin/accounts');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Участки пока не созданы.');
    }

    public function testAdminCanOpenAccountStatement(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $this->createPostedAccrual($workspace, $account, '1500.00');
        $cancelledAccrual = $this->createPostedAccrual($workspace, $account, '9999.00', '2026-04-01');
        $cancelledAccrual->cancel('Не участвует в квитанции');
        $this->createPayment($workspace, $account, '500.00');
        $cancelledPayment = $this->createPayment($workspace, $account, '8888.00', '2026-04-09');
        $cancelledPayment->cancel('Не участвует в квитанции');
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('a[href="/admin/accounts/%s/statement"]', $account->getUuid()));

        $client->request('GET', sprintf('/admin/accounts/%s/statement', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Квитанция по участку 9-123');
        $this->assertSelectorTextContains('body', 'Сумма к оплате');
        $this->assertSelectorTextContains('body', '1 000,00 руб.');
        $this->assertSelectorTextContains('body', '1 500,00 руб.');
        $this->assertSelectorTextContains('body', '500,00 руб.');
        $this->assertSelectorTextNotContains('body', '9 999,00 руб.');
        $this->assertSelectorTextNotContains('body', '8 888,00 руб.');
    }

    public function testAdminCanCreateAccountStatementSnapshot(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $accrual = $this->createPostedAccrual($workspace, $account, '1500.00');
        $this->createElectricityAccrualDetails($workspace, $account, $accrual);
        $payment = $this->createPayment($workspace, $account, '500.00');
        $this->createDefaultPaymentRequisiteProfile($workspace, $admin);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/statement', $account->getUuid()));
        $this->assertResponseIsSuccessful();
        $client->submitForm('Зафиксировать квитанцию');

        $snapshot = $this->entityManager()
            ->getRepository(AccountStatementSnapshot::class)
            ->findOneBy(['account' => $account]);

        self::assertInstanceOf(AccountStatementSnapshot::class, $snapshot);
        $this->assertResponseRedirects(sprintf(
            '/admin/accounts/%s/statements/%s',
            $account->getUuid(),
            $snapshot->getUuid(),
        ), Response::HTTP_SEE_OTHER);
        self::assertMatchesRegularExpression('/^ST-\d{8}-[0-9A-F]{8}$/', $snapshot->getNumber());
        self::assertSame('9-123', $snapshot->getAccountNumber());
        self::assertSame('1500.00', $snapshot->getActiveAccrualTotal());
        self::assertSame('500.00', $snapshot->getActivePaymentTotal());
        self::assertSame('1000.00', $snapshot->getAmountToPay());
        self::assertSame('ТСН "Ромашка"', $snapshot->getPaymentRecipientName());
        self::assertSame('1234567890', $snapshot->getPaymentRecipientInn());
        self::assertSame('044525225', $snapshot->getPaymentBankBik());
        self::assertSame('40703810900000000001', $snapshot->getPaymentBankAccount());
        self::assertSame(sprintf('Оплата по квитанции %s, участок 9-123', $snapshot->getNumber()), $snapshot->getPaymentPurpose());
        self::assertSame($admin->getUuid()->toRfc4122(), $snapshot->getGeneratedBy()?->getUuid()->toRfc4122());

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Квитанция № '.$snapshot->getNumber());
        $this->assertSelectorTextContains('body', '1 500,00 руб.');
        $this->assertSelectorTextContains('body', '500,00 руб.');
        $this->assertSelectorTextContains('body', '1 000,00 руб.');
        $this->assertSelectorTextContains('body', 'ТСН "Ромашка"');
        $this->assertSelectorTextContains('body', '40703810900000000001');
        $this->assertSelectorTextContains('body', sprintf('Оплата по квитанции %s, участок 9-123', $snapshot->getNumber()));
        $this->assertSelectorExists('img[alt^="QR-код оплаты"]');
        $this->assertSelectorTextContains('body', 'Иванов И.И.');
        $this->assertSelectorTextContains('body', 'Расчет электроэнергии');
        $this->assertSelectorTextContains('body', 'Меркурий 201');
        $this->assertSelectorTextContains('body', 'SN-777');
        $this->assertSelectorTextContains('body', 'Однотарифная зона');
        $this->assertSelectorTextContains('body', '100,000 кВт⋅ч');
        $this->assertSelectorTextContains('body', '400,000 кВт⋅ч');
        $this->assertSelectorTextContains('body', 'Социальная норма');
        $this->assertSelectorTextContains('body', '300,000 кВт⋅ч');
        $this->assertSelectorTextContains('body', '5,000000 руб.');
        $this->assertSelectorExists(sprintf('a[href="/admin/accounts/%s/statements/%s/pdf"]', $account->getUuid(), $snapshot->getUuid()));
        $this->assertSelectorExists(sprintf('a[href="/admin/accounts/%s/statements/%s/print"]', $account->getUuid(), $snapshot->getUuid()));

        $client->request('GET', sprintf('/admin/accounts/%s/statements/%s/print', $account->getUuid(), $snapshot->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('.admin-sidebar');
        $this->assertSelectorExists('.statement-print-document');
        $this->assertSelectorTextContains('h1', 'Квитанция № '.$snapshot->getNumber());
        $this->assertSelectorTextContains('body', 'Сумма к оплате');
        $this->assertSelectorTextContains('body', 'Платежные реквизиты');
        $this->assertSelectorTextContains('body', 'ТСН "Ромашка"');
        $this->assertSelectorTextContains('body', '40703810900000000001');
        $this->assertSelectorExists('img[alt^="QR-код оплаты"]');
        $this->assertSelectorTextContains('body', 'Расчет электроэнергии');
        $this->assertSelectorTextContains('body', '300,000 кВт⋅ч');
        $this->assertSelectorTextContains('body', 'Печать');

        $client->request('GET', sprintf('/admin/accounts/%s/statements/%s/pdf', $account->getUuid(), $snapshot->getUuid()));

        $this->assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('content-type'));
        self::assertStringContainsString('inline;', (string) $client->getResponse()->headers->get('content-disposition'));
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());

        $accrual->cancel('Отмена после формирования snapshot');
        $payment->cancel('Отмена после формирования snapshot');
        $this->entityManager()->flush();

        $client->request('GET', sprintf('/admin/accounts/%s/statements/%s', $account->getUuid(), $snapshot->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '1 500,00 руб.');
        $this->assertSelectorTextContains('body', '500,00 руб.');
        $this->assertSelectorTextContains('body', '1 000,00 руб.');
        $this->assertSelectorTextContains('body', '300,000 кВт⋅ч');
    }

    public function testAdminCanQueueAccountStatementDelivery(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $subscriber->setContactEmail('owner@example.test');
        $this->createAccess($workspace, $subscriber, $account);
        $snapshot = new AccountStatementSnapshot(
            workspace: $workspace,
            account: $account,
            statementDate: new DateTimeImmutable('2026-05-13'),
            activeAccrualTotal: '1500.00',
            activePaymentTotal: '500.00',
            balanceAmount: '-1000.00',
            amountToPay: '1000.00',
            overpaymentAmount: '0.00',
            generatedBy: $admin,
        );
        $this->entityManager()->persist($snapshot);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/statements/%s', $account->getUuid(), $snapshot->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Доставка');
        $this->assertSelectorTextContains('body', 'Отправки пока не создавались.');
        $client->submitForm('Поставить в очередь отправки');

        $this->assertResponseRedirects(sprintf(
            '/admin/accounts/%s/statements/%s',
            $account->getUuid(),
            $snapshot->getUuid(),
        ), Response::HTTP_SEE_OTHER);

        $delivery = $this->entityManager()
            ->getRepository(AccountStatementDelivery::class)
            ->findOneBy(['accountStatement' => $snapshot]);

        self::assertInstanceOf(AccountStatementDelivery::class, $delivery);
        self::assertSame($workspace->getUuid()->toRfc4122(), $delivery->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame($snapshot->getUuid()->toRfc4122(), $delivery->getAccountStatement()?->getUuid()->toRfc4122());
        self::assertSame($subscriber->getUuid()->toRfc4122(), $delivery->getRecipientSubscriber()?->getUuid()->toRfc4122());
        self::assertSame('owner@example.test', $delivery->getRecipientEmail());
        self::assertSame('owner@example.test', $delivery->getRecipientEmailNormalized());
        self::assertSame('Иванов Иван Иванович', $delivery->getRecipientName());
        self::assertSame($admin->getUuid()->toRfc4122(), $delivery->getCreatedBy()?->getUuid()->toRfc4122());

        $attempt = $this->entityManager()
            ->getRepository(AccountStatementDeliveryAttempt::class)
            ->findOneBy(['delivery' => $delivery, 'attemptNumber' => 1]);

        self::assertInstanceOf(AccountStatementDeliveryAttempt::class, $attempt);
        self::assertSame('queued', $attempt->getStatusCode());
        self::assertSame($admin->getUuid()->toRfc4122(), $attempt->getQueuedBy()?->getUuid()->toRfc4122());

        $accountUuid = $account->getUuid();
        $snapshotUuid = $snapshot->getUuid();
        $deliveryUuid = $delivery->getUuid();

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'В очередь отправки поставлено: 1.');
        $this->assertSelectorTextContains('body', 'owner@example.test');
        $this->assertSelectorTextContains('body', 'В очереди');
        $this->assertSelectorTextContains('body', '№ 1');

        $application = new Application(static::getContainer()->get('kernel'));
        $commandTester = new CommandTester($application->find('app:account-statement-deliveries:send'));
        $commandTester->execute(['--limit' => 5]);
        $commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('Sent: 1. Failed: 0.', $commandTester->getDisplay());

        $this->entityManager()->clear();
        $delivery = $this->entityManager()
            ->getRepository(AccountStatementDelivery::class)
            ->find($deliveryUuid);
        $attempt = $this->entityManager()
            ->getRepository(AccountStatementDeliveryAttempt::class)
            ->findOneBy(['delivery' => $delivery, 'attemptNumber' => 1]);

        self::assertInstanceOf(AccountStatementDeliveryAttempt::class, $attempt);
        self::assertSame('sent', $attempt->getStatusCode());
        self::assertNotNull($attempt->getStartedAt());
        self::assertNotNull($attempt->getSucceededAt());
        self::assertNull($attempt->getFailedAt());

        $client->request('GET', sprintf('/admin/accounts/%s/statements/%s', $accountUuid, $snapshotUuid));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Отправлено');

        $client->submitForm('Поставить в очередь отправки');
        $this->assertResponseRedirects(sprintf(
            '/admin/accounts/%s/statements/%s',
            $accountUuid,
            $snapshotUuid,
        ), Response::HTTP_SEE_OTHER);
        self::assertSame(1, $this->countStatementDeliveries($snapshot));

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Уже есть активные отправки: 1.');
    }

    public function testAdminCanCancelAccountStatementAndActiveDeliveries(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $snapshot = new AccountStatementSnapshot(
            workspace: $workspace,
            account: $account,
            statementDate: new DateTimeImmutable('2026-05-13'),
            activeAccrualTotal: '1500.00',
            activePaymentTotal: '500.00',
            balanceAmount: '-1000.00',
            amountToPay: '1000.00',
            overpaymentAmount: '0.00',
            generatedBy: $admin,
        );
        $queuedDelivery = new AccountStatementDelivery(
            workspace: $workspace,
            accountStatement: $snapshot,
            recipientEmail: 'queued@example.test',
            recipientName: 'Иванов Иван Иванович',
            createdBy: $admin,
        );
        $queuedAttempt = new AccountStatementDeliveryAttempt(
            workspace: $workspace,
            delivery: $queuedDelivery,
            queuedBy: $admin,
        );
        $queuedDelivery->addAttempt($queuedAttempt);
        $sentDelivery = new AccountStatementDelivery(
            workspace: $workspace,
            accountStatement: $snapshot,
            recipientEmail: 'sent@example.test',
            recipientName: 'Петров Петр Петрович',
            createdBy: $admin,
        );
        $sentAttempt = new AccountStatementDeliveryAttempt(
            workspace: $workspace,
            delivery: $sentDelivery,
            queuedBy: $admin,
        );
        $sentAttempt->markSucceeded('smtp-message-1');
        $sentDelivery->addAttempt($sentAttempt);

        $this->entityManager()->persist($snapshot);
        $this->entityManager()->persist($queuedDelivery);
        $this->entityManager()->persist($queuedAttempt);
        $this->entityManager()->persist($sentDelivery);
        $this->entityManager()->persist($sentAttempt);
        $this->entityManager()->flush();

        $snapshotUuid = $snapshot->getUuid();
        $queuedDeliveryUuid = $queuedDelivery->getUuid();
        $sentDeliveryUuid = $sentDelivery->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/statements/%s', $account->getUuid(), $snapshotUuid));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Отменить квитанцию');
        $this->assertSelectorTextContains('body', 'queued@example.test');
        $this->assertSelectorTextContains('body', 'sent@example.test');

        $client->submitForm('Отменить', [
            'account_statement_cancel[reason]' => 'Ошибочная сумма к оплате',
        ]);

        $this->assertResponseRedirects(sprintf(
            '/admin/accounts/%s/statements/%s',
            $account->getUuid(),
            $snapshotUuid,
        ), Response::HTTP_SEE_OTHER);

        $this->entityManager()->clear();
        $cancelledStatement = $this->entityManager()
            ->getRepository(AccountStatementSnapshot::class)
            ->find($snapshotUuid);
        $cancelledQueuedDelivery = $this->entityManager()
            ->getRepository(AccountStatementDelivery::class)
            ->find($queuedDeliveryUuid);
        $cancelledSentDelivery = $this->entityManager()
            ->getRepository(AccountStatementDelivery::class)
            ->find($sentDeliveryUuid);

        self::assertInstanceOf(AccountStatementSnapshot::class, $cancelledStatement);
        self::assertInstanceOf(AccountStatementDelivery::class, $cancelledQueuedDelivery);
        self::assertInstanceOf(AccountStatementDelivery::class, $cancelledSentDelivery);
        self::assertNotNull($cancelledStatement->getCancelledAt());
        self::assertSame('Ошибочная сумма к оплате', $cancelledStatement->getCancellationReason());
        self::assertSame($admin->getUuid()->toRfc4122(), $cancelledStatement->getCancelledBy()?->getUuid()->toRfc4122());
        self::assertNotNull($cancelledQueuedDelivery->getCancelledAt());
        self::assertSame('Ошибочная сумма к оплате', $cancelledQueuedDelivery->getCancellationReason());
        self::assertNotNull($cancelledSentDelivery->getCancelledAt());
        self::assertSame('Ошибочная сумма к оплате', $cancelledSentDelivery->getCancellationReason());
        self::assertSame('queued', $cancelledQueuedDelivery->getLatestAttempt()?->getStatusCode());
        self::assertSame('sent', $cancelledSentDelivery->getLatestAttempt()?->getStatusCode());
        self::assertSame([], $this->entityManager()->getRepository(AccountStatementDeliveryAttempt::class)->findQueued(10));

        $statementAuditLog = $this->entityManager()
            ->getRepository(AuditLog::class)
            ->findOneBy(['action' => 'account_statement.cancelled', 'entityUuid' => $snapshotUuid]);

        self::assertInstanceOf(AuditLog::class, $statementAuditLog);
        self::assertSame('Ошибочная сумма к оплате', $statementAuditLog->getReason());
        self::assertSame(['cancelled_at', 'cancelled_by', 'cancellation_reason'], $statementAuditLog->getChangedFields());
        self::assertSame(2, $this->countAuditLogs('account_statement_delivery.cancelled'));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Квитанция отменена');
        $this->assertSelectorTextContains('body', 'Ошибочная сумма к оплате');
        $this->assertSelectorTextContains('body', 'Отменена');
        $this->assertSelectorTextContains('body', 'Отменена после отправки');
        $this->assertSelectorTextNotContains('body', 'Поставить в очередь отправки');
    }

    public function testAdminCanGrantAccountAccessToSubscriber(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select.js-searchable-select[name="account_subscriber_access_grant[subscriber]"]');

        $client->submitForm('Добавить абонента', [
            'account_subscriber_access_grant[subscriber]' => $subscriber->getUuid()->toRfc4122(),
            'account_subscriber_access_grant[accessRole]' => SubscriberAccountAccessRole::Representative->value,
            'account_subscriber_access_grant[notes]' => 'Доступ оформлен с карточки участка',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/accounts/%s', $account->getUuid()), Response::HTTP_SEE_OTHER);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');
        $this->assertSelectorTextContains('body', 'Представитель');
        $this->assertSelectorExists(sprintf('a[href="/admin/subscribers/%s"]', $subscriber->getUuid()->toRfc4122()));

        $access = $this->findActiveAccess($subscriber, $account);

        self::assertInstanceOf(SubscriberAccountAccess::class, $access);
        self::assertSame($workspace->getUuid()->toRfc4122(), $access->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame(SubscriberAccountAccessRole::Representative, $access->getAccessRole());
        self::assertSame('Доступ оформлен с карточки участка', $access->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $access->getGrantedBy()?->getUuid()->toRfc4122());
        self::assertNull($access->getRevokedAt());
    }

    public function testAdminCannotGrantDuplicateActiveAccessFromAccountCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $this->createSubscriber($workspace, 'Петров', 'Петр', 'Петрович');
        $this->createAccess($workspace, $subscriber, $account);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));
        $token = $crawler->filter('#account_subscriber_access_grant__token')->attr('value');

        $client->request('POST', sprintf('/admin/accounts/%s/accesses/grant', $account->getUuid()), [
            'account_subscriber_access_grant' => [
                'subscriber' => $subscriber->getUuid()->toRfc4122(),
                'accessRole' => SubscriberAccountAccessRole::Owner->value,
                'notes' => '',
                '_token' => $token,
            ],
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(1, $this->countActiveAccesses($subscriber, $account));
    }

    public function testAdminCanRevokeSubscriberAccessFromAccountCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $this->createAccess($workspace, $subscriber, $account);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Отозвать');
        $this->assertResponseRedirects(sprintf('/admin/accounts/%s', $account->getUuid()), Response::HTTP_SEE_OTHER);

        $access = $this->findAnyAccess($subscriber, $account);

        self::assertInstanceOf(SubscriberAccountAccess::class, $access);
        self::assertNotNull($access->getRevokedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $access->getRevokedBy()?->getUuid()->toRfc4122());
        self::assertSame(0, $this->countActiveAccesses($subscriber, $account));

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Абоненты к участку пока не привязаны.');
    }

    public function testAdminCanAssignTariffProfileFromAccountCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select.js-searchable-select[name="account_electricity_tariff_profile_assign[tariffProfile]"]');
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="account_electricity_tariff_profile_assign[validFrom]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Назначить профиль', [
            'account_electricity_tariff_profile_assign[tariffProfile]' => $tariffProfile->getUuid()->toRfc4122(),
            'account_electricity_tariff_profile_assign[validFrom]' => '01.05.2026',
            'account_electricity_tariff_profile_assign[notes]' => 'Основной тариф для СНТ',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/accounts/%s', $account->getUuid()), Response::HTTP_SEE_OTHER);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'СНТ');
        $this->assertSelectorTextContains('body', 'snt');
        $this->assertSelectorTextContains('body', 'Основной тариф для СНТ');

        $assignment = $this->findOpenTariffProfileAssignment($account);

        self::assertInstanceOf(AccountElectricityTariffProfileAssignment::class, $assignment);
        self::assertSame($workspace->getUuid()->toRfc4122(), $assignment->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame($tariffProfile->getUuid()->toRfc4122(), $assignment->getTariffProfile()?->getUuid()->toRfc4122());
        self::assertSame('2026-05-01', $assignment->getValidFrom()->format('Y-m-d'));
        self::assertNull($assignment->getValidTo());
        self::assertSame('Основной тариф для СНТ', $assignment->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $assignment->getAssignedBy()?->getUuid()->toRfc4122());
    }

    public function testAdminCanReplaceOpenEndedTariffProfileAssignment(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $oldProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $newProfile = $this->createTariffProfile($workspace, 'electric_heating', 'Электроотопление');
        $this->createTariffProfileAssignment($workspace, $account, $oldProfile, '2026-05-01');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));
        $client->submitForm('Назначить профиль', [
            'account_electricity_tariff_profile_assign[tariffProfile]' => $newProfile->getUuid()->toRfc4122(),
            'account_electricity_tariff_profile_assign[validFrom]' => '01.06.2026',
            'account_electricity_tariff_profile_assign[notes]' => '',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/accounts/%s', $account->getUuid()), Response::HTTP_SEE_OTHER);

        $oldAssignment = $this->findTariffProfileAssignmentByValidFrom($account, '2026-05-01');
        $newAssignment = $this->findTariffProfileAssignmentByValidFrom($account, '2026-06-01');

        self::assertInstanceOf(AccountElectricityTariffProfileAssignment::class, $oldAssignment);
        self::assertInstanceOf(AccountElectricityTariffProfileAssignment::class, $newAssignment);
        self::assertSame('2026-06-01', $oldAssignment->getValidTo()?->format('Y-m-d'));
        self::assertSame($newProfile->getUuid()->toRfc4122(), $newAssignment->getTariffProfile()?->getUuid()->toRfc4122());
        self::assertNull($newAssignment->getValidTo());

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Электроотопление');
        $this->assertSelectorTextContains('body', '01.06.2026');
    }

    public function testAdminCannotAssignSameOpenEndedTariffProfile(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffProfileAssignment($workspace, $account, $tariffProfile, '2026-05-01');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));
        $client->submitForm('Назначить профиль', [
            'account_electricity_tariff_profile_assign[tariffProfile]' => $tariffProfile->getUuid()->toRfc4122(),
            'account_electricity_tariff_profile_assign[validFrom]' => '01.06.2026',
            'account_electricity_tariff_profile_assign[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Этот тарифный профиль уже назначен участку.');
        self::assertSame(1, $this->countTariffProfileAssignments($account));
    }

    private function createAccount(Workspace $workspace, string $number, ?string $notes = null): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number)
            ->setNotes($notes);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createSubscriber(Workspace $workspace, string $lastName, string $firstName, ?string $secondName = null): Subscriber
    {
        $subscriber = (new Subscriber($workspace))
            ->setLastName($lastName)
            ->setFirstName($firstName)
            ->setSecondName($secondName);

        $this->entityManager()->persist($subscriber);
        $this->entityManager()->flush();

        return $subscriber;
    }

    private function createAccess(Workspace $workspace, Subscriber $subscriber, Account $account): SubscriberAccountAccess
    {
        $access = new SubscriberAccountAccess($workspace, $subscriber, $account);

        $this->entityManager()->persist($access);
        $this->entityManager()->flush();

        return $access;
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

    private function createTariffProfileAssignment(Workspace $workspace, Account $account, ElectricityTariffProfile $tariffProfile, string $validFrom): AccountElectricityTariffProfileAssignment
    {
        $assignment = new AccountElectricityTariffProfileAssignment($workspace, $account, $tariffProfile, new DateTimeImmutable($validFrom));

        $this->entityManager()->persist($assignment);
        $this->entityManager()->flush();

        return $assignment;
    }

    private function createPostedAccrual(
        Workspace $workspace,
        Account $account,
        string $amount,
        string $periodStart = '2026-05-01',
    ): Accrual {
        $periodStartDate = new DateTimeImmutable($periodStart);
        $accrual = new Accrual(
            $workspace,
            $account,
            AccrualType::Electricity,
            $periodStartDate,
            $periodStartDate->modify('+1 month'),
            $amount,
        );
        $accrual->post();

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }

    private function createPayment(
        Workspace $workspace,
        Account $account,
        string $amount,
        string $paidOn = '2026-05-09',
    ): Payment {
        $payment = new Payment(
            $workspace,
            $account,
            $amount,
            new DateTimeImmutable($paidOn),
            PaymentSource::Manual,
        );
        $payment
            ->setPayerName('Иванов И.И.')
            ->setPurpose('Оплата света');

        $this->entityManager()->persist($payment);
        $this->entityManager()->flush();

        return $payment;
    }

    private function createDefaultPaymentRequisiteProfile(Workspace $workspace, ?User $assignedBy = null): PaymentRequisiteProfile
    {
        $profile = (new PaymentRequisiteProfile($workspace, new DateTimeImmutable('2026-01-01')))
            ->setCode('main')
            ->setName('Основные реквизиты')
            ->setRecipientName('ТСН "Ромашка"')
            ->setRecipientInn('1234567890')
            ->setRecipientKpp('123456789')
            ->setBankName('ПАО Сбербанк')
            ->setBankBik('044525225')
            ->setBankCorrespondentAccount('30101810400000000225')
            ->setBankAccount('40703810900000000001')
            ->setPaymentPurposeTemplate('Оплата по квитанции {statement_number}, участок {account_number}');
        $assignment = new PaymentRequisiteAssignment($workspace, $profile, null, new DateTimeImmutable('2026-01-01'), $assignedBy);

        $this->entityManager()->persist($profile);
        $this->entityManager()->persist($assignment);
        $this->entityManager()->flush();

        return $profile;
    }

    private function createElectricityAccrualDetails(Workspace $workspace, Account $account, Accrual $accrual): void
    {
        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode('single')
            ->setName('Однотарифная зона');
        $meter = (new ElectricityMeter($workspace, $account, new DateTimeImmutable('2026-05-01')))
            ->setSerialNumber('SN-777')
            ->setModel('Меркурий 201');
        $previousReading = new ElectricityMeterReading(
            $workspace,
            $meter,
            $tariffZone,
            '100.000',
            new DateTimeImmutable('2026-05-01'),
            ElectricityMeterReadingSource::Admin,
        );
        $currentReading = new ElectricityMeterReading(
            $workspace,
            $meter,
            $tariffZone,
            '400.000',
            new DateTimeImmutable('2026-06-04'),
            ElectricityMeterReadingSource::Admin,
        );
        $consumptionBand = (new ElectricityConsumptionBand($workspace))
            ->setCode('social_norm')
            ->setName('Социальная норма');

        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->persist($meter);
        $this->entityManager()->persist(new ElectricityMeterRegister($workspace, $meter, $tariffZone));
        $this->entityManager()->persist($previousReading);
        $this->entityManager()->persist($currentReading);
        $this->entityManager()->persist($consumptionBand);
        $this->entityManager()->persist(new ElectricityAccrualRegister(
            $workspace,
            $accrual,
            $meter,
            $tariffZone,
            $currentReading,
            $previousReading,
        ));
        $this->entityManager()->persist(new ElectricityAccrualLine(
            $workspace,
            $accrual,
            $tariffZone,
            $consumptionBand,
            '300.000',
            '5.000000',
            '1500.00',
        ));
        $this->entityManager()->flush();
    }

    private function findAccountByUuid(Uuid $uuid): ?Account
    {
        return $this->entityManager()
            ->getRepository(Account::class)
            ->find($uuid);
    }

    private function findAccountByNumber(string $number): ?Account
    {
        return $this->entityManager()
            ->getRepository(Account::class)
            ->findOneBy(['number' => $number]);
    }

    private function countAccountsByNumber(string $number): int
    {
        return (int) $this->entityManager()
            ->getRepository(Account::class)
            ->createQueryBuilder('account')
            ->select('COUNT(account.uuid)')
            ->andWhere('account.number = :number')
            ->setParameter('number', $number)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function findActiveAccess(Subscriber $subscriber, Account $account): ?SubscriberAccountAccess
    {
        return $this->entityManager()
            ->getRepository(SubscriberAccountAccess::class)
            ->findOneBy([
                'subscriber' => $subscriber,
                'account' => $account,
                'revokedAt' => null,
            ]);
    }

    private function findAnyAccess(Subscriber $subscriber, Account $account): ?SubscriberAccountAccess
    {
        return $this->entityManager()
            ->getRepository(SubscriberAccountAccess::class)
            ->findOneBy([
                'subscriber' => $subscriber,
                'account' => $account,
            ]);
    }

    private function countActiveAccesses(Subscriber $subscriber, Account $account): int
    {
        return (int) $this->entityManager()
            ->getRepository(SubscriberAccountAccess::class)
            ->createQueryBuilder('access')
            ->select('COUNT(access.grantedAt)')
            ->andWhere('access.subscriber = :subscriber')
            ->andWhere('access.account = :account')
            ->andWhere('access.revokedAt IS NULL')
            ->setParameter('subscriber', $subscriber)
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function findOpenTariffProfileAssignment(Account $account): ?AccountElectricityTariffProfileAssignment
    {
        return $this->entityManager()
            ->getRepository(AccountElectricityTariffProfileAssignment::class)
            ->findOneBy([
                'account' => $account,
                'validTo' => null,
            ]);
    }

    private function findTariffProfileAssignmentByValidFrom(Account $account, string $validFrom): ?AccountElectricityTariffProfileAssignment
    {
        return $this->entityManager()
            ->getRepository(AccountElectricityTariffProfileAssignment::class)
            ->findOneBy([
                'account' => $account,
                'validFrom' => $validFrom,
            ]);
    }

    private function countTariffProfileAssignments(Account $account): int
    {
        return (int) $this->entityManager()
            ->getRepository(AccountElectricityTariffProfileAssignment::class)
            ->createQueryBuilder('assignment')
            ->select('COUNT(assignment.validFrom)')
            ->andWhere('assignment.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countStatementDeliveries(AccountStatementSnapshot $snapshot): int
    {
        return (int) $this->entityManager()
            ->getRepository(AccountStatementDelivery::class)
            ->createQueryBuilder('delivery')
            ->select('COUNT(delivery.uuid)')
            ->andWhere('delivery.accountStatement = :snapshot')
            ->setParameter('snapshot', $snapshot)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countAuditLogs(string $action): int
    {
        return (int) $this->entityManager()
            ->getRepository(AuditLog::class)
            ->createQueryBuilder('auditLog')
            ->select('COUNT(auditLog.uuid)')
            ->andWhere('auditLog.action = :action')
            ->setParameter('action', $action)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
