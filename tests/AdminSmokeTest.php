<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Accrual;
use App\Entity\BillingRun;
use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffZone;
use App\Entity\Payment;
use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Subscriber;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\BillingRunKind;
use App\Enum\ElectricityConsumptionBandAllocationMethod;
use App\Enum\SubscriberAccountAccessRole;
use App\Enum\WorkspaceUserRoleCode;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;

final class AdminSmokeTest extends FunctionalWebTestCase
{
    public function testAdminCanCompleteElectricityBillingFlow(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace('main', 'СНТ Smoke');
        $client->loginUser($admin);

        $this->completeElectricityBillingFlow($client);
    }

    public function testWorkspaceOperatorCanCompleteElectricityBillingFlow(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace('main', 'СНТ Smoke');
        $operator = $this->createWorkspaceUser($workspace, WorkspaceUserRoleCode::Operator);
        $client->loginUser($operator);

        $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Оператор хозяйства');
        $this->assertSelectorNotExists('a[href="/admin/workspaces"]');
        $this->assertSelectorNotExists('a[href="/admin/audit-logs"]');

        $this->completeElectricityBillingFlow($client);
    }

    private function completeElectricityBillingFlow(KernelBrowser $client): void
    {
        $subscriber = $this->createSubscriberThroughUi($client);
        $this->grantPortalAccessThroughUi($client, $subscriber);

        $account = $this->createAccountThroughUi($client);
        $this->grantAccountAccessThroughUi($client, $account, $subscriber);

        $tariffZone = $this->createTariffZoneThroughUi($client);
        $tariffProfile = $this->createTariffProfileThroughUi($client);
        $socialBand = $this->createConsumptionBandThroughUi($client, 'social_norm', 'Социальная норма', '10');
        $aboveBand = $this->createConsumptionBandThroughUi($client, 'above_social_norm', 'Сверх социальной нормы', '20');
        $rule = $this->createConsumptionBandRuleThroughUi($client, $tariffProfile);
        $this->addConsumptionBandRangeThroughUi($client, $rule, $socialBand, '0', '100');
        $this->addConsumptionBandRangeThroughUi($client, $rule, $aboveBand, '100', '');
        $tariffPeriod = $this->createTariffPeriodThroughUi($client, $tariffProfile);
        $this->addTariffRateThroughUi($client, $tariffPeriod, $tariffZone, $socialBand, '5');
        $this->addTariffRateThroughUi($client, $tariffPeriod, $tariffZone, $aboveBand, '7');
        $this->assignTariffProfileThroughUi($client, $account, $tariffProfile);

        $meter = $this->createElectricityMeterThroughUi($client, $account, $tariffZone);
        $this->createReadingThroughUi($client, $meter, $tariffZone, '50', '01.05.2026');
        $this->createReadingThroughUi($client, $meter, $tariffZone, '170', '04.06.2026');
        $payment = $this->createPaymentThroughUi($client, $account);
        $this->createPaymentRequisiteProfileThroughUi($client);

        self::assertSame('300.00', $payment->getAmount());

        $billingRun = $this->createBillingRunThroughUi($client);

        self::assertSame(0, $this->countOpenBillingRunIssues($billingRun));

        $client->submitForm('Сгенерировать начисления');
        $client->followRedirect();

        $accrual = $this->findAccrualByBillingRunAndAccount($billingRun, $account);

        self::assertInstanceOf(Accrual::class, $accrual);
        self::assertSame('640.00', $accrual->getAmount());
        self::assertFalse($accrual->isActivePosted());
        $this->assertSelectorTextContains('body', '640,00 руб.');
        $this->assertSelectorTextContains('body', 'Следующее действие: провести расчет');

        $client->submitForm('Провести расчет');
        $client->followRedirect();

        $postedBillingRun = $this->findBillingRunByPeriod('2026-05-01', '2026-06-01');
        $postedAccrual = $this->findAccrualByBillingRunAndAccount($billingRun, $account);

        self::assertInstanceOf(BillingRun::class, $postedBillingRun);
        self::assertInstanceOf(Accrual::class, $postedAccrual);
        self::assertTrue($postedBillingRun->isPosted());
        self::assertTrue($postedAccrual->isActivePosted());
        $this->assertSelectorTextContains('body', 'Расчет проведен. Проведено начислений: 1.');

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');
        $this->assertSelectorTextContains('body', 'Начислено');
        $this->assertSelectorTextContains('body', '640,00 руб.');
        $this->assertSelectorTextContains('body', 'Оплачено');
        $this->assertSelectorTextContains('body', '300,00 руб.');
        $this->assertSelectorTextContains('body', 'К оплате');
        $this->assertSelectorTextContains('body', '340,00 руб.');

        $statement = $this->generateStatementsThroughUi($client, $postedBillingRun, $account);
        $this->assertStatementHasPdfQrAndQueuedDelivery($client, $statement, $account);
    }

    private function createWorkspaceUser(Workspace $workspace, WorkspaceUserRoleCode $roleCode): User
    {
        $user = new User();
        $user->approve();

        $identity = new UserEmailIdentity($user, strtolower($roleCode->value).'@example.test');
        $identity->markVerified();
        $user->addEmailIdentity($identity);

        $assignment = new WorkspaceUserRoleAssignment($workspace, $user, $roleCode);
        $user->addWorkspaceRoleAssignment($assignment);

        $this->entityManager()->persist($user);
        $this->entityManager()->persist($identity);
        $this->entityManager()->persist($assignment);
        $this->entityManager()->flush();

        return $user;
    }

    private function createSubscriberThroughUi(KernelBrowser $client): Subscriber
    {
        $client->request('GET', '/admin/subscribers/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'subscriber[lastName]' => 'Иванов',
            'subscriber[firstName]' => 'Иван',
            'subscriber[secondName]' => 'Иванович',
            'subscriber[contactEmail]' => 'smoke-subscriber@example.test',
            'subscriber[contactPhone]' => '+7 900 000-00-00',
            'subscriber[notes]' => 'Smoke-проход оператора',
        ]);

        $subscriber = $this->findSubscriberByEmail('smoke-subscriber@example.test');

        self::assertInstanceOf(Subscriber::class, $subscriber);
        $this->assertResponseRedirects(sprintf('/admin/subscribers/%s', $subscriber->getUuid()), Response::HTTP_SEE_OTHER);

        return $subscriber;
    }

    private function grantPortalAccessThroughUi(KernelBrowser $client, Subscriber $subscriber): void
    {
        $client->request('GET', sprintf('/admin/subscribers/%s', $subscriber->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Подключить к порталу', [
            'subscriber_portal_access_grant[email]' => 'smoke-subscriber@example.test',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/subscribers/%s', $subscriber->getUuid()), Response::HTTP_SEE_OTHER);
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Временный пароль');
    }

    private function createAccountThroughUi(KernelBrowser $client): Account
    {
        $client->request('GET', '/admin/accounts/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'account[number]' => '9-123',
            'account[notes]' => 'Smoke-участок',
        ]);

        $account = $this->findAccountByNumber('9-123');

        self::assertInstanceOf(Account::class, $account);
        $this->assertResponseRedirects(sprintf('/admin/accounts/%s', $account->getUuid()), Response::HTTP_SEE_OTHER);

        return $account;
    }

    private function grantAccountAccessThroughUi(KernelBrowser $client, Account $account, Subscriber $subscriber): void
    {
        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Добавить абонента', [
            'account_subscriber_access_grant[subscriber]' => $subscriber->getUuid()->toRfc4122(),
            'account_subscriber_access_grant[accessRole]' => SubscriberAccountAccessRole::Owner->value,
            'account_subscriber_access_grant[notes]' => 'Smoke-проверка доступа',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/accounts/%s', $account->getUuid()), Response::HTTP_SEE_OTHER);
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Владелец');
    }

    private function createTariffZoneThroughUi(KernelBrowser $client): ElectricityTariffZone
    {
        $client->request('GET', '/admin/electricity-tariff-zones/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_tariff_zone[code]' => 'single',
            'electricity_tariff_zone[name]' => 'Однотарифная зона',
            'electricity_tariff_zone[description]' => 'Общий регистр',
            'electricity_tariff_zone[sortOrder]' => '10',
        ]);

        $tariffZone = $this->findTariffZoneByCode('single');

        self::assertInstanceOf(ElectricityTariffZone::class, $tariffZone);
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-zones/%s', $tariffZone->getUuid()), Response::HTTP_SEE_OTHER);

        return $tariffZone;
    }

    private function createTariffProfileThroughUi(KernelBrowser $client): ElectricityTariffProfile
    {
        $client->request('GET', '/admin/electricity-tariff-profiles/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_tariff_profile[code]' => 'snt',
            'electricity_tariff_profile[name]' => 'СНТ',
            'electricity_tariff_profile[description]' => 'Smoke-профиль',
        ]);

        $tariffProfile = $this->findTariffProfileByCode('snt');

        self::assertInstanceOf(ElectricityTariffProfile::class, $tariffProfile);
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-profiles/%s', $tariffProfile->getUuid()), Response::HTTP_SEE_OTHER);

        return $tariffProfile;
    }

    private function createConsumptionBandThroughUi(
        KernelBrowser $client,
        string $code,
        string $name,
        string $sortOrder,
    ): ElectricityConsumptionBand {
        $client->request('GET', '/admin/electricity-consumption-bands/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_consumption_band[code]' => $code,
            'electricity_consumption_band[name]' => $name,
            'electricity_consumption_band[description]' => '',
            'electricity_consumption_band[sortOrder]' => $sortOrder,
        ]);

        $band = $this->findConsumptionBandByCode($code);

        self::assertInstanceOf(ElectricityConsumptionBand::class, $band);
        $this->assertResponseRedirects(sprintf('/admin/electricity-consumption-bands/%s', $band->getUuid()), Response::HTTP_SEE_OTHER);

        return $band;
    }

    private function createConsumptionBandRuleThroughUi(
        KernelBrowser $client,
        ElectricityTariffProfile $tariffProfile,
    ): ElectricityConsumptionBandRule {
        $client->request('GET', '/admin/electricity-consumption-band-rules/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_consumption_band_rule[tariffProfile]' => $tariffProfile->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule[month]' => '5',
            'electricity_consumption_band_rule[validFrom]' => '01.05.2026',
            'electricity_consumption_band_rule[validTo]' => '01.06.2026',
            'electricity_consumption_band_rule[allocationMethod]' => ElectricityConsumptionBandAllocationMethod::TotalProportional->value,
            'electricity_consumption_band_rule[priority]' => '100',
            'electricity_consumption_band_rule[sourceDocument]' => 'Smoke-решение',
            'electricity_consumption_band_rule[notes]' => '',
        ]);

        $rule = $this->findConsumptionBandRule($tariffProfile, 5);

        self::assertInstanceOf(ElectricityConsumptionBandRule::class, $rule);
        $this->assertResponseRedirects(sprintf('/admin/electricity-consumption-band-rules/%s', $rule->getUuid()), Response::HTTP_SEE_OTHER);

        return $rule;
    }

    private function addConsumptionBandRangeThroughUi(
        KernelBrowser $client,
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBand $band,
        string $lowerBound,
        string $upperBound,
    ): void {
        $client->request('GET', sprintf('/admin/electricity-consumption-band-rules/%s', $rule->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('OK', [
            'electricity_consumption_band_rule_range[consumptionBand]' => $band->getUuid()->toRfc4122(),
            'electricity_consumption_band_rule_range[lowerBoundKwh]' => $lowerBound,
            'electricity_consumption_band_rule_range[upperBoundKwh]' => $upperBound,
        ]);

        $this->assertResponseRedirects(sprintf('/admin/electricity-consumption-band-rules/%s', $rule->getUuid()), Response::HTTP_SEE_OTHER);
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $band->getCode());
    }

    private function createTariffPeriodThroughUi(
        KernelBrowser $client,
        ElectricityTariffProfile $tariffProfile,
    ): ElectricityTariffPeriod {
        $client->request('GET', sprintf('/admin/electricity-tariff-profiles/%s/periods/new', $tariffProfile->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_tariff_period[validFrom]' => '01.05.2026',
            'electricity_tariff_period[validTo]' => '01.06.2026',
            'electricity_tariff_period[sourceDocument]' => 'Smoke-тариф',
            'electricity_tariff_period[notes]' => '',
        ]);

        $tariffPeriod = $this->findTariffPeriod($tariffProfile, '2026-05-01');

        self::assertInstanceOf(ElectricityTariffPeriod::class, $tariffPeriod);
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-periods/%s', $tariffPeriod->getUuid()), Response::HTTP_SEE_OTHER);

        return $tariffPeriod;
    }

    private function addTariffRateThroughUi(
        KernelBrowser $client,
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffZone $tariffZone,
        ElectricityConsumptionBand $band,
        string $rate,
    ): void {
        $client->request('GET', sprintf('/admin/electricity-tariff-periods/%s', $tariffPeriod->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('OK', [
            'electricity_tariff_rate[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_tariff_rate[consumptionBand]' => $band->getUuid()->toRfc4122(),
            'electricity_tariff_rate[rate]' => $rate,
        ]);

        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-periods/%s', $tariffPeriod->getUuid()), Response::HTTP_SEE_OTHER);
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', $band->getCode());
    }

    private function assignTariffProfileThroughUi(KernelBrowser $client, Account $account, ElectricityTariffProfile $tariffProfile): void
    {
        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Назначить профиль', [
            'account_electricity_tariff_profile_assign[tariffProfile]' => $tariffProfile->getUuid()->toRfc4122(),
            'account_electricity_tariff_profile_assign[validFrom]' => '01.05.2026',
            'account_electricity_tariff_profile_assign[notes]' => 'Smoke-тариф участка',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/accounts/%s', $account->getUuid()), Response::HTTP_SEE_OTHER);
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Smoke-тариф участка');
    }

    private function createElectricityMeterThroughUi(
        KernelBrowser $client,
        Account $account,
        ElectricityTariffZone $tariffZone,
    ): ElectricityMeter {
        $client->request('GET', '/admin/electricity-meters/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_meter[account]' => $account->getUuid()->toRfc4122(),
            'electricity_meter[tariffZones]' => [$tariffZone->getUuid()->toRfc4122()],
            'electricity_meter[serialNumber]' => 'SMOKE-001',
            'electricity_meter[model]' => 'Меркурий 201.5',
            'electricity_meter[installedOn]' => '01.05.2026',
            'electricity_meter[removedOn]' => '',
            'electricity_meter[verifiedOn]' => '',
            'electricity_meter[verificationValidUntil]' => '',
            'electricity_meter[notes]' => '',
        ]);

        $meter = $this->findElectricityMeterBySerialNumber('SMOKE-001');

        self::assertInstanceOf(ElectricityMeter::class, $meter);
        $this->assertResponseRedirects(sprintf('/admin/electricity-meters/%s', $meter->getUuid()), Response::HTTP_SEE_OTHER);

        return $meter;
    }

    private function createReadingThroughUi(
        KernelBrowser $client,
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
        string $readingValue,
        string $takenOn,
    ): ElectricityMeterReading {
        $client->request('GET', sprintf('/admin/electricity-meters/%s/readings/new', $meter->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_meter_reading[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_meter_reading[readingValue]' => $readingValue,
            'electricity_meter_reading[takenOn]' => $takenOn,
            'electricity_meter_reading[notes]' => 'Smoke-показание',
        ]);

        $reading = $this->findReading($meter, $tariffZone, $readingValue);

        self::assertInstanceOf(ElectricityMeterReading::class, $reading);
        $this->assertResponseRedirects(sprintf('/admin/electricity-meter-readings/%s', $reading->getUuid()), Response::HTTP_SEE_OTHER);

        return $reading;
    }

    private function createPaymentThroughUi(KernelBrowser $client, Account $account): Payment
    {
        $client->request('GET', sprintf('/admin/accounts/%s/payments/new', $account->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'payment[amount]' => '300,00',
            'payment[paidOn]' => '10.05.2026',
            'payment[payerName]' => 'Иванов Иван Иванович',
            'payment[purpose]' => 'Smoke-оплата света',
            'payment[externalReference]' => 'smoke-bank-row-1',
        ]);

        $payment = $this->findPaymentByReference('smoke-bank-row-1');

        self::assertInstanceOf(Payment::class, $payment);
        $this->assertResponseRedirects(sprintf('/admin/payments/%s', $payment->getUuid()), Response::HTTP_SEE_OTHER);

        return $payment;
    }

    private function createPaymentRequisiteProfileThroughUi(KernelBrowser $client): PaymentRequisiteProfile
    {
        $client->request('GET', '/admin/payment-requisite-profiles/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'payment_requisite_profile[code]' => 'smoke-main',
            'payment_requisite_profile[name]' => 'Smoke-реквизиты',
            'payment_requisite_profile[recipientName]' => 'ТСН "Smoke"',
            'payment_requisite_profile[recipientInn]' => '1234567890',
            'payment_requisite_profile[recipientKpp]' => '123456789',
            'payment_requisite_profile[bankName]' => 'ПАО Сбербанк',
            'payment_requisite_profile[bankBik]' => '044525225',
            'payment_requisite_profile[bankCorrespondentAccount]' => '30101810400000000225',
            'payment_requisite_profile[bankAccount]' => '40703810900000000001',
            'payment_requisite_profile[validFrom]' => '01.05.2026',
            'payment_requisite_profile[paymentPurposeTemplate]' => 'Smoke-оплата {statement_number}, участок {account_number}',
        ]);

        $profile = $this->findPaymentRequisiteProfileByCode('smoke-main');

        self::assertInstanceOf(PaymentRequisiteProfile::class, $profile);
        $this->assertResponseRedirects(sprintf('/admin/payment-requisite-profiles/%s', $profile->getUuid()), Response::HTTP_SEE_OTHER);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $client->submitForm('Назначить профиль', [
            'accrual_type' => '',
        ]);

        $assignment = $this->findOpenPaymentRequisiteAssignment($profile);

        self::assertInstanceOf(PaymentRequisiteAssignment::class, $assignment);
        self::assertNull($assignment->getAccrualType());
        $this->assertResponseRedirects(sprintf('/admin/payment-requisite-profiles/%s', $profile->getUuid()), Response::HTTP_SEE_OTHER);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Все начисления');

        return $profile;
    }

    private function createBillingRunThroughUi(KernelBrowser $client): BillingRun
    {
        $client->request('GET', '/admin/billing-runs/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'billing_run[kind]' => BillingRunKind::Electricity->value,
            'billing_run[periodStart]' => '01.05.2026',
            'billing_run[periodEnd]' => '01.06.2026',
        ]);

        $billingRun = $this->findBillingRunByPeriod('2026-05-01', '2026-06-01');

        self::assertInstanceOf(BillingRun::class, $billingRun);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Открытых проблем нет.');

        return $billingRun;
    }

    private function generateStatementsThroughUi(
        KernelBrowser $client,
        BillingRun $billingRun,
        Account $account,
    ): AccountStatementSnapshot {
        $client->request('GET', sprintf('/admin/billing-runs/%s', $billingRun->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Следующее действие: сформировать квитанции');
        $this->assertSelectorTextContains('body', 'Квитанции по расчету пока не сформированы.');
        $this->assertSelectorExists(sprintf(
            'form[action="/admin/billing-runs/%s/statements/generate"]',
            $billingRun->getUuid()->toRfc4122(),
        ));

        $client->submitForm('Сформировать квитанции');

        $statement = $this->findStatementByBillingRunAndAccount($billingRun, $account);

        self::assertInstanceOf(AccountStatementSnapshot::class, $statement);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($billingRun->getUuid()->toRfc4122(), $statement->getBillingRun()?->getUuid()->toRfc4122());
        self::assertSame($account->getUuid()->toRfc4122(), $statement->getAccount()?->getUuid()->toRfc4122());
        self::assertSame('640.00', $statement->getActiveAccrualTotal());
        self::assertSame('300.00', $statement->getActivePaymentTotal());
        self::assertSame('-340.00', $statement->getBalanceAmount());
        self::assertSame('340.00', $statement->getAmountToPay());
        self::assertSame('ТСН "Smoke"', $statement->getPaymentRecipientName());
        self::assertSame('044525225', $statement->getPaymentBankBik());
        self::assertSame('40703810900000000001', $statement->getPaymentBankAccount());
        self::assertSame(sprintf('Smoke-оплата %s, участок 9-123', $statement->getNumber()), $statement->getPaymentPurpose());

        $delivery = $this->findDeliveryByStatement($statement);

        self::assertInstanceOf(AccountStatementDelivery::class, $delivery);
        self::assertSame('smoke-subscriber@example.test', $delivery->getRecipientEmail());
        self::assertSame('Иванов Иван Иванович', $delivery->getRecipientName());
        self::assertSame('queued', $delivery->getLatestAttempt()?->getStatusCode());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Квитанции обработаны. Создано: 1, уже было: 0, доставок в очередь: 1');
        $this->assertSelectorTextContains('body', $statement->getNumber());
        $this->assertSelectorTextContains('body', 'smoke-subscriber@example.test');
        $this->assertSelectorTextContains('body', 'В очереди');
        $this->assertSelectorExists(sprintf(
            'a[href="/admin/accounts/%s/statements/%s/pdf"]',
            $account->getUuid()->toRfc4122(),
            $statement->getUuid()->toRfc4122(),
        ));

        return $statement;
    }

    private function assertStatementHasPdfQrAndQueuedDelivery(
        KernelBrowser $client,
        AccountStatementSnapshot $statement,
        Account $account,
    ): void {
        $client->request('GET', sprintf('/admin/accounts/%s/statements/%s', $account->getUuid(), $statement->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Квитанция № '.$statement->getNumber());
        $this->assertSelectorTextContains('body', '340,00 руб.');
        $this->assertSelectorTextContains('body', 'ТСН "Smoke"');
        $this->assertSelectorTextContains('body', '40703810900000000001');
        $this->assertSelectorTextContains('body', sprintf('Smoke-оплата %s, участок 9-123', $statement->getNumber()));
        $this->assertSelectorExists('img[alt^="QR-код оплаты"]');
        $this->assertSelectorTextContains('body', 'Расчет электроэнергии');
        $this->assertSelectorTextContains('body', 'Меркурий 201.5');
        $this->assertSelectorTextContains('body', 'SMOKE-001');
        $this->assertSelectorTextContains('body', 'Доставка');
        $this->assertSelectorTextContains('body', 'smoke-subscriber@example.test');
        $this->assertSelectorTextContains('body', 'В очереди');

        $client->request('GET', sprintf('/admin/accounts/%s/statements/%s/pdf', $account->getUuid(), $statement->getUuid()));

        $this->assertResponseIsSuccessful();
        self::assertSame('application/pdf', $client->getResponse()->headers->get('content-type'));
        self::assertStringContainsString('inline;', (string) $client->getResponse()->headers->get('content-disposition'));
        self::assertStringStartsWith('%PDF-', (string) $client->getResponse()->getContent());

        $client->request('GET', '/admin/account-statement-deliveries', [
            'status' => 'queued',
            'q' => 'smoke-subscriber@example.test',
        ]);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Доставка квитанций');
        $this->assertSelectorTextContains('body', $statement->getNumber());
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', 'smoke-subscriber@example.test');
        $this->assertSelectorTextContains('body', 'В очереди');
    }

    private function findSubscriberByEmail(string $email): ?Subscriber
    {
        return $this->entityManager()
            ->getRepository(Subscriber::class)
            ->findOneBy(['contactEmail' => $email]);
    }

    private function findAccountByNumber(string $number): ?Account
    {
        return $this->entityManager()
            ->getRepository(Account::class)
            ->findOneBy(['number' => $number]);
    }

    private function findTariffZoneByCode(string $code): ?ElectricityTariffZone
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffZone::class)
            ->findOneBy(['code' => $code]);
    }

    private function findTariffProfileByCode(string $code): ?ElectricityTariffProfile
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffProfile::class)
            ->findOneBy(['code' => $code]);
    }

    private function findConsumptionBandByCode(string $code): ?ElectricityConsumptionBand
    {
        return $this->entityManager()
            ->getRepository(ElectricityConsumptionBand::class)
            ->findOneBy(['code' => $code]);
    }

    private function findConsumptionBandRule(ElectricityTariffProfile $tariffProfile, int $month): ?ElectricityConsumptionBandRule
    {
        return $this->entityManager()
            ->getRepository(ElectricityConsumptionBandRule::class)
            ->findOneBy(['tariffProfile' => $tariffProfile, 'month' => $month]);
    }

    private function findTariffPeriod(ElectricityTariffProfile $tariffProfile, string $validFrom): ?ElectricityTariffPeriod
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffPeriod::class)
            ->findOneBy(['tariffProfile' => $tariffProfile, 'validFrom' => new DateTimeImmutable($validFrom)]);
    }

    private function findElectricityMeterBySerialNumber(string $serialNumber): ?ElectricityMeter
    {
        return $this->entityManager()
            ->getRepository(ElectricityMeter::class)
            ->findOneBy(['serialNumber' => $serialNumber]);
    }

    private function findReading(
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
        string $readingValue,
    ): ?ElectricityMeterReading {
        return $this->entityManager()
            ->getRepository(ElectricityMeterReading::class)
            ->findOneBy([
                'electricityMeter' => $meter,
                'tariffZone' => $tariffZone,
                'readingValue' => str_replace(',', '.', $readingValue),
            ]);
    }

    private function findPaymentByReference(string $externalReference): ?Payment
    {
        return $this->entityManager()
            ->getRepository(Payment::class)
            ->findOneBy(['externalReference' => $externalReference]);
    }

    private function findPaymentRequisiteProfileByCode(string $code): ?PaymentRequisiteProfile
    {
        return $this->entityManager()
            ->getRepository(PaymentRequisiteProfile::class)
            ->findOneBy(['code' => $code]);
    }

    private function findOpenPaymentRequisiteAssignment(PaymentRequisiteProfile $profile): ?PaymentRequisiteAssignment
    {
        return $this->entityManager()
            ->getRepository(PaymentRequisiteAssignment::class)
            ->findOneBy([
                'paymentRequisiteProfile' => $profile,
                'closedAt' => null,
            ]);
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

    private function findAccrualByBillingRunAndAccount(BillingRun $billingRun, Account $account): ?Accrual
    {
        return $this->entityManager()
            ->getRepository(Accrual::class)
            ->findOneBy(['billingRun' => $billingRun, 'account' => $account]);
    }

    private function findStatementByBillingRunAndAccount(
        BillingRun $billingRun,
        Account $account,
    ): ?AccountStatementSnapshot {
        return $this->entityManager()
            ->getRepository(AccountStatementSnapshot::class)
            ->findOneBy(['billingRun' => $billingRun, 'account' => $account]);
    }

    private function findDeliveryByStatement(AccountStatementSnapshot $statement): ?AccountStatementDelivery
    {
        return $this->entityManager()
            ->getRepository(AccountStatementDelivery::class)
            ->findOneBy(['accountStatement' => $statement]);
    }

    private function countOpenBillingRunIssues(BillingRun $billingRun): int
    {
        return (int) $this->entityManager()
            ->getRepository(\App\Entity\BillingRunAccountIssue::class)
            ->createQueryBuilder('issue')
            ->select('COUNT(issue.uuid)')
            ->andWhere('issue.billingRun = :billingRun')
            ->andWhere('issue.closedAt IS NULL')
            ->setParameter('billingRun', $billingRun)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
