<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffZone;
use App\Entity\Workspace;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminElectricityMeterControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/electricity-meters');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyElectricityMetersList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-meters');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Электросчетчики');
        $this->assertSelectorTextContains('td', 'Электросчетчики пока не созданы.');
    }

    public function testAdminCanSortAndPaginateElectricityMetersList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');

        for ($i = 1; $i <= 55; ++$i) {
            $account = $this->createAccount($workspace, sprintf('9-%03d', $i));
            $this->createElectricityMeter($workspace, $account, sprintf('SN-%03d', $i), $tariffZone);
        }

        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-meters?sort=account_number&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $account55Position = strpos($content, '9-055');
        $account54Position = strpos($content, '9-054');
        self::assertNotFalse($account55Position);
        self::assertNotFalse($account54Position);
        self::assertLessThan($account54Position, $account55Position);
        $this->assertSelectorExists('a[href="/admin/electricity-meters?sort=account_number&dir=asc&page=1"]');

        $client->request('GET', '/admin/electricity-meters?sort=account_number&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', '9-005');
        $this->assertSelectorTextNotContains('body', '9-055');
    }

    public function testAdminCanCreateElectricityMeterWithRegisters(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $singleZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона', 10);
        $nightZone = $this->createTariffZone($workspace, 'night', 'Ночная зона', 20);
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-meters/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select.js-searchable-select[name="electricity_meter[account]"]');
        $this->assertSelectorExists('select.js-searchable-select[name="electricity_meter[tariffZones][]"]');
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="electricity_meter[installedOn]"][placeholder="дд.мм.гггг"]');
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="electricity_meter[verificationValidUntil]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Сохранить', [
            'electricity_meter[account]' => $account->getUuid()->toRfc4122(),
            'electricity_meter[tariffZones]' => [
                $singleZone->getUuid()->toRfc4122(),
                $nightZone->getUuid()->toRfc4122(),
            ],
            'electricity_meter[serialNumber]' => 'SN-001',
            'electricity_meter[model]' => 'Меркурий 201.5',
            'electricity_meter[installedOn]' => '01.05.2026',
            'electricity_meter[removedOn]' => '',
            'electricity_meter[verifiedOn]' => '20.04.2026',
            'electricity_meter[verificationValidUntil]' => '20.04.2036',
            'electricity_meter[notes]' => 'Установлен на опоре',
        ]);

        $electricityMeter = $this->findElectricityMeterBySerialNumber('SN-001');

        self::assertInstanceOf(ElectricityMeter::class, $electricityMeter);
        $this->assertResponseRedirects(sprintf('/admin/electricity-meters/%s', $electricityMeter->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $electricityMeter->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame($account->getUuid()->toRfc4122(), $electricityMeter->getAccount()?->getUuid()->toRfc4122());
        self::assertSame('Меркурий 201.5', $electricityMeter->getModel());
        self::assertSame('2026-05-01', $electricityMeter->getInstalledOn()->format('Y-m-d'));
        self::assertSame('2026-04-20', $electricityMeter->getVerifiedOn()?->format('Y-m-d'));
        self::assertSame('2036-04-20', $electricityMeter->getVerificationValidUntil()?->format('Y-m-d'));
        self::assertSame($admin->getUuid()->toRfc4122(), $electricityMeter->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertSame(2, $this->countRegisters($electricityMeter));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Электросчетчик участка 9-123');
        $this->assertSelectorTextContains('body', 'SN-001');
        $this->assertSelectorTextContains('body', 'Меркурий 201.5');
        $this->assertSelectorTextContains('body', 'single');
        $this->assertSelectorTextContains('body', 'night');

        $client->request('GET', '/admin/electricity-meters');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'SN-001');
        $this->assertSelectorTextContains('body', 'Меркурий 201.5');
        $this->assertSelectorTextContains('body', 'single');
        $this->assertSelectorTextContains('body', 'Активен');
    }

    public function testAdminCanFilterElectricityMetersList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $activeAccount = $this->createAccount($workspace, '9-001');
        $removedAccount = $this->createAccount($workspace, '9-002');
        $deletedAccount = $this->createAccount($workspace, '9-003');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $activeMeter = $this->createElectricityMeter($workspace, $activeAccount, 'SN-001', $tariffZone)
            ->setModel('Меркурий 201.5');
        $removedMeter = $this->createElectricityMeter($workspace, $removedAccount, 'SN-002', $tariffZone)
            ->setModel('Энергомера CE101')
            ->setRemovedOn(new DateTimeImmutable('2026-06-01'));
        $deletedMeter = $this->createElectricityMeter($workspace, $deletedAccount, 'SN-003', $tariffZone);
        $deletedMeter->delete();
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-meters?q=меркурий');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'SN-001');
        $this->assertSelectorTextNotContains('body', 'SN-002');
        $this->assertSelectorTextNotContains('body', 'SN-003');
        $this->assertSelectorExists('input[name="q"][value="меркурий"]');

        $client->request('GET', '/admin/electricity-meters?q=9-002');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'SN-002');
        $this->assertSelectorTextNotContains('body', 'SN-001');

        $client->request('GET', '/admin/electricity-meters?status=active');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'SN-001');
        $this->assertSelectorTextNotContains('body', 'SN-002');

        $client->request('GET', '/admin/electricity-meters?status=removed');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'SN-002');
        $this->assertSelectorTextNotContains('body', 'SN-001');

        self::assertSame('SN-001', $activeMeter->getSerialNumber());
        self::assertSame('SN-002', $removedMeter->getSerialNumber());
    }

    public function testAdminCannotCreateSecondActiveMeterForAccount(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $this->createElectricityMeter($workspace, $account, 'SN-001', $tariffZone);
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-meters/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_meter[account]' => $account->getUuid()->toRfc4122(),
            'electricity_meter[tariffZones]' => [$tariffZone->getUuid()->toRfc4122()],
            'electricity_meter[serialNumber]' => 'SN-002',
            'electricity_meter[model]' => '',
            'electricity_meter[installedOn]' => '01.06.2026',
            'electricity_meter[removedOn]' => '',
            'electricity_meter[verifiedOn]' => '',
            'electricity_meter[verificationValidUntil]' => '',
            'electricity_meter[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'У этого участка уже есть активный электросчетчик.');
        self::assertSame(1, $this->countElectricityMetersByAccount($account));
    }

    public function testAdminCannotCreateMeterWithInvalidDates(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-meters/new');
        $client->submitForm('Сохранить', [
            'electricity_meter[account]' => $account->getUuid()->toRfc4122(),
            'electricity_meter[tariffZones]' => [$tariffZone->getUuid()->toRfc4122()],
            'electricity_meter[serialNumber]' => 'SN-001',
            'electricity_meter[model]' => '',
            'electricity_meter[installedOn]' => '10.05.2026',
            'electricity_meter[removedOn]' => '01.05.2026',
            'electricity_meter[verifiedOn]' => '10.05.2026',
            'electricity_meter[verificationValidUntil]' => '01.05.2026',
            'electricity_meter[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Дата снятия не может быть раньше даты установки.');
        $this->assertSelectorTextContains('body', 'Дата окончания поверки не может быть раньше даты поверки.');
        self::assertSame(0, $this->countElectricityMetersByAccount($account));
    }

    public function testAdminCanEditElectricityMeterWithoutChangingRegisters(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $electricityMeter = $this->createElectricityMeter($workspace, $account, 'SN-001', $tariffZone);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-meters/%s/edit', $electricityMeter->getUuid()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('select[name="electricity_meter[account]"]');
        $this->assertSelectorNotExists('select[name="electricity_meter[tariffZones][]"]');
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="electricity_meter[removedOn]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Сохранить', [
            'electricity_meter[serialNumber]' => 'SN-002',
            'electricity_meter[model]' => 'Энергомера CE101',
            'electricity_meter[installedOn]' => '01.05.2026',
            'electricity_meter[removedOn]' => '01.06.2026',
            'electricity_meter[verifiedOn]' => '02.05.2026',
            'electricity_meter[verificationValidUntil]' => '02.05.2036',
            'electricity_meter[notes]' => 'После исправления',
        ]);

        $updatedMeter = $this->findElectricityMeterByUuid($electricityMeter->getUuid());

        self::assertInstanceOf(ElectricityMeter::class, $updatedMeter);
        $this->assertResponseRedirects(sprintf('/admin/electricity-meters/%s', $updatedMeter->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('SN-002', $updatedMeter->getSerialNumber());
        self::assertSame('Энергомера CE101', $updatedMeter->getModel());
        self::assertSame('2026-06-01', $updatedMeter->getRemovedOn()?->format('Y-m-d'));
        self::assertSame('После исправления', $updatedMeter->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $updatedMeter->getUpdatedBy()?->getUuid()->toRfc4122());
        self::assertSame(1, $this->countRegisters($updatedMeter));
    }

    public function testAdminCanSoftDeleteElectricityMeter(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $electricityMeter = $this->createElectricityMeter($workspace, $account, 'SN-001', $tariffZone);
        $electricityMeterUuid = $electricityMeter->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-meters/%s/edit', $electricityMeterUuid));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Удалить');
        $this->assertResponseRedirects('/admin/electricity-meters', Response::HTTP_SEE_OTHER);

        $deletedMeter = $this->findElectricityMeterByUuid($electricityMeterUuid);

        self::assertInstanceOf(ElectricityMeter::class, $deletedMeter);
        self::assertNotNull($deletedMeter->getDeletedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $deletedMeter->getDeletedBy()?->getUuid()->toRfc4122());
        self::assertSame(1, $this->countRegisters($deletedMeter));

        $client->request('GET', '/admin/electricity-meters');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Электросчетчики пока не созданы.');
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createTariffZone(Workspace $workspace, string $code, string $name, int $sortOrder = 100): ElectricityTariffZone
    {
        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode($code)
            ->setName($name)
            ->setSortOrder($sortOrder);

        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->flush();

        return $tariffZone;
    }

    private function createElectricityMeter(Workspace $workspace, Account $account, string $serialNumber, ElectricityTariffZone $tariffZone): ElectricityMeter
    {
        $electricityMeter = (new ElectricityMeter($workspace, $account, new DateTimeImmutable('2026-05-01')))
            ->setSerialNumber($serialNumber);
        $register = new ElectricityMeterRegister($workspace, $electricityMeter, $tariffZone);

        $this->entityManager()->persist($electricityMeter);
        $this->entityManager()->persist($register);
        $this->entityManager()->flush();

        return $electricityMeter;
    }

    private function findElectricityMeterByUuid(Uuid $uuid): ?ElectricityMeter
    {
        return $this->entityManager()
            ->getRepository(ElectricityMeter::class)
            ->find($uuid);
    }

    private function findElectricityMeterBySerialNumber(string $serialNumber): ?ElectricityMeter
    {
        return $this->entityManager()
            ->getRepository(ElectricityMeter::class)
            ->findOneBy(['serialNumber' => $serialNumber]);
    }

    private function countElectricityMetersByAccount(Account $account): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityMeter::class)
            ->createQueryBuilder('electricityMeter')
            ->select('COUNT(electricityMeter.uuid)')
            ->andWhere('electricityMeter.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countRegisters(ElectricityMeter $electricityMeter): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityMeterRegister::class)
            ->createQueryBuilder('register')
            ->select('COUNT(register.electricityMeter)')
            ->andWhere('register.electricityMeter = :electricityMeter')
            ->setParameter('electricityMeter', $electricityMeter)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
