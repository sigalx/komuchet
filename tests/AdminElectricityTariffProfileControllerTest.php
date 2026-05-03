<?php

namespace App\Tests;

use App\Entity\ElectricityTariffProfile;
use App\Entity\Workspace;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminElectricityTariffProfileControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/electricity-tariff-profiles');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyTariffProfilesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-tariff-profiles');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Тарифные профили');
        $this->assertSelectorTextContains('td', 'Тарифные профили пока не созданы.');
    }

    public function testAdminCanSortAndPaginateTariffProfilesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $tariffProfile = (new ElectricityTariffProfile($workspace))
                ->setCode(sprintf('profile_%02d', $i))
                ->setName(sprintf('Профиль %02d', $i));

            $this->entityManager()->persist($tariffProfile);
        }

        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-tariff-profiles?sort=code&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $profile55Position = strpos($content, 'profile_55');
        $profile54Position = strpos($content, 'profile_54');
        self::assertNotFalse($profile55Position);
        self::assertNotFalse($profile54Position);
        self::assertLessThan($profile54Position, $profile55Position);
        $this->assertSelectorExists('a[href="/admin/electricity-tariff-profiles?sort=code&dir=asc&page=1"]');

        $client->request('GET', '/admin/electricity-tariff-profiles?sort=code&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'profile_05');
        $this->assertSelectorTextNotContains('body', 'profile_55');
    }

    public function testAdminCanCreateTariffProfile(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-tariff-profiles/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_tariff_profile[code]' => 'snt',
            'electricity_tariff_profile[name]' => 'СНТ',
            'electricity_tariff_profile[description]' => 'Основной профиль для участков СНТ',
        ]);

        $tariffProfile = $this->findTariffProfileByCode('snt');

        self::assertInstanceOf(ElectricityTariffProfile::class, $tariffProfile);
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-profiles/%s', $tariffProfile->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $tariffProfile->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame('СНТ', $tariffProfile->getName());
        self::assertSame('Основной профиль для участков СНТ', $tariffProfile->getDescription());
        self::assertSame($admin->getUuid()->toRfc4122(), $tariffProfile->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertNull($tariffProfile->getDeletedAt());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Тарифный профиль СНТ');
        $this->assertSelectorTextContains('body', 'snt');
        $this->assertSelectorTextContains('body', 'Основной профиль для участков СНТ');
    }

    public function testAdminCannotCreateDuplicateActiveTariffProfileCode(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-tariff-profiles/new');
        $client->submitForm('Сохранить', [
            'electricity_tariff_profile[code]' => 'snt',
            'electricity_tariff_profile[name]' => 'Другой профиль',
            'electricity_tariff_profile[description]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Активный тарифный профиль с таким кодом уже существует.');
        self::assertSame(1, $this->countTariffProfilesByCode('snt'));
    }

    public function testAdminCannotCreateTariffProfileWithInvalidCode(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/electricity-tariff-profiles/new');
        $client->submitForm('Сохранить', [
            'electricity_tariff_profile[code]' => 'SNT Profile',
            'electricity_tariff_profile[name]' => 'СНТ',
            'electricity_tariff_profile[description]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Код должен начинаться с латинской буквы или цифры');
        self::assertSame(0, $this->countTariffProfilesByCode('SNT Profile'));
    }

    public function testAdminCanEditTariffProfile(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ', 'До исправления');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-profiles/%s/edit', $tariffProfile->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'electricity_tariff_profile[code]' => 'electric_heating',
            'electricity_tariff_profile[name]' => 'Электроотопление',
            'electricity_tariff_profile[description]' => 'После исправления',
        ]);

        $updatedTariffProfile = $this->findTariffProfileByUuid($tariffProfile->getUuid());

        self::assertInstanceOf(ElectricityTariffProfile::class, $updatedTariffProfile);
        $this->assertResponseRedirects(sprintf('/admin/electricity-tariff-profiles/%s', $updatedTariffProfile->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('electric_heating', $updatedTariffProfile->getCode());
        self::assertSame('Электроотопление', $updatedTariffProfile->getName());
        self::assertSame('После исправления', $updatedTariffProfile->getDescription());
        self::assertSame($admin->getUuid()->toRfc4122(), $updatedTariffProfile->getUpdatedBy()?->getUuid()->toRfc4122());
    }

    public function testAdminCanSoftDeleteTariffProfile(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $tariffProfileUuid = $tariffProfile->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/electricity-tariff-profiles/%s/edit', $tariffProfileUuid));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Удалить');
        $this->assertResponseRedirects('/admin/electricity-tariff-profiles', Response::HTTP_SEE_OTHER);

        $deletedTariffProfile = $this->findTariffProfileByUuid($tariffProfileUuid);

        self::assertInstanceOf(ElectricityTariffProfile::class, $deletedTariffProfile);
        self::assertNotNull($deletedTariffProfile->getDeletedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $deletedTariffProfile->getDeletedBy()?->getUuid()->toRfc4122());

        $client->request('GET', '/admin/electricity-tariff-profiles');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Тарифные профили пока не созданы.');
    }

    private function createTariffProfile(Workspace $workspace, string $code, string $name, ?string $description = null): ElectricityTariffProfile
    {
        $tariffProfile = (new ElectricityTariffProfile($workspace))
            ->setCode($code)
            ->setName($name)
            ->setDescription($description);

        $this->entityManager()->persist($tariffProfile);
        $this->entityManager()->flush();

        return $tariffProfile;
    }

    private function findTariffProfileByUuid(Uuid $uuid): ?ElectricityTariffProfile
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffProfile::class)
            ->find($uuid);
    }

    private function findTariffProfileByCode(string $code): ?ElectricityTariffProfile
    {
        return $this->entityManager()
            ->getRepository(ElectricityTariffProfile::class)
            ->findOneBy(['code' => $code]);
    }

    private function countTariffProfilesByCode(string $code): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityTariffProfile::class)
            ->createQueryBuilder('tariffProfile')
            ->select('COUNT(tariffProfile.uuid)')
            ->andWhere('tariffProfile.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
