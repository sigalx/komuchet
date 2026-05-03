<?php

namespace App\Tests;

use App\Entity\ElectricityConsumptionBand;
use App\Entity\Workspace;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminElectricityConsumptionBandControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/electricity-consumption-bands');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyConsumptionBandsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-consumption-bands');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Диапазоны потребления');
        $this->assertSelectorTextContains('td', 'Диапазоны потребления пока не созданы.');
    }

    public function testAdminCanSortAndPaginateConsumptionBandsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $consumptionBand = (new ElectricityConsumptionBand($workspace))
                ->setCode(sprintf('band_%02d', $i))
                ->setName(sprintf('Диапазон %02d', $i))
                ->setSortOrder($i);

            $this->entityManager()->persist($consumptionBand);
        }

        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-consumption-bands?sort=code&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $band55Position = strpos($content, 'band_55');
        $band54Position = strpos($content, 'band_54');
        self::assertNotFalse($band55Position);
        self::assertNotFalse($band54Position);
        self::assertLessThan($band54Position, $band55Position);
        $this->assertSelectorExists('a[href="/admin/electricity-consumption-bands?sort=code&dir=asc&page=1"]');

        $client->request('GET', '/admin/electricity-consumption-bands?sort=code&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'band_05');
        $this->assertSelectorTextNotContains('body', 'band_55');
    }

    public function testAdminCanCreateConsumptionBand(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-consumption-bands/new');

        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_consumption_band[code]' => 'social_norm',
            'electricity_consumption_band[name]' => 'Социальная норма',
            'electricity_consumption_band[description]' => 'Льготный диапазон потребления',
            'electricity_consumption_band[sortOrder]' => '10',
        ]);

        $consumptionBand = $this->findConsumptionBandByCode('social_norm');

        self::assertInstanceOf(ElectricityConsumptionBand::class, $consumptionBand);
        $this->assertResponseRedirects(sprintf('/admin/electricity-consumption-bands/%s', $consumptionBand->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $consumptionBand->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame('Социальная норма', $consumptionBand->getName());
        self::assertSame('Льготный диапазон потребления', $consumptionBand->getDescription());
        self::assertSame(10, $consumptionBand->getSortOrder());
        self::assertSame($admin->getUuid()->toRfc4122(), $consumptionBand->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertNull($consumptionBand->getDeletedAt());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Диапазон потребления Социальная норма');
        $this->assertSelectorTextContains('body', 'social_norm');
        $this->assertSelectorTextContains('body', 'Льготный диапазон потребления');
    }

    public function testAdminCannotCreateDuplicateActiveConsumptionBandCode(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма');
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-consumption-bands/new');
        $client->submitForm('Сохранить', [
            'electricity_consumption_band[code]' => 'social_norm',
            'electricity_consumption_band[name]' => 'Другой диапазон',
            'electricity_consumption_band[description]' => '',
            'electricity_consumption_band[sortOrder]' => '20',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Активный диапазон потребления с таким кодом уже существует.');
        self::assertSame(1, $this->countConsumptionBandsByCode('social_norm'));
    }

    public function testAdminCannotCreateConsumptionBandWithInvalidCodeOrSortOrder(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-consumption-bands/new');
        $client->submitForm('Сохранить', [
            'electricity_consumption_band[code]' => 'Social Norm',
            'electricity_consumption_band[name]' => 'Социальная норма',
            'electricity_consumption_band[description]' => '',
            'electricity_consumption_band[sortOrder]' => '-1',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Код должен начинаться с латинской буквы или цифры');
        $this->assertSelectorTextContains('body', 'Порядок сортировки не может быть отрицательным.');
        self::assertSame(0, $this->countConsumptionBandsByCode('Social Norm'));
    }

    public function testAdminCanEditConsumptionBand(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $consumptionBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма', 'До исправления', 10);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-consumption-bands/%s/edit', $consumptionBand->getUuid()));

        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_consumption_band[code]' => 'above_social_norm',
            'electricity_consumption_band[name]' => 'Сверх социальной нормы',
            'electricity_consumption_band[description]' => 'После исправления',
            'electricity_consumption_band[sortOrder]' => '20',
        ]);

        $updatedConsumptionBand = $this->findConsumptionBandByUuid($consumptionBand->getUuid());

        self::assertInstanceOf(ElectricityConsumptionBand::class, $updatedConsumptionBand);
        $this->assertResponseRedirects(sprintf('/admin/electricity-consumption-bands/%s', $updatedConsumptionBand->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('above_social_norm', $updatedConsumptionBand->getCode());
        self::assertSame('Сверх социальной нормы', $updatedConsumptionBand->getName());
        self::assertSame('После исправления', $updatedConsumptionBand->getDescription());
        self::assertSame(20, $updatedConsumptionBand->getSortOrder());
        self::assertSame($admin->getUuid()->toRfc4122(), $updatedConsumptionBand->getUpdatedBy()?->getUuid()->toRfc4122());
    }

    public function testAdminCanSoftDeleteConsumptionBand(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $consumptionBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма');
        $consumptionBandUuid = $consumptionBand->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-consumption-bands/%s/edit', $consumptionBandUuid));

        $this->assertResponseIsSuccessful();

        $client->submitForm('Удалить');
        $this->assertResponseRedirects('/admin/electricity-consumption-bands', Response::HTTP_SEE_OTHER);

        $deletedConsumptionBand = $this->findConsumptionBandByUuid($consumptionBandUuid);

        self::assertInstanceOf(ElectricityConsumptionBand::class, $deletedConsumptionBand);
        self::assertNotNull($deletedConsumptionBand->getDeletedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $deletedConsumptionBand->getDeletedBy()?->getUuid()->toRfc4122());

        $client->request('GET', '/admin/electricity-consumption-bands');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Диапазоны потребления пока не созданы.');
    }

    private function createConsumptionBand(Workspace $workspace, string $code, string $name, ?string $description = null, int $sortOrder = 100): ElectricityConsumptionBand
    {
        $consumptionBand = (new ElectricityConsumptionBand($workspace))
            ->setCode($code)
            ->setName($name)
            ->setDescription($description)
            ->setSortOrder($sortOrder);

        $this->entityManager()->persist($consumptionBand);
        $this->entityManager()->flush();

        return $consumptionBand;
    }

    private function findConsumptionBandByUuid(Uuid $uuid): ?ElectricityConsumptionBand
    {
        return $this->entityManager()
            ->getRepository(ElectricityConsumptionBand::class)
            ->find($uuid);
    }

    private function findConsumptionBandByCode(string $code): ?ElectricityConsumptionBand
    {
        return $this->entityManager()
            ->getRepository(ElectricityConsumptionBand::class)
            ->findOneBy(['code' => $code]);
    }

    private function countConsumptionBandsByCode(string $code): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityConsumptionBand::class)
            ->createQueryBuilder('consumptionBand')
            ->select('COUNT(consumptionBand.uuid)')
            ->andWhere('consumptionBand.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
