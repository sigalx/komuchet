<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\BillingSettings;
use App\Entity\Workspace;
use Symfony\Component\HttpFoundation\Response;

final class AdminBillingSettingsControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/billing-settings');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanCreateMissingBillingSettingsForWorkspace(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('main', 'СНТ Тест');
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-settings');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Настройки расчетов');
        $this->assertSelectorExists('select[name="billing_settings[timezone]"] option[value="Europe/Kaliningrad"]');
        $this->assertSelectorExists('select[name="billing_settings[timezone]"] option[value="Europe/Moscow"][selected]');
        $this->assertSelectorExists('select[name="billing_settings[timezone]"] option[value="Asia/Anadyr"]');
        $this->assertSelectorTextContains('select[name="billing_settings[timezone]"] option[value="Europe/Moscow"]', '(UTC+03:00) Москва, Санкт-Петербург');
        $this->assertSelectorTextContains('select[name="billing_settings[timezone]"] option[value="Asia/Anadyr"]', '(UTC+12:00) Анадырь, Певек');

        $client->submitForm('Сохранить', [
            'billing_settings[associationName]' => 'СНТ Ромашка',
            'billing_settings[timezone]' => 'Europe/Moscow',
            'billing_settings[invoiceGenerationDay]' => '5',
            'billing_settings[readingFreshnessWindowDays]' => '15',
        ]);

        $settings = $this->findBillingSettings($workspace);
        $workspace = $this->findWorkspace($workspace);

        self::assertInstanceOf(BillingSettings::class, $settings);
        self::assertInstanceOf(Workspace::class, $workspace);
        $this->assertResponseRedirects('/admin/billing-settings', Response::HTTP_SEE_OTHER);
        self::assertSame('СНТ Ромашка', $settings->getAssociationName());
        self::assertSame('Europe/Moscow', $workspace->getTimezone());
        self::assertSame(5, $settings->getInvoiceGenerationDay());
        self::assertSame(15, $settings->getReadingFreshnessWindowDays());
        self::assertSame($admin->getUuid()->toRfc4122(), $settings->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertSame($admin->getUuid()->toRfc4122(), $settings->getUpdatedBy()?->getUuid()->toRfc4122());
        self::assertSame(1, $this->countAuditLogs('billing_settings.created'));
    }

    public function testAdminCanUpdateBillingSettings(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createBillingSettings($workspace, 'СНТ Старое');
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-settings');
        $client->submitForm('Сохранить', [
            'billing_settings[associationName]' => 'СНТ Новое',
            'billing_settings[timezone]' => 'Europe/Samara',
            'billing_settings[invoiceGenerationDay]' => '7',
            'billing_settings[readingFreshnessWindowDays]' => '20',
        ]);

        $settings = $this->findBillingSettings($workspace);
        $workspace = $this->findWorkspace($workspace);

        self::assertInstanceOf(BillingSettings::class, $settings);
        self::assertInstanceOf(Workspace::class, $workspace);
        $this->assertResponseRedirects('/admin/billing-settings', Response::HTTP_SEE_OTHER);
        self::assertSame('СНТ Новое', $settings->getAssociationName());
        self::assertSame('Europe/Samara', $workspace->getTimezone());
        self::assertSame(7, $settings->getInvoiceGenerationDay());
        self::assertSame(20, $settings->getReadingFreshnessWindowDays());
        self::assertSame($admin->getUuid()->toRfc4122(), $settings->getUpdatedBy()?->getUuid()->toRfc4122());
        self::assertSame(1, $this->countAuditLogs('billing_settings.updated'));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'СНТ Новое');
        $this->assertSelectorTextContains('body', '(UTC+04:00) Самара, Ульяновск');
    }

    public function testAdminCannotSaveInvalidBillingSettings(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createBillingSettings($workspace, 'СНТ Тест');
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/billing-settings');
        $form = $crawler->selectButton('Сохранить')->form([
            'billing_settings[associationName]' => '',
            'billing_settings[timezone]' => 'Europe/Moscow',
            'billing_settings[invoiceGenerationDay]' => '31',
            'billing_settings[readingFreshnessWindowDays]' => '90',
        ]);
        $form->disableValidation();
        $form['billing_settings[timezone]'] = 'Not/AZone';
        $client->submit($form);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Укажите название хозяйства.');
        $this->assertSelectorTextContains('body', 'Выберите часовой пояс из списка.');
        $this->assertSelectorTextContains('body', 'День формирования должен быть от 1 до 28.');
        $this->assertSelectorTextContains('body', 'Окно актуальности должно быть от 1 до 60 дней.');
    }

    private function createBillingSettings(Workspace $workspace, string $associationName): BillingSettings
    {
        $settings = new BillingSettings($workspace, $associationName);

        $this->entityManager()->persist($settings);
        $this->entityManager()->flush();

        return $settings;
    }

    private function findBillingSettings(Workspace $workspace): ?BillingSettings
    {
        return $this->entityManager()
            ->getRepository(BillingSettings::class)
            ->find($workspace);
    }

    private function findWorkspace(Workspace $workspace): ?Workspace
    {
        return $this->entityManager()
            ->getRepository(Workspace::class)
            ->find($workspace->getUuid());
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
