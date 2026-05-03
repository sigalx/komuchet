<?php

namespace App\Tests;

use App\Entity\ElectricityTariffZone;
use App\Entity\Workspace;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminElectricityTariffZoneControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/electricity-tariff-zones');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyTariffZonesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-tariff-zones');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Тарифные зоны');
        $this->assertSelectorTextContains('td', 'Тарифные зоны пока не созданы.');
    }

    public function testAdminCanSortAndPaginateTariffZonesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $tariffZone = (new ElectricityTariffZone($workspace))
                ->setCode(sprintf('zone_%02d', $i))
                ->setName(sprintf('Зона %02d', $i))
                ->setSortOrder($i);

            $this->entityManager()->persist($tariffZone);
        }

        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-tariff-zones?sort=code&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $zone55Position = strpos($content, 'zone_55');
        $zone54Position = strpos($content, 'zone_54');
        self::assertNotFalse($zone55Position);
        self::assertNotFalse($zone54Position);
        self::assertLessThan($zone54Position, $zone55Position);
        $this->assertSelectorExists('a[href="/admin/electricity-tariff-zones?sort=code&dir=asc&page=1"]');

        $client->request('GET', '/admin/electricity-tariff-zones?sort=code&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'zone_05');
        $this->assertSelectorTextNotContains('body', 'zone_55');
    }

    public function testAdminCanCreateTariffZone(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

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
        self::assertSame($workspace->getUuid()->toRfc4122(), $tariffZone->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame('Однотарифная зона', $tariffZone->getName());
        self::assertSame('Общий регистр', $tariffZone->getDescription());
        self::assertSame(10, $tariffZone->getSortOrder());
        self::assertSame($admin->getUuid()->toRfc4122(), $tariffZone->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertNull($tariffZone->getDeletedAt());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Тарифная зона Однотарифная зона');
        $this->assertSelectorTextContains('body', 'single');
        $this->assertSelectorTextContains('body', 'Общий регистр');
    }

    public function testAdminCannotCreateDuplicateActiveTariffZoneCode(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-tariff-zones/new');
        $client->submitForm('Сохранить', [
            'electricity_tariff_zone[code]' => 'single',
            'electricity_tariff_zone[name]' => 'Другая зона',
            'electricity_tariff_zone[description]' => '',
            'electricity_tariff_zone[sortOrder]' => '20',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Активная тарифная зона с таким кодом уже существует.');
        self::assertSame(1, $this->countTariffZonesByCode('single'));
    }

    public function testAdminCannotCreateTariffZoneWithInvalidCodeOrSortOrder(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-tariff-zones/new');
        $client->submitForm('Сохранить', [
            'electricity_tariff_zone[code]' => 'Day Zone',
            'electricity_tariff_zone[name]' => 'Дневная зона',
            'electricity_tariff_zone[description]' => '',
            'electricity_tariff_zone[sortOrder]' => '-1',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Код должен начинаться с латинской буквы или цифры');
        $this->assertSelectorTextContains('body', 'Порядок сортировки не может быть отрицательным.');
        self::assertSame(0, $this->countTariffZonesByCode('Day Zone'));
    }

    public function testAdminCanEditTariffZone(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона', 'До исправления', 10);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-zones/%s/edit', $tariffZone->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_tariff_zone[code]' => 'day',
            'electricity_tariff_zone[name]' => 'Дневная зона',
            'electricity_tariff_zone[description]' => 'После исправления',
            'electricity_tariff_zone[sortOrder]' => '20',
        ]);

        $updatedTariffZone = $this->findTariffZoneByUuid($tariffZone->getUuid());

        self::assertInstanceOf(ElectricityTariffZone::class, $updatedTariffZone);
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-zones/%s', $updatedTariffZone->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('day', $updatedTariffZone->getCode());
        self::assertSame('Дневная зона', $updatedTariffZone->getName());
        self::assertSame('После исправления', $updatedTariffZone->getDescription());
        self::assertSame(20, $updatedTariffZone->getSortOrder());
        self::assertSame($admin->getUuid()->toRfc4122(), $updatedTariffZone->getUpdatedBy()?->getUuid()->toRfc4122());
    }

    public function testAdminCanSoftDeleteTariffZone(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $tariffZoneUuid = $tariffZone->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-zones/%s/edit', $tariffZoneUuid));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Удалить');
        $this->assertResponseRedirects('/admin/electricity-tariff-zones', Response::HTTP_SEE_OTHER);

        $deletedTariffZone = $this->findTariffZoneByUuid($tariffZoneUuid);

        self::assertInstanceOf(ElectricityTariffZone::class, $deletedTariffZone);
        self::assertNotNull($deletedTariffZone->getDeletedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $deletedTariffZone->getDeletedBy()?->getUuid()->toRfc4122());

        $client->request('GET', '/admin/electricity-tariff-zones');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Тарифные зоны пока не созданы.');
    }

    private function createTariffZone(Workspace $workspace, string $code, string $name, ?string $description = null, int $sortOrder = 100): ElectricityTariffZone
    {
        $tariffZone = (new ElectricityTariffZone($workspace))
            ->setCode($code)
            ->setName($name)
            ->setDescription($description)
            ->setSortOrder($sortOrder);

        $this->entityManager()->persist($tariffZone);
        $this->entityManager()->flush();

        return $tariffZone;
    }

    private function findTariffZoneByUuid(Uuid $uuid): ?ElectricityTariffZone
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffZone::class)
            ->find($uuid);
    }

    private function findTariffZoneByCode(string $code): ?ElectricityTariffZone
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffZone::class)
            ->findOneBy(['code' => $code]);
    }

    private function countTariffZonesByCode(string $code): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityTariffZone::class)
            ->createQueryBuilder('tariffZone')
            ->select('COUNT(tariffZone.uuid)')
            ->andWhere('tariffZone.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
