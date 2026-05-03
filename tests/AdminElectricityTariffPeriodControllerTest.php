<?php

namespace App\Tests;

use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\Workspace;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminElectricityTariffPeriodControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/electricity-tariff-periods/018f0000-0000-7000-8000-000000000000');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanCreateTariffPeriodFromProfileCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-profiles/%s', $tariffProfile->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Тарифные периоды пока не созданы.');

        $client->request('GET', sprintf('/admin/electricity-tariff-profiles/%s/periods/new', $tariffProfile->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="electricity_tariff_period[validFrom]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Сохранить', [
            'electricity_tariff_period[validFrom]' => '01.05.2026',
            'electricity_tariff_period[validTo]' => '01.06.2026',
            'electricity_tariff_period[sourceDocument]' => 'Протокол правления от 30.04.2026',
            'electricity_tariff_period[notes]' => 'Весенний тариф',
        ]);

        $tariffPeriod = $this->findTariffPeriodByValidFrom($tariffProfile, '2026-05-01');

        self::assertInstanceOf(ElectricityTariffPeriod::class, $tariffPeriod);
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-periods/%s', $tariffPeriod->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $tariffPeriod->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame($tariffProfile->getUuid()->toRfc4122(), $tariffPeriod->getTariffProfile()?->getUuid()->toRfc4122());
        self::assertSame('2026-06-01', $tariffPeriod->getValidTo()?->format('Y-m-d'));
        self::assertSame('Протокол правления от 30.04.2026', $tariffPeriod->getSourceDocument());
        self::assertSame('Весенний тариф', $tariffPeriod->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $tariffPeriod->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertNull($tariffPeriod->getDeletedAt());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Тарифный период');
        $this->assertSelectorTextContains('body', 'Протокол правления от 30.04.2026');
        $this->assertSelectorTextContains('body', 'Ставки для периода пока не заданы.');
    }

    public function testAdminCannotCreateOverlappingTariffPeriod(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01', '2026-06-01');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-profiles/%s/periods/new', $tariffProfile->getUuid()));
        $client->submitForm('Сохранить', [
            'electricity_tariff_period[validFrom]' => '15.05.2026',
            'electricity_tariff_period[validTo]' => '01.07.2026',
            'electricity_tariff_period[sourceDocument]' => '',
            'electricity_tariff_period[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Период пересекается с уже существующим тарифным периодом профиля.');
        self::assertSame(1, $this->countTariffPeriods($tariffProfile));
    }

    public function testAdminCanEditTariffPeriod(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01', '2026-06-01', 'До исправления');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-periods/%s/edit', $tariffPeriod->getUuid()));

        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_tariff_period[validFrom]' => '01.06.2026',
            'electricity_tariff_period[validTo]' => '',
            'electricity_tariff_period[sourceDocument]' => 'Решение от 01.06.2026',
            'electricity_tariff_period[notes]' => 'После исправления',
        ]);

        $updatedTariffPeriod = $this->findTariffPeriodByUuid($tariffPeriod->getUuid());

        self::assertInstanceOf(ElectricityTariffPeriod::class, $updatedTariffPeriod);
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-periods/%s', $updatedTariffPeriod->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('2026-06-01', $updatedTariffPeriod->getValidFrom()->format('Y-m-d'));
        self::assertNull($updatedTariffPeriod->getValidTo());
        self::assertSame('Решение от 01.06.2026', $updatedTariffPeriod->getSourceDocument());
        self::assertSame('После исправления', $updatedTariffPeriod->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $updatedTariffPeriod->getUpdatedBy()?->getUuid()->toRfc4122());
    }

    public function testAdminCanSoftDeleteTariffPeriod(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $tariffPeriodUuid = $tariffPeriod->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-periods/%s/edit', $tariffPeriodUuid));

        $this->assertResponseIsSuccessful();

        $client->submitForm('Удалить');
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-profiles/%s', $tariffProfile->getUuid()), Response::HTTP_SEE_OTHER);

        $deletedTariffPeriod = $this->findTariffPeriodByUuid($tariffPeriodUuid);

        self::assertInstanceOf(ElectricityTariffPeriod::class, $deletedTariffPeriod);
        self::assertNotNull($deletedTariffPeriod->getDeletedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $deletedTariffPeriod->getDeletedBy()?->getUuid()->toRfc4122());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Тарифные периоды пока не созданы.');
    }

    public function testAdminCanAddAndUpdateTariffRate(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона', 10);
        $consumptionBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма', 10);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-periods/%s', $tariffPeriod->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select.js-searchable-select[name="electricity_tariff_rate[tariffZone]"]');

        $client->submitForm('OK', [
            'electricity_tariff_rate[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_tariff_rate[consumptionBand]' => $consumptionBand->getUuid()->toRfc4122(),
            'electricity_tariff_rate[rate]' => '5,123456',
        ]);

        $tariffRate = $this->findTariffRate($workspace, $tariffPeriod, $tariffZone, $consumptionBand);

        self::assertInstanceOf(ElectricityTariffRate::class, $tariffRate);
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-periods/%s', $tariffPeriod->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('5.123456', $tariffRate->getRate());
        self::assertSame($admin->getUuid()->toRfc4122(), $tariffRate->getCreatedBy()?->getUuid()->toRfc4122());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'social_norm');

        $client->submitForm('OK', [
            'electricity_tariff_rate[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_tariff_rate[consumptionBand]' => $consumptionBand->getUuid()->toRfc4122(),
            'electricity_tariff_rate[rate]' => '6.25',
        ]);

        $updatedTariffRate = $this->findTariffRate($workspace, $tariffPeriod, $tariffZone, $consumptionBand);

        self::assertInstanceOf(ElectricityTariffRate::class, $updatedTariffRate);
        self::assertSame(1, $this->countTariffRates($tariffPeriod));
        self::assertSame('6.25', $updatedTariffRate->getRate());
        self::assertSame($admin->getUuid()->toRfc4122(), $updatedTariffRate->getUpdatedBy()?->getUuid()->toRfc4122());
    }

    public function testAdminCannotAddTariffRateWithInvalidRate(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона', 10);
        $consumptionBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма', 10);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-periods/%s', $tariffPeriod->getUuid()));
        $client->submitForm('OK', [
            'electricity_tariff_rate[tariffZone]' => $tariffZone->getUuid()->toRfc4122(),
            'electricity_tariff_rate[consumptionBand]' => $consumptionBand->getUuid()->toRfc4122(),
            'electricity_tariff_rate[rate]' => '-1',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Ставка должна быть неотрицательным числом с точностью до 6 знаков.');
        self::assertSame(0, $this->countTariffRates($tariffPeriod));
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

    private function createTariffPeriod(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        string $validFrom,
        ?string $validTo = null,
        ?string $sourceDocument = null,
    ): ElectricityTariffPeriod {
        $tariffPeriod = (new ElectricityTariffPeriod($workspace, $tariffProfile, new DateTimeImmutable($validFrom)))
            ->setSourceDocument($sourceDocument);

        if ($validTo !== null) {
            $tariffPeriod->setValidTo(new DateTimeImmutable($validTo));
        }

        $this->entityManager()->persist($tariffPeriod);
        $this->entityManager()->flush();

        return $tariffPeriod;
    }

    private function createTariffZone(Workspace $workspace, string $code, string $name, int $sortOrder): ElectricityTariffZone
    {
        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode($code)
            ->setName($name)
            ->setSortOrder($sortOrder);

        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->flush();

        return $tariffZone;
    }

    private function createConsumptionBand(Workspace $workspace, string $code, string $name, int $sortOrder): ElectricityConsumptionBand
    {
        $consumptionBand = (new ElectricityConsumptionBand($workspace))
            ->setCode($code)
            ->setName($name)
            ->setSortOrder($sortOrder);

        $this->entityManager()->persist($consumptionBand);
        $this->entityManager()->flush();

        return $consumptionBand;
    }

    private function findTariffPeriodByUuid(Uuid $uuid): ?ElectricityTariffPeriod
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffPeriod::class)
            ->find($uuid);
    }

    private function findTariffPeriodByValidFrom(ElectricityTariffProfile $tariffProfile, string $validFrom): ?ElectricityTariffPeriod
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffPeriod::class)
            ->findOneBy([
                'tariffProfile' => $tariffProfile,
                'validFrom' => new DateTimeImmutable($validFrom),
            ]);
    }

    private function countTariffPeriods(ElectricityTariffProfile $tariffProfile): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityTariffPeriod::class)
            ->createQueryBuilder('tariffPeriod')
            ->select('COUNT(tariffPeriod.uuid)')
            ->andWhere('tariffPeriod.tariffProfile = :tariffProfile')
            ->setParameter('tariffProfile', $tariffProfile)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function findTariffRate(
        Workspace $workspace,
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffZone $tariffZone,
        ElectricityConsumptionBand $consumptionBand,
    ): ?ElectricityTariffRate {
        return $this->entityManager()
            ->getRepository(ElectricityTariffRate::class)
            ->findOneBy([
                'workspace' => $workspace,
                'tariffPeriod' => $tariffPeriod,
                'tariffZone' => $tariffZone,
                'consumptionBand' => $consumptionBand,
            ]);
    }

    private function countTariffRates(ElectricityTariffPeriod $tariffPeriod): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityTariffRate::class)
            ->createQueryBuilder('tariffRate')
            ->select('COUNT(tariffRate.rate)')
            ->andWhere('tariffRate.tariffPeriod = :tariffPeriod')
            ->setParameter('tariffPeriod', $tariffPeriod)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
