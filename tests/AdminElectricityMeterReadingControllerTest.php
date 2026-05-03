<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffZone;
use App\Entity\Workspace;
use App\Enum\ElectricityMeterReadingSource;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminElectricityMeterReadingControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/electricity-meter-readings');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyReadingsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-meter-readings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Показания электросчетчиков');
        $this->assertSelectorTextContains('td', 'Показания пока не внесены.');
    }

    public function testAdminCanSortAndPaginateReadingsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');

        for ($i = 1; $i <= 55; ++$i) {
            $account = $this->createAccount($workspace, sprintf('9-%03d', $i));
            $meter = $this->createElectricityMeter($workspace, $account, sprintf('SN-%03d', $i), $tariffZone);
            $this->createReading($workspace, $meter, $tariffZone, (string) $i, '2026-05-09');
        }

        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-meter-readings?sort=account_number&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $account55Position = strpos($content, '9-055');
        $account54Position = strpos($content, '9-054');
        self::assertNotFalse($account55Position);
        self::assertNotFalse($account54Position);
        self::assertLessThan($account54Position, $account55Position);
        $this->assertSelectorExists('a[href="/admin/electricity-meter-readings?sort=account_number&dir=asc&page=1"]');

        $client->request('GET', '/admin/electricity-meter-readings?sort=account_number&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', '9-005');
        $this->assertSelectorTextNotContains('body', '9-055');
    }

    public function testAdminCanCreateReadingFromMeterCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, 'SN-001', $tariffZone);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-meters/%s', $meter->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показания пока не внесены.');

        $client->request('GET', sprintf('/admin/electricity-meters/%s/readings/new', $meter->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select.js-searchable-select[name="electricity_meter_reading[tariffZone]"]');
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="electricity_meter_reading[takenOn]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Сохранить', [
            'electricity_meter_reading[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_meter_reading[readingValue]' => '123,456',
            'electricity_meter_reading[takenOn]' => '09.05.2026',
            'electricity_meter_reading[notes]' => 'Снято оператором',
        ]);

        $reading = $this->findReadingByMeterAndZone($meter, $tariffZone);

        self::assertInstanceOf(ElectricityMeterReading::class, $reading);
        $this->assertResponseRedirects(sprintf('/admin/electricity-meter-readings/%s', $reading->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $reading->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame($meter->getUuid()->toRfc4122(), $reading->getElectricityMeter()?->getUuid()->toRfc4122());
        self::assertSame($tariffZone->getUuid()->toRfc4122(), $reading->getTariffZone()?->getUuid()->toRfc4122());
        self::assertSame('123.456', $reading->getReadingValue());
        self::assertSame('2026-05-09', $reading->getTakenOn()->format('Y-m-d'));
        self::assertSame(ElectricityMeterReadingSource::Admin, $reading->getSource());
        self::assertSame($admin->getUuid()->toRfc4122(), $reading->getSubmittedBy()?->getUuid()->toRfc4122());
        self::assertSame($admin->getUuid()->toRfc4122(), $reading->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertTrue($reading->isActive());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Показание электросчетчика');
        $this->assertSelectorTextContains('body', '123,456');
        $this->assertSelectorTextContains('body', 'Активно');

        $client->request('GET', sprintf('/admin/electricity-meters/%s', $meter->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '123,456');

        $client->request('GET', '/admin/electricity-meter-readings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', '123,456');
    }

    public function testAdminCanFilterReadingsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $activeAccount = $this->createAccount($workspace, '9-001');
        $cancelledAccount = $this->createAccount($workspace, '9-002');
        $singleZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $nightZone = $this->createTariffZone($workspace, 'night', 'Ночная зона');
        $activeMeter = $this->createElectricityMeter($workspace, $activeAccount, 'SN-001', $singleZone);
        $cancelledMeter = $this->createElectricityMeter($workspace, $cancelledAccount, 'SN-002', $nightZone);
        $activeReading = $this->createReading($workspace, $activeMeter, $singleZone, '123', '2026-05-09');
        $cancelledReading = $this->createReading($workspace, $cancelledMeter, $nightZone, '50', '2026-05-10');
        $cancelledReading->cancel('Ошибочное показание', $admin);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-meter-readings?q=SN-001');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');
        $this->assertSelectorExists('input[name="q"][value="SN-001"]');

        $client->request('GET', sprintf('/admin/electricity-meter-readings?tariff_zone_uuid=%s', $nightZone->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/electricity-meter-readings?status=active');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');

        $client->request('GET', '/admin/electricity-meter-readings?status=cancelled');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextContains('body', 'Отменено');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/electricity-meter-readings?taken_on_from=10.05.2026&taken_on_to=10.05.2026');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-001');
        $this->assertSelectorExists('input[name="taken_on_from"][value="10.05.2026"]');

        self::assertTrue($activeReading->isActive());
        self::assertFalse($cancelledReading->isActive());
    }

    public function testAdminCannotCreateReadingForMissingMeterRegister(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $singleZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $nightZone = $this->createTariffZone($workspace, 'night', 'Ночная зона');
        $meter = $this->createElectricityMeter($workspace, $account, 'SN-001', $singleZone);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/electricity-meters/%s/readings/new', $meter->getUuid()));
        $csrfToken = (string) $crawler->filter('input[name="electricity_meter_reading[_token]"]')->attr('value');
        $client->request('POST', sprintf('/admin/electricity-meters/%s/readings/new', $meter->getUuid()), [
            'electricity_meter_reading' => [
                'tariffZone' => $nightZone->getUuid()->toRfc4122(),
                'readingValue' => '10',
                'takenOn' => '09.05.2026',
                'notes' => '',
                '_token' => $csrfToken,
            ],
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, $this->countReadings($meter));
    }

    public function testAdminCannotCreateReadingOutsideMeterDatesOrLowerThanPrevious(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, 'SN-001', $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '100', '2026-05-10');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-meters/%s/readings/new', $meter->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_meter_reading[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_meter_reading[readingValue]' => '90',
            'electricity_meter_reading[takenOn]' => '10.05.2026',
            'electricity_meter_reading[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Показание не может быть меньше предыдущего активного показания этой зоны.');
        self::assertSame(1, $this->countReadings($meter));

        $client->request('GET', sprintf('/admin/electricity-meters/%s/readings/new', $meter->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_meter_reading[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_meter_reading[readingValue]' => '120',
            'electricity_meter_reading[takenOn]' => '09.05.2026',
            'electricity_meter_reading[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Показание не может быть больше следующего активного показания этой зоны.');
        self::assertSame(1, $this->countReadings($meter));

        $client->request('GET', sprintf('/admin/electricity-meters/%s/readings/new', $meter->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_meter_reading[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_meter_reading[readingValue]' => '120',
            'electricity_meter_reading[takenOn]' => '30.04.2026',
            'electricity_meter_reading[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Дата снятия показания не может быть раньше даты установки счетчика.');
        self::assertSame(1, $this->countReadings($meter));
    }

    public function testAdminCanCancelActiveReading(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, 'SN-001', $tariffZone);
        $reading = $this->createReading($workspace, $meter, $tariffZone, '123', '2026-05-09');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-meter-readings/%s', $reading->getUuid()));

        $this->assertResponseIsSuccessful();

        $client->submitForm('Отменить', [
            'electricity_meter_reading_cancel[reason]' => 'Внесено не по тому фото',
        ]);

        $cancelledReading = $this->findReadingByUuid($reading->getUuid());

        self::assertInstanceOf(ElectricityMeterReading::class, $cancelledReading);
        $this->assertResponseRedirects(sprintf('/admin/electricity-meter-readings/%s', $reading->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNotNull($cancelledReading->getCancelledAt());
        self::assertSame('Внесено не по тому фото', $cancelledReading->getCancellationReason());
        self::assertSame($admin->getUuid()->toRfc4122(), $cancelledReading->getCancelledBy()?->getUuid()->toRfc4122());
        self::assertFalse($cancelledReading->isActive());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Отменено');
        $this->assertSelectorTextContains('body', 'Внесено не по тому фото');
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number);

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

    private function createElectricityMeter(Workspace $workspace, Account $account, string $serialNumber, ElectricityTariffZone $tariffZone): ElectricityMeter
    {
        $meter = (new ElectricityMeter($workspace, $account, new DateTimeImmutable('2026-05-01')))
            ->setSerialNumber($serialNumber);
        $register = new ElectricityMeterRegister($workspace, $meter, $tariffZone);

        $this->entityManager()->persist($meter);
        $this->entityManager()->persist($register);
        $this->entityManager()->flush();

        return $meter;
    }

    private function createReading(Workspace $workspace, ElectricityMeter $meter, ElectricityTariffZone $tariffZone, string $value, string $takenOn): ElectricityMeterReading
    {
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

    private function findReadingByUuid(Uuid $uuid): ?ElectricityMeterReading
    {
        return $this->entityManager()
            ->getRepository(ElectricityMeterReading::class)
            ->find($uuid);
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
