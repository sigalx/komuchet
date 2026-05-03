<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Accrual;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffZone;
use App\Entity\Payment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\UserEmailIdentity;
use App\Entity\UserPasswordCredential;
use App\Enum\AccrualType;
use App\Enum\ElectricityMeterReadingSource;
use App\Enum\PaymentSource;
use App\Enum\SubscriberAccountAccessRole;
use App\Form\SubscriberElectricityMeterReadingType;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriberPortalControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/portal');

        $this->assertResponseRedirects('/login');
    }

    public function testUserWithoutSubscriberAccessIsDenied(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $client->loginUser($user);

        $client->request('GET', '/portal');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testSubscriberCanCompletePortalFlowThroughLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createLoginUser('subscriber-smoke@example.test');
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $this->createPostedAccrual($workspace, $account, '1200.00');
        $this->createPayment($workspace, $account, '200.00');
        $meter = $this->createElectricityMeter($workspace, $account);
        $tariffZone = $this->createTariffZone($workspace);
        $this->createReading($workspace, $meter, $tariffZone, '345.678');
        $fieldName = SubscriberElectricityMeterReadingType::readingFieldName($tariffZone);

        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Вход');

        $client->submitForm('Войти', [
            '_email' => 'subscriber-smoke@example.test',
            '_password' => 'test-password-123',
        ]);

        $this->assertResponseRedirects('/', Response::HTTP_FOUND);

        $client->followRedirect();

        $this->assertResponseRedirects('/portal', Response::HTTP_FOUND);

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Личный кабинет');
        $this->assertSelectorTextContains('body', 'Основное хозяйство');
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', '1 000,00 руб.');
        $this->assertSelectorTextContains('body', 'SN-001');
        $this->assertSelectorExists(sprintf('a[href="/portal/accounts/%s"]', $account->getUuid()->toRfc4122()));

        $client->request('GET', sprintf('/portal/accounts/%s', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Участок 9-123');
        $this->assertSelectorTextContains('body', 'Баланс и операции');
        $this->assertSelectorTextContains('body', 'Есть задолженность 1 000,00 руб., но квитанция для оплаты еще не сформирована.');
        $this->assertSelectorTextContains('body', 'Передать показания');
        $this->assertSelectorTextContains('body', '345,678');
        $this->assertSelectorTextContains('body', 'Оплата света май 2026');

        $client->request('GET', sprintf('/portal/accounts/%s/balance', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Баланс и операции по участку 9-123');
        $this->assertSelectorTextContains('body', 'Сумма к оплате');
        $this->assertSelectorTextContains('body', '1 000,00 руб.');
        $this->assertSelectorTextContains('body', '1 200,00 руб.');
        $this->assertSelectorTextContains('body', '200,00 руб.');

        $client->request('GET', sprintf('/portal/accounts/%s/readings/new', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Передать показания');
        $this->assertSelectorTextContains('body', 'Последнее: 345,678 кВт⋅ч от 10.05.2026.');

        $client->submitForm('Сохранить', [
            'subscriber_electricity_meter_reading[takenOn]' => '14.05.2026',
            sprintf('subscriber_electricity_meter_reading[%s]', $fieldName) => '456,789',
            'subscriber_electricity_meter_reading[notes]' => 'Smoke-показание абонента',
        ]);

        $newReading = $this->findReadingByMeterZoneAndValue($meter, $tariffZone, '456.789');

        self::assertInstanceOf(ElectricityMeterReading::class, $newReading);
        $this->assertResponseRedirects(sprintf('/portal/accounts/%s', $account->getUuid()->toRfc4122()), Response::HTTP_SEE_OTHER);
        self::assertSame(ElectricityMeterReadingSource::Subscriber, $newReading->getSource());
        self::assertSame($user->getUuid()->toRfc4122(), $newReading->getSubmittedBy()?->getUuid()->toRfc4122());
        self::assertSame($subscriber->getUuid()->toRfc4122(), $newReading->getProvidedBySubscriber()?->getUuid()->toRfc4122());
        self::assertSame('Smoke-показание абонента', $newReading->getNotes());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показания переданы.');
        $this->assertSelectorTextContains('body', '456,789');
        $this->assertSelectorTextContains('body', '345,678');
        self::assertSame(2, $this->countReadings($meter));
    }

    public function testSubscriberCanOpenPortalDashboard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $this->createPostedAccrual($workspace, $account, '1200.00');
        $this->createPayment($workspace, $account, '200.00');
        $meter = $this->createElectricityMeter($workspace, $account);
        $tariffZone = $this->createTariffZone($workspace);
        $this->createReading($workspace, $meter, $tariffZone, '345.678');
        $client->loginUser($user);

        $client->request('GET', '/portal');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Личный кабинет');
        $this->assertSelectorTextContains('body', 'Основное хозяйство');
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', 'Владелец');
        $this->assertSelectorTextContains('body', '1 000,00 руб.');
        $this->assertSelectorTextContains('body', 'SN-001');
        $this->assertSelectorNotExists('#portal-current-workspace');
        $this->assertSelectorTextNotContains('[data-current-workspace-summary]', 'Текущее хозяйство');
        $this->assertSelectorTextContains('[data-current-workspace-summary]', 'Основное хозяйство');
        $this->assertSelectorNotExists('a[href="/portal/accounts"]');
        $this->assertSelectorExists('a[href="/profile"]');
        $this->assertSelectorExists('a[href="/logout"]');
        $this->assertSelectorExists(sprintf('a[href="/portal/accounts/%s/readings/new"]', $account->getUuid()->toRfc4122()));
    }

    public function testPureSubscriberIsRedirectedFromRootDashboardToPortal(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $client->loginUser($user);

        $client->request('GET', '/');

        $this->assertResponseRedirects('/portal', Response::HTTP_FOUND);
    }

    public function testPortalAccountListRouteDoesNotExist(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/portal/accounts');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSubscriberCanOpenAccessibleAccountCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $this->createPostedAccrual($workspace, $account, '1200.00');
        $this->createPayment($workspace, $account, '200.00');
        $meter = $this->createElectricityMeter($workspace, $account);
        $tariffZone = $this->createTariffZone($workspace);
        $this->createReading($workspace, $meter, $tariffZone, '345.678');
        $client->loginUser($user);

        $client->request('GET', sprintf('/portal/accounts/%s', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Участок 9-123');
        $this->assertSelectorTextContains('body', 'Начислено');
        $this->assertSelectorTextContains('body', '1 200,00 руб.');
        $this->assertSelectorTextContains('body', 'Оплачено');
        $this->assertSelectorTextContains('body', '200,00 руб.');
        $this->assertSelectorTextContains('body', 'Баланс');
        $this->assertSelectorTextContains('body', '-1 000,00 руб.');
        $this->assertSelectorTextContains('body', 'Есть задолженность 1 000,00 руб., но квитанция для оплаты еще не сформирована.');
        $this->assertSelectorTextContains('body', 'Активный электросчетчик');
        $this->assertSelectorTextContains('body', 'SN-001');
        $this->assertSelectorTextContains('body', 'Меркурий 201');
        $this->assertSelectorTextContains('body', '01.05.2026');
        $this->assertSelectorTextContains('body', 'Показания электросчетчиков');
        $this->assertSelectorTextContains('body', 'Однотарифная зона');
        $this->assertSelectorTextContains('body', '345,678');
        $this->assertSelectorTextContains('body', 'Начисления');
        $this->assertSelectorTextContains('body', 'Электроэнергия');
        $this->assertSelectorTextContains('body', 'Оплата света май 2026');
        $this->assertSelectorTextContains('body', 'Оплаты');
        $this->assertSelectorTextContains('body', 'Иванов И.И.');
        $this->assertSelectorTextContains('body', 'Ручной ввод');
        $this->assertSelectorExists(sprintf('a[href="/portal/accounts/%s/balance"]', $account->getUuid()->toRfc4122()));
    }

    public function testSubscriberCanOpenAccountBalanceAndOperations(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $this->createPostedAccrual($workspace, $account, '1200.00');
        $cancelledAccrual = $this->createPostedAccrual($workspace, $account, '9999.00', '2026-04-01');
        $cancelledAccrual->cancel('Не участвует в квитанции');
        $this->createPayment($workspace, $account, '200.00');
        $cancelledPayment = $this->createPayment($workspace, $account, '8888.00', '2026-04-09');
        $cancelledPayment->cancel('Не участвует в квитанции');
        $this->entityManager()->flush();
        $client->loginUser($user);

        $client->request('GET', sprintf('/portal/accounts/%s/balance', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Баланс и операции по участку 9-123');
        $this->assertSelectorTextContains('body', 'Основное хозяйство');
        $this->assertSelectorTextContains('body', 'Сумма к оплате');
        $this->assertSelectorTextContains('body', '1 000,00 руб.');
        $this->assertSelectorTextContains('body', '1 200,00 руб.');
        $this->assertSelectorTextContains('body', '200,00 руб.');
        $this->assertSelectorTextNotContains('body', '9 999,00 руб.');
        $this->assertSelectorTextNotContains('body', '8 888,00 руб.');
        $this->assertSelectorExists(sprintf('a[href="/portal/accounts/%s"]', $account->getUuid()->toRfc4122()));
    }

    public function testSubscriberCanOpenActiveStatementSnapshotAndPdf(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $profile = $this->createPaymentRequisiteProfile($workspace);
        $snapshot = $this->createStatementSnapshot($workspace, $account, $profile);
        $client->loginUser($user);

        $client->request('GET', sprintf('/portal/accounts/%s', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#statements', 'Квитанции');
        $this->assertSelectorTextContains('#statements', $snapshot->getNumber());
        $this->assertSelectorTextContains('#statements', '1 000,00 руб.');
        $this->assertSelectorExists(sprintf(
            'a[href="/portal/accounts/%s/statements/%s"]',
            $account->getUuid()->toRfc4122(),
            $snapshot->getUuid()->toRfc4122(),
        ));
        $this->assertSelectorExists(sprintf(
            'a[href="/portal/accounts/%s/statements/%s/pdf"]',
            $account->getUuid()->toRfc4122(),
            $snapshot->getUuid()->toRfc4122(),
        ));

        $client->request('GET', sprintf(
            '/portal/accounts/%s/statements/%s',
            $account->getUuid()->toRfc4122(),
            $snapshot->getUuid()->toRfc4122(),
        ));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Квитанция № '.$snapshot->getNumber());
        $this->assertSelectorTextContains('body', 'QR-код сформирован из сохраненных реквизитов квитанции.');
        $this->assertSelectorExists(sprintf('a[href="/portal/accounts/%s"]', $account->getUuid()->toRfc4122()));

        $client->request('GET', sprintf(
            '/portal/accounts/%s/statements/%s/pdf',
            $account->getUuid()->toRfc4122(),
            $snapshot->getUuid()->toRfc4122(),
        ));

        $this->assertResponseIsSuccessful();
        self::assertStringStartsWith('application/pdf', (string) $client->getResponse()->headers->get('Content-Type'));
    }

    public function testSubscriberCannotOpenStatementSnapshotFromAnotherAccount(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $accessibleAccount = $this->createAccount($workspace, '9-123');
        $forbiddenAccount = $this->createAccount($workspace, '9-124');
        $this->createAccess($workspace, $subscriber, $accessibleAccount);
        $profile = $this->createPaymentRequisiteProfile($workspace);
        $snapshot = $this->createStatementSnapshot($workspace, $forbiddenAccount, $profile);
        $client->loginUser($user);

        $client->request('GET', sprintf(
            '/portal/accounts/%s/statements/%s',
            $accessibleAccount->getUuid()->toRfc4122(),
            $snapshot->getUuid()->toRfc4122(),
        ));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSubscriberLegacyAccountStatementRouteDoesNotExist(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $client->loginUser($user);

        $client->request('GET', sprintf('/portal/accounts/%s/statement', $account->getUuid()->toRfc4122()));

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testSubscriberAccountHistoriesArePaginatedAndFilterableByDate(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $meter = $this->createElectricityMeter($workspace, $account);
        $tariffZone = $this->createTariffZone($workspace);

        for ($day = 1; $day <= 12; ++$day) {
            $date = sprintf('2026-05-%02d', $day);
            $this->createReading($workspace, $meter, $tariffZone, (string) (900 + $day), $date);
            $this->createPostedAccrual($workspace, $account, sprintf('%d.00', 1000 + $day), $date);
            $this->createPayment($workspace, $account, sprintf('%d.00', 200 + $day), $date);
        }
        $client->loginUser($user);

        $client->request('GET', sprintf('/portal/accounts/%s', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#readings', 'Показано 1-10 из 12');
        $this->assertSelectorTextContains('#readings', '912,000');
        $this->assertSelectorTextNotContains('#readings', '901,000');
        $this->assertSelectorExists('#readings a[href*="readings_page=2"]');
        $this->assertSelectorTextContains('#accruals', 'Показано 1-10 из 12');
        $this->assertSelectorTextContains('#accruals', '1 012,00 руб.');
        $this->assertSelectorTextNotContains('#accruals', '1 001,00 руб.');
        $this->assertSelectorExists('#accruals a[href*="accruals_page=2"]');
        $this->assertSelectorTextContains('#payments', 'Показано 1-10 из 12');
        $this->assertSelectorTextContains('#payments', '212,00 руб.');
        $this->assertSelectorTextNotContains('#payments', '201,00 руб.');
        $this->assertSelectorExists('#payments a[href*="payments_page=2"]');

        $client->request('GET', sprintf('/portal/accounts/%s?readings_page=2&accruals_page=2&payments_page=2', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#readings', 'Показано 11-12 из 12');
        $this->assertSelectorTextContains('#readings', '901,000');
        $this->assertSelectorTextNotContains('#readings', '912,000');
        $this->assertSelectorTextContains('#accruals', '1 001,00 руб.');
        $this->assertSelectorTextNotContains('#accruals', '1 012,00 руб.');
        $this->assertSelectorTextContains('#payments', '201,00 руб.');
        $this->assertSelectorTextNotContains('#payments', '212,00 руб.');

        $client->request('GET', sprintf('/portal/accounts/%s?readings_taken_on_from=10.05.2026&accruals_period_start_from=10.05.2026&payments_paid_on_from=10.05.2026', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#readings', '912,000');
        $this->assertSelectorTextContains('#readings', '910,000');
        $this->assertSelectorTextNotContains('#readings', '909,000');
        $this->assertSelectorTextContains('#accruals', '1 012,00 руб.');
        $this->assertSelectorTextNotContains('#accruals', '1 009,00 руб.');
        $this->assertSelectorTextContains('#payments', '212,00 руб.');
        $this->assertSelectorTextNotContains('#payments', '209,00 руб.');
    }

    public function testSubscriberAccountHistoryStatusFiltersAreApplied(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $meter = $this->createElectricityMeter($workspace, $account);
        $tariffZone = $this->createTariffZone($workspace);
        $this->createReading($workspace, $meter, $tariffZone, '100', '2026-05-10');
        $cancelledReading = $this->createReading($workspace, $meter, $tariffZone, '200', '2026-05-11');
        $activeAccrual = $this->createPostedAccrual($workspace, $account, '1000.00', '2026-05-10');
        $cancelledAccrual = $this->createPostedAccrual($workspace, $account, '2000.00', '2026-05-11');
        $activePayment = $this->createPayment($workspace, $account, '300.00', '2026-05-10');
        $cancelledPayment = $this->createPayment($workspace, $account, '400.00', '2026-05-11');

        $cancelledReading->cancel('Ошибка');
        $cancelledAccrual->cancel('Ошибка');
        $cancelledPayment->cancel('Ошибка');
        $this->entityManager()->flush();
        $client->loginUser($user);

        $client->request('GET', sprintf('/portal/accounts/%s?readings_status=cancelled&accruals_status=cancelled&payments_status=cancelled', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#readings', '200,000');
        $this->assertSelectorTextContains('#readings', 'Отменено');
        $this->assertSelectorTextNotContains('#readings', '100,000');
        $this->assertSelectorTextContains('#accruals', '2 000,00 руб.');
        $this->assertSelectorTextContains('#accruals', 'Отменено');
        $this->assertSelectorTextNotContains('#accruals', '1 000,00 руб.');
        $this->assertSelectorTextContains('#payments', '400,00 руб.');
        $this->assertSelectorTextContains('#payments', 'Отменено');
        $this->assertSelectorTextNotContains('#payments', '300,00 руб.');

        self::assertTrue($activeAccrual->isActivePosted());
        self::assertTrue($activePayment->isActive());
    }

    public function testSubscriberCannotOpenAccountWithoutAccess(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $accessibleAccount = $this->createAccount($workspace, '9-123');
        $forbiddenAccount = $this->createAccount($workspace, '9-124');
        $this->createAccess($workspace, $subscriber, $accessibleAccount);
        $client->loginUser($user);

        $client->request('GET', sprintf('/portal/accounts/%s', $forbiddenAccount->getUuid()->toRfc4122()));

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testSubscriberCanSubmitReadingsForAccountActiveMeter(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $meter = $this->createElectricityMeter($workspace, $account);
        $tariffZone = $this->createTariffZone($workspace);
        $this->createMeterRegister($workspace, $meter, $tariffZone);
        $fieldName = SubscriberElectricityMeterReadingType::readingFieldName($tariffZone);
        $client->loginUser($user);

        $client->request('GET', sprintf('/portal/accounts/%s/readings/new', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Передать показания');
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="subscriber_electricity_meter_reading[takenOn]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Сохранить', [
            'subscriber_electricity_meter_reading[takenOn]' => '10.05.2026',
            sprintf('subscriber_electricity_meter_reading[%s]', $fieldName) => '345,678',
            'subscriber_electricity_meter_reading[notes]' => 'Передано по фото',
        ]);

        $reading = $this->findReadingByMeterAndZone($meter, $tariffZone);

        self::assertInstanceOf(ElectricityMeterReading::class, $reading);
        $this->assertResponseRedirects(sprintf('/portal/accounts/%s', $account->getUuid()->toRfc4122()), Response::HTTP_SEE_OTHER);
        self::assertSame('345.678', $reading->getReadingValue());
        self::assertSame('2026-05-10', $reading->getTakenOn()->format('Y-m-d'));
        self::assertSame(ElectricityMeterReadingSource::Subscriber, $reading->getSource());
        self::assertSame($user->getUuid()->toRfc4122(), $reading->getSubmittedBy()?->getUuid()->toRfc4122());
        self::assertSame($subscriber->getUuid()->toRfc4122(), $reading->getProvidedBySubscriber()?->getUuid()->toRfc4122());
        self::assertSame($user->getUuid()->toRfc4122(), $reading->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertSame('Передано по фото', $reading->getNotes());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показания переданы.');
        $this->assertSelectorTextContains('body', '345,678');
    }

    public function testSubscriberMustSubmitAllMeterRegisters(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $meter = $this->createElectricityMeter($workspace, $account);
        $dayZone = $this->createTariffZone($workspace, 'day', 'Дневная зона');
        $nightZone = $this->createTariffZone($workspace, 'night', 'Ночная зона');
        $this->createMeterRegister($workspace, $meter, $dayZone);
        $this->createMeterRegister($workspace, $meter, $nightZone);
        $client->loginUser($user);

        $client->request('GET', sprintf('/portal/accounts/%s/readings/new', $account->getUuid()->toRfc4122()));
        $client->submitForm('Сохранить', [
            'subscriber_electricity_meter_reading[takenOn]' => '10.05.2026',
            sprintf('subscriber_electricity_meter_reading[%s]', SubscriberElectricityMeterReadingType::readingFieldName($dayZone)) => '100',
            sprintf('subscriber_electricity_meter_reading[%s]', SubscriberElectricityMeterReadingType::readingFieldName($nightZone)) => '',
            'subscriber_electricity_meter_reading[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Укажите показание.');
        self::assertSame(0, $this->countReadings($meter));
    }

    public function testSubscriberCannotSubmitLowerReadingThanPreviousActiveReading(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $subscriber = $this->createSubscriber($workspace, $user, 'Иванов');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $meter = $this->createElectricityMeter($workspace, $account);
        $tariffZone = $this->createTariffZone($workspace);
        $this->createReading($workspace, $meter, $tariffZone, '100', '2026-05-10');
        $client->loginUser($user);

        $client->request('GET', sprintf('/portal/accounts/%s/readings/new', $account->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Последнее: 100,000 кВт⋅ч от 10.05.2026.');

        $client->submitForm('Сохранить', [
            'subscriber_electricity_meter_reading[takenOn]' => '10.05.2026',
            sprintf('subscriber_electricity_meter_reading[%s]', SubscriberElectricityMeterReadingType::readingFieldName($tariffZone)) => '90',
            'subscriber_electricity_meter_reading[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Показание не может быть меньше предыдущего активного показания этой зоны.');
        self::assertSame(1, $this->countReadings($meter));
    }

    public function testSubscriberCanSwitchCurrentPortalWorkspace(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $alphaWorkspace = $this->createWorkspace('alpha', 'Альфа');
        $betaWorkspace = $this->createWorkspace('beta', 'Бета');
        $alphaSubscriber = $this->createSubscriber($alphaWorkspace, $user, 'Альфа');
        $betaSubscriber = $this->createSubscriber($betaWorkspace, $user, 'Бета');
        $alphaAccount = $this->createAccount($alphaWorkspace, '1-001');
        $betaAccount = $this->createAccount($betaWorkspace, '2-002');
        $this->createAccess($alphaWorkspace, $alphaSubscriber, $alphaAccount);
        $this->createAccess($betaWorkspace, $betaSubscriber, $betaAccount);
        $client->loginUser($user);

        $client->request('GET', '/portal');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Альфа');
        $this->assertSelectorTextContains('body', '1-001');
        $this->assertSelectorTextNotContains('body', '2-002');

        $client->submitForm('Сменить', [
            'workspace_uuid' => $betaWorkspace->getUuid()->toRfc4122(),
        ]);

        $this->assertResponseRedirects('/portal', Response::HTTP_FOUND);
        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Бета');
        $this->assertSelectorTextContains('body', '2-002');
        $this->assertSelectorTextNotContains('body', '1-001');
    }

    private function createUser(): User
    {
        $user = (new User())->approve();

        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function createLoginUser(string $email): User
    {
        $user = (new User())->approve();

        $identity = new UserEmailIdentity($user, $email);
        $identity->markVerified();
        $user->addEmailIdentity($identity);

        $passwordHash = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, 'test-password-123');
        $credential = new UserPasswordCredential($user, $passwordHash);
        $user->setPasswordCredential($credential);

        $this->entityManager()->persist($user);
        $this->entityManager()->persist($identity);
        $this->entityManager()->persist($credential);
        $this->entityManager()->flush();

        return $user;
    }

    private function createSubscriber(Workspace $workspace, User $user, string $lastName): Subscriber
    {
        $subscriber = (new Subscriber($workspace))
            ->setUser($user)
            ->setLastName($lastName)
            ->setFirstName('Иван')
            ->setSecondName('Иванович');

        $this->entityManager()->persist($subscriber);
        $this->entityManager()->flush();

        return $subscriber;
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createAccess(Workspace $workspace, Subscriber $subscriber, Account $account): SubscriberAccountAccess
    {
        $access = new SubscriberAccountAccess($workspace, $subscriber, $account, SubscriberAccountAccessRole::Owner);

        $this->entityManager()->persist($access);
        $this->entityManager()->flush();

        return $access;
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
            new DateTimeImmutable('2026-06-01'),
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
    ): Payment
    {
        $payment = new Payment(
            $workspace,
            $account,
            $amount,
            new DateTimeImmutable($paidOn),
            PaymentSource::Manual,
        );
        $payment
            ->setPayerName('Иванов И.И.')
            ->setPurpose('Оплата света май 2026');

        $this->entityManager()->persist($payment);
        $this->entityManager()->flush();

        return $payment;
    }

    private function createPaymentRequisiteProfile(Workspace $workspace): PaymentRequisiteProfile
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
            ->setBankAccount('40703810900000000001');

        $this->entityManager()->persist($profile);
        $this->entityManager()->flush();

        return $profile;
    }

    private function createStatementSnapshot(
        Workspace $workspace,
        Account $account,
        PaymentRequisiteProfile $profile,
    ): AccountStatementSnapshot {
        $snapshot = new AccountStatementSnapshot(
            workspace: $workspace,
            account: $account,
            statementDate: new DateTimeImmutable('2026-05-13'),
            activeAccrualTotal: '1200.00',
            activePaymentTotal: '200.00',
            balanceAmount: '-1000.00',
            amountToPay: '1000.00',
            overpaymentAmount: '0.00',
        );
        $snapshot->applyPaymentRequisites($profile, 'Оплата по квитанции '.$snapshot->getNumber());

        $this->entityManager()->persist($snapshot);
        $this->entityManager()->flush();

        return $snapshot;
    }

    private function createElectricityMeter(Workspace $workspace, Account $account): ElectricityMeter
    {
        $meter = (new ElectricityMeter($workspace, $account, new DateTimeImmutable('2026-05-01')))
            ->setSerialNumber('SN-001')
            ->setModel('Меркурий 201');

        $this->entityManager()->persist($meter);
        $this->entityManager()->flush();

        return $meter;
    }

    private function createTariffZone(
        Workspace $workspace,
        string $code = 'single',
        string $name = 'Однотарифная зона',
    ): ElectricityTariffZone {
        $sortOrder = match ($code) {
            'day' => 10,
            'night' => 20,
            default => 10,
        };

        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode($code)
            ->setName($name)
            ->setSortOrder($sortOrder);

        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->flush();

        return $tariffZone;
    }

    private function createMeterRegister(
        Workspace $workspace,
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
    ): ElectricityMeterRegister {
        $register = new ElectricityMeterRegister($workspace, $meter, $tariffZone);

        $this->entityManager()->persist($register);
        $this->entityManager()->flush();

        return $register;
    }

    private function createReading(
        Workspace $workspace,
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
        string $value,
        string $takenOn = '2026-05-10',
    ): ElectricityMeterReading {
        if (!$this->findMeterRegister($meter, $tariffZone) instanceof ElectricityMeterRegister) {
            $this->createMeterRegister($workspace, $meter, $tariffZone);
        }

        $reading = new ElectricityMeterReading(
            $workspace,
            $meter,
            $tariffZone,
            $value,
            new DateTimeImmutable($takenOn),
            ElectricityMeterReadingSource::Subscriber,
        );

        $this->entityManager()->persist($reading);
        $this->entityManager()->flush();

        return $reading;
    }

    private function findMeterRegister(ElectricityMeter $meter, ElectricityTariffZone $tariffZone): ?ElectricityMeterRegister
    {
        return $this->entityManager()
            ->getRepository(ElectricityMeterRegister::class)
            ->findOneBy([
                'electricityMeter' => $meter,
                'tariffZone' => $tariffZone,
            ]);
    }

    private function findReadingByMeterAndZone(ElectricityMeter $meter, ElectricityTariffZone $tariffZone): ?ElectricityMeterReading
    {
        return $this->entityManager()
            ->getRepository(ElectricityMeterReading::class)
            ->findOneBy([
                'electricityMeter' => $meter,
                'tariffZone' => $tariffZone,
            ]);
    }

    private function findReadingByMeterZoneAndValue(
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
        string $readingValue,
    ): ?ElectricityMeterReading {
        return $this->entityManager()
            ->getRepository(ElectricityMeterReading::class)
            ->findOneBy([
                'electricityMeter' => $meter,
                'tariffZone' => $tariffZone,
                'readingValue' => $readingValue,
            ]);
    }

    private function countReadings(ElectricityMeter $meter): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityMeterReading::class)
            ->createQueryBuilder('reading')
            ->select('COUNT(reading.uuid)')
            ->andWhere('reading.electricityMeter = :meter')
            ->setParameter('meter', $meter)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
