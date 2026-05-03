<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountElectricityTariffProfileAssignment;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Accrual;
use App\Entity\BillingRun;
use App\Entity\BillingRunAccountIssue;
use App\Entity\ElectricityAccrualLine;
use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleAllScope;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\BillingRunAccountIssueCloseReason;
use App\Enum\BillingRunAccountIssueType;
use App\Enum\BillingRunKind;
use App\Enum\AccrualType;
use App\Enum\ElectricityConsumptionBandRuleScopeMode;
use App\Enum\ElectricityMeterReadingSource;
use App\Enum\SubscriberAccountAccessRole;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminBillingRunControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/billing-runs');

        $this->assertResponseRedirects('/login');
    }

    public function testAnonymousUserIsRedirectedToLoginFromBillingRunIssues(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/billing-run-issues');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyBillingRunsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-runs');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Расчеты');
        $this->assertSelectorTextContains('td', 'Расчеты пока не создавались.');
    }

    public function testAdminCanSortAndPaginateBillingRunsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $periodStart = new DateTimeImmutable('2026-01-01');

        for ($i = 1; $i <= 55; ++$i) {
            $start = $periodStart->modify(sprintf('+%d months', $i - 1));
            $this->createBillingRun($workspace, $start->format('Y-m-d'), $start->modify('+1 month')->format('Y-m-d'));
        }

        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-runs?sort=period_start&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $july2030Position = strpos($content, '01.07.2030 - 01.08.2030');
        $june2030Position = strpos($content, '01.06.2030 - 01.07.2030');
        self::assertNotFalse($july2030Position);
        self::assertNotFalse($june2030Position);
        self::assertLessThan($june2030Position, $july2030Position);
        $this->assertSelectorExists('a[href="/admin/billing-runs?sort=period_start&dir=asc&page=1"]');

        $client->request('GET', '/admin/billing-runs?sort=period_start&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', '01.05.2026 - 01.06.2026');
        $this->assertSelectorTextNotContains('body', '01.07.2030 - 01.08.2030');
    }

    public function testAdminCanFilterBillingRunsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $draftWithIssue = $this->createBillingRun($workspace, '2026-05-01', '2026-06-01');
        $draftWithoutIssue = $this->createBillingRun($workspace, '2026-04-01', '2026-05-01');
        $draftWithAccrual = $this->createBillingRun($workspace, '2026-03-01', '2026-04-01');
        $postedRun = $this->createBillingRun($workspace, '2026-02-01', '2026-03-01');
        $cancelledRun = $this->createBillingRun($workspace, '2026-01-01', '2026-02-01');
        $this->entityManager()->persist(new BillingRunAccountIssue(
            $workspace,
            $draftWithIssue,
            $account,
            BillingRunAccountIssueType::MissingReading,
            'Нет показаний для фильтра расчетов.'
        ));
        $this->createDraftAccrual($workspace, $account, $draftWithAccrual, '400.00', '2026-03-01', '2026-04-01');
        $draftWithAccrual->markAccrualsGenerated($admin);
        $postedRun->markAccrualsGenerated($admin);
        $postedRun->post($admin);
        $cancelledRun->cancel('Ошибочный расчет для фильтра.', $admin);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/billing-runs?kind=%s', BillingRunKind::Electricity->value));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf(
            'select[name="kind"] option[value="%s"][selected]',
            BillingRunKind::Electricity->value
        ));
        $this->assertSelectorTextContains('body', '01.05.2026 - 01.06.2026');

        $client->request('GET', '/admin/billing-runs?status=posted');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '01.02.2026 - 01.03.2026');
        $this->assertSelectorTextContains('body', 'Проведен');
        $this->assertSelectorTextNotContains('body', '01.05.2026 - 01.06.2026');

        $client->request('GET', '/admin/billing-runs?status=cancelled');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '01.01.2026 - 01.02.2026');
        $this->assertSelectorTextContains('body', 'Отменен');
        $this->assertSelectorTextNotContains('body', '01.02.2026 - 01.03.2026');

        $client->request('GET', '/admin/billing-runs?status=draft');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '01.05.2026 - 01.06.2026');
        $this->assertSelectorTextContains('body', 'Черновик');
        $this->assertSelectorTextNotContains('body', '01.02.2026 - 01.03.2026');
        $this->assertSelectorTextNotContains('body', '01.01.2026 - 01.02.2026');

        $client->request('GET', '/admin/billing-runs?issues=with_open');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '01.05.2026 - 01.06.2026');
        $this->assertSelectorTextNotContains('body', '01.04.2026 - 01.05.2026');

        $client->request('GET', '/admin/billing-runs?issues=without_open');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '01.04.2026 - 01.05.2026');
        $this->assertSelectorTextNotContains('body', '01.05.2026 - 01.06.2026');

        $client->request('GET', '/admin/billing-runs?accruals=with');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '01.03.2026 - 01.04.2026');
        $this->assertSelectorTextNotContains('body', '01.05.2026 - 01.06.2026');

        $client->request('GET', '/admin/billing-runs?accruals=without');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '01.05.2026 - 01.06.2026');
        $this->assertSelectorTextNotContains('body', '01.03.2026 - 01.04.2026');

        $client->request('GET', '/admin/billing-runs?period_start_from=01.03.2026&period_start_to=31.03.2026');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '01.03.2026 - 01.04.2026');
        $this->assertSelectorTextNotContains('body', '01.04.2026 - 01.05.2026');
        $this->assertSelectorExists('input[name="period_start_from"][value="01.03.2026"]');

        $workspaceTimezone = new DateTimeZone($workspace->getTimezone());
        $today = (new DateTimeImmutable('now', $workspaceTimezone))->format('d.m.Y');
        $tomorrow = (new DateTimeImmutable('tomorrow', $workspaceTimezone))->format('d.m.Y');

        $client->request('GET', sprintf('/admin/billing-runs?generated_at_from=%s&generated_at_to=%s', $today, $today));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '01.05.2026 - 01.06.2026');
        $this->assertSelectorExists(sprintf('input[name="generated_at_from"][value="%s"]', $today));

        $client->request('GET', sprintf('/admin/billing-runs?generated_at_from=%s', $tomorrow));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Расчеты не найдены.');

        self::assertTrue($draftWithIssue->isDraft());
        self::assertTrue($draftWithoutIssue->isDraft());
        self::assertTrue($draftWithAccrual->isDraft());
        self::assertTrue($postedRun->isPosted());
        self::assertTrue($cancelledRun->isCancelled());
    }

    public function testAdminSeesBillingRunGeneratedAtInWorkspaceTimezone(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $workspace->setTimezone('Europe/Samara');
        $billingRun = $this->createBillingRun($workspace, '2026-05-01', '2026-06-01');
        $this->entityManager()->flush();
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE billing_runs SET generated_at = ? WHERE uuid = ?',
            ['2026-05-10 20:15:00+00', $billingRun->getUuid()->toRfc4122()],
        );
        $this->entityManager()->refresh($billingRun);
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-runs');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '11.05.2026 00:15');
    }

    public function testAdminCanSeeEmptyBillingRunIssuesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-run-issues');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Проблемы расчетов');
        $this->assertSelectorTextContains('td', 'Открытых проблем расчетов нет.');
        $this->assertSelectorExists('a[href="/admin/billing-run-issues"].active');
    }

    public function testAdminCanSortAndPaginateBillingRunIssuesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $billingRun = $this->createBillingRun($workspace, '2026-05-01', '2026-06-01');

        for ($i = 1; $i <= 55; ++$i) {
            $account = $this->createAccount($workspace, sprintf('9-%03d', $i));
            $this->entityManager()->persist(new BillingRunAccountIssue(
                $workspace,
                $billingRun,
                $account,
                BillingRunAccountIssueType::MissingReading,
                sprintf('Нет показаний для участка 9-%03d.', $i)
            ));
        }

        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-run-issues?sort=account_number&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $account55Position = strpos($content, 'Нет показаний для участка 9-055.');
        $account54Position = strpos($content, 'Нет показаний для участка 9-054.');
        self::assertNotFalse($account55Position);
        self::assertNotFalse($account54Position);
        self::assertLessThan($account54Position, $account55Position);
        $this->assertSelectorExists('a[href="/admin/billing-run-issues?sort=account_number&dir=asc&page=1"]');

        $client->request('GET', '/admin/billing-run-issues?sort=account_number&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'Нет показаний для участка 9-005.');
        $this->assertSelectorTextNotContains('body', 'Нет показаний для участка 9-055.');
    }

    public function testAdminCanSeeOpenBillingRunIssuesForCurrentWorkspace(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);

        $missingReadingIssue = $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::MissingReading);

        self::assertInstanceOf(BillingRunAccountIssue::class, $missingReadingIssue);

        $missingReadingIssue->close(BillingRunAccountIssueCloseReason::Ignored, 'Проверено вручную.');
        $this->entityManager()->flush();

        $otherWorkspace = $this->createWorkspace('zz-other', 'Другое хозяйство');
        $otherAccount = $this->createAccount($otherWorkspace, '9-999');
        $otherBillingRun = $this->createBillingRun($otherWorkspace, '2026-05-01', '2026-06-01');
        $this->entityManager()->persist(new BillingRunAccountIssue(
            $otherWorkspace,
            $otherBillingRun,
            $otherAccount,
            BillingRunAccountIssueType::CalculationError,
            'Чужая проблема не должна быть видна.'
        ));
        $this->entityManager()->flush();

        $client->request('GET', '/admin/billing-run-issues');
        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Открытые проблемы по всем расчетам текущего хозяйства.');
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', 'Нет тарифа');
        $this->assertSelectorTextContains('body', '01.05.2026 - 01.06.2026');
        $this->assertSelectorExists(sprintf(
            'a[href="/admin/billing-runs/%s"]',
            $billingRun->getUuid()->toRfc4122()
        ));
        self::assertStringNotContainsString('У участка нет активного электросчетчика.', $content);
        self::assertStringNotContainsString('9-999', $content);
        self::assertStringNotContainsString('Чужая проблема не должна быть видна.', $content);
    }

    public function testAdminCanFilterOpenBillingRunIssues(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account123 = $this->createAccount($workspace, '9-123');
        $account124 = $this->createAccount($workspace, '9-124');
        $mayBillingRun = $this->createBillingRun($workspace, '2026-05-01', '2026-06-01');
        $aprilBillingRun = $this->createBillingRun($workspace, '2026-04-01', '2026-05-01');

        $this->entityManager()->persist(new BillingRunAccountIssue(
            $workspace,
            $mayBillingRun,
            $account123,
            BillingRunAccountIssueType::MissingTariff,
            'Нет тарифа для тестового фильтра.'
        ));
        $this->entityManager()->persist(new BillingRunAccountIssue(
            $workspace,
            $mayBillingRun,
            $account124,
            BillingRunAccountIssueType::CalculationError,
            'Ошибка расчета для тестового фильтра.'
        ));
        $this->entityManager()->persist(new BillingRunAccountIssue(
            $workspace,
            $aprilBillingRun,
            $account124,
            BillingRunAccountIssueType::MissingReading,
            'Нет показаний для тестового фильтра.'
        ));
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-run-issues', [
            'account_uuid' => $account123->getUuid()->toRfc4122(),
        ]);
        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf(
            'select[name="account_uuid"] option[value="%s"][selected]',
            $account123->getUuid()->toRfc4122()
        ));
        self::assertStringContainsString('Нет тарифа для тестового фильтра.', $content);
        self::assertStringNotContainsString('Ошибка расчета для тестового фильтра.', $content);
        self::assertStringNotContainsString('Нет показаний для тестового фильтра.', $content);

        $client->request('GET', '/admin/billing-run-issues', [
            'issue_type' => BillingRunAccountIssueType::CalculationError->value,
        ]);
        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf(
            'select[name="issue_type"] option[value="%s"][selected]',
            BillingRunAccountIssueType::CalculationError->value
        ));
        self::assertStringContainsString('Ошибка расчета для тестового фильтра.', $content);
        self::assertStringNotContainsString('Нет тарифа для тестового фильтра.', $content);
        self::assertStringNotContainsString('Нет показаний для тестового фильтра.', $content);

        $client->request('GET', '/admin/billing-run-issues', [
            'billing_run_uuid' => $aprilBillingRun->getUuid()->toRfc4122(),
        ]);
        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf(
            'select[name="billing_run_uuid"] option[value="%s"][selected]',
            $aprilBillingRun->getUuid()->toRfc4122()
        ));
        self::assertStringContainsString('Нет показаний для тестового фильтра.', $content);
        self::assertStringNotContainsString('Нет тарифа для тестового фильтра.', $content);
        self::assertStringNotContainsString('Ошибка расчета для тестового фильтра.', $content);

        $client->request('GET', '/admin/billing-run-issues', [
            'billing_run_uuid' => $mayBillingRun->getUuid()->toRfc4122(),
            'account_uuid' => $account124->getUuid()->toRfc4122(),
            'issue_type' => BillingRunAccountIssueType::CalculationError->value,
        ]);
        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Ошибка расчета для тестового фильтра.', $content);
        self::assertStringNotContainsString('Нет тарифа для тестового фильтра.', $content);
        self::assertStringNotContainsString('Нет показаний для тестового фильтра.', $content);
    }

    public function testAdminCanCloseBillingRunIssueFromGlobalListAndKeepFilters(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account123 = $this->createAccount($workspace, '9-123');
        $account124 = $this->createAccount($workspace, '9-124');
        $billingRun = $this->createBillingRun($workspace, '2026-05-01', '2026-06-01');
        $issue = new BillingRunAccountIssue(
            $workspace,
            $billingRun,
            $account123,
            BillingRunAccountIssueType::MissingTariff,
            'Нет тарифа для закрытия из общего списка.'
        );

        $this->entityManager()->persist($issue);
        $this->entityManager()->persist(new BillingRunAccountIssue(
            $workspace,
            $billingRun,
            $account124,
            BillingRunAccountIssueType::CalculationError,
            'Другая проблема должна остаться открытой.'
        ));
        $this->entityManager()->flush();
        $issueUuid = $issue->getUuid();
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-run-issues', [
            'account_uuid' => $account123->getUuid()->toRfc4122(),
        ]);
        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('Нет тарифа для закрытия из общего списка.', $content);
        self::assertStringNotContainsString('Другая проблема должна остаться открытой.', $content);

        $client->submitForm('Закрыть', [
            'billing_run_account_issue_close[reason]' => BillingRunAccountIssueCloseReason::Ignored->value,
            'billing_run_account_issue_close[comment]' => 'Закрыто из общего списка.',
        ]);

        $this->assertResponseRedirects(
            sprintf('/admin/billing-run-issues?account_uuid=%s', $account123->getUuid()->toRfc4122()),
            Response::HTTP_SEE_OTHER
        );
        $issue = $this->entityManager()->getRepository(BillingRunAccountIssue::class)->find($issueUuid);

        self::assertInstanceOf(BillingRunAccountIssue::class, $issue);
        self::assertFalse($issue->isOpen());
        self::assertSame(BillingRunAccountIssueCloseReason::Ignored, $issue->getCloseReason());
        self::assertSame('Закрыто из общего списка.', $issue->getCloseComment());
        self::assertSame($admin->getUuid()->toRfc4122(), $issue->getClosedBy()?->getUuid()->toRfc4122());
        self::assertSame(1, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Проблема закрыта.');
        $this->assertSelectorTextContains('td', 'Открытых проблем расчетов нет.');
        $this->assertSelectorExists(sprintf(
            'select[name="account_uuid"] option[value="%s"][selected]',
            $account123->getUuid()->toRfc4122()
        ));
    }

    public function testAdminCanCreateBillingRunDraft(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-runs/new');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="billing_run[periodStart]"][placeholder="дд.мм.гггг"]');
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="billing_run[periodEnd]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Сохранить', [
            'billing_run[kind]' => BillingRunKind::Electricity->value,
            'billing_run[periodStart]' => '01.05.2026',
            'billing_run[periodEnd]' => '01.06.2026',
        ]);

        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $billingRun->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame(BillingRunKind::Electricity, $billingRun->getKind());
        self::assertSame('2026-05-01', $billingRun->getPeriodStart()->format('Y-m-d'));
        self::assertSame('2026-06-01', $billingRun->getPeriodEnd()->format('Y-m-d'));
        self::assertSame($admin->getUuid()->toRfc4122(), $billingRun->getGeneratedBy()?->getUuid()->toRfc4122());
        self::assertTrue($billingRun->isDraft());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Расчет');
        $this->assertSelectorTextContains('body', 'Электроэнергия');
        $this->assertSelectorTextContains('body', 'Черновик');
        $this->assertSelectorTextContains('body', 'Сценарий конца месяца');
        $this->assertSelectorTextContains('body', 'Следующее действие: сгенерировать начисления');

        $client->request('GET', '/admin/billing-runs');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '01.05.2026 - 01.06.2026');
        $this->assertSelectorTextContains('body', 'Черновик');
        $this->assertSelectorTextContains('body', 'Следующее действие: сгенерировать начисления');
    }

    public function testAdminCannotCreateBillingRunWithInvalidPeriod(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-runs/new');
        $client->submitForm('Сохранить', [
            'billing_run[kind]' => BillingRunKind::Electricity->value,
            'billing_run[periodStart]' => '01.06.2026',
            'billing_run[periodEnd]' => '01.05.2026',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Конец периода должен быть позже начала периода.');
        self::assertSame(0, $this->countBillingRuns($workspace));
    }

    public function testAdminCannotCreateDuplicateActiveBillingRun(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createBillingRun($workspace, '2026-05-01', '2026-06-01');
        $client->loginUser($admin);

        $client->request('GET', '/admin/billing-runs/new');
        $client->submitForm('Сохранить', [
            'billing_run[kind]' => BillingRunKind::Electricity->value,
            'billing_run[periodStart]' => '01.05.2026',
            'billing_run[periodEnd]' => '01.06.2026',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Активный расчет такого типа за этот период уже существует.');
        self::assertSame(1, $this->countBillingRuns($workspace));
    }

    public function testAdminCanCancelBillingRunDraft(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $billingRun = $this->createBillingRun($workspace, '2026-05-01', '2026-06-01');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/billing-runs/%s', $billingRun->getUuid()));

        $this->assertResponseIsSuccessful();

        $client->submitForm('Отменить', [
            'billing_run_cancel[reason]' => 'Ошибочный период расчета',
        ]);

        $cancelledRun = $this->findBillingRunByUuid($billingRun->getUuid());

        self::assertInstanceOf(BillingRun::class, $cancelledRun);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNotNull($cancelledRun->getCancelledAt());
        self::assertSame('Ошибочный период расчета', $cancelledRun->getCancellationReason());
        self::assertSame($admin->getUuid()->toRfc4122(), $cancelledRun->getCancelledBy()?->getUuid()->toRfc4122());
        self::assertFalse($cancelledRun->isDraft());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Отменен');
        $this->assertSelectorTextContains('body', 'Ошибочный период расчета');
    }

    public function testAdminCreatesBillingRunIssuesForAccountWithoutMeterOrTariff(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(2, $this->countBillingRunIssues($billingRun));
        self::assertInstanceOf(BillingRunAccountIssue::class, $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::MissingReading));
        self::assertInstanceOf(BillingRunAccountIssue::class, $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::MissingTariff));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Создано проблем: 2');
        $this->assertSelectorTextContains('body', 'Открытые проблемы: 2');
        $this->assertSelectorTextContains('body', 'Проведение расчета будет заблокировано');
        $this->assertSelectorTextContains('body', 'Следующее действие: закрыть проблемы');
        $this->assertSelectorTextContains('body', 'Нет показаний');
        $this->assertSelectorTextContains('body', 'У участка нет активного электросчетчика.');
        $this->assertSelectorTextContains('body', 'Нет тарифа');
    }

    public function testAdminCreatesBillingRunIssuesForStaleReadingAndMissingConsumptionBandRule(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '100', '2026-05-01');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffProfileAssignment($workspace, $account, $tariffProfile, '2026-05-01');
        $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(2, $this->countBillingRunIssues($billingRun));
        self::assertInstanceOf(BillingRunAccountIssue::class, $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::StaleReading));
        self::assertInstanceOf(BillingRunAccountIssue::class, $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::MissingConsumptionBandRule));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Устаревшие показания');
        $this->assertSelectorTextContains('body', 'раньше допустимой даты 21.05.2026');
        $this->assertSelectorTextContains('body', 'Нет правила нормы');
    }

    public function testAdminCreatesBillingRunIssueForMissingTariffRate(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $meter, $tariffZone, '100', '2026-06-04');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffProfileAssignment($workspace, $account, $tariffProfile, '2026-05-01');
        $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $rule = $this->createConsumptionBandRule($workspace, $tariffProfile, 5, '2026-05-01');
        $band = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма');
        $this->createConsumptionBandRuleRange($workspace, $rule, $band, '0', null);
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);
        $issue = $billingRun instanceof BillingRun
            ? $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::MissingTariff)
            : null;

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(1, $this->countBillingRunIssues($billingRun));
        self::assertInstanceOf(BillingRunAccountIssue::class, $issue);
        self::assertStringContainsString('Нет ставки тарифа', $issue->getMessage());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Нет ставки тарифа для зоны');
    }

    public function testAdminCreatesNoBillingRunIssuesWhenElectricityConfigurationIsComplete(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $meter, $tariffZone, '100', '2026-06-04');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffProfileAssignment($workspace, $account, $tariffProfile, '2026-05-01');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $rule = $this->createConsumptionBandRule($workspace, $tariffProfile, 5, '2026-05-01');
        $band = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма');
        $this->createConsumptionBandRuleRange($workspace, $rule, $band, '0', null);
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $band, '5.500000');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(0, $this->countBillingRunIssues($billingRun));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Проблемы не найдены.');
        $this->assertSelectorTextContains('body', 'Открытых проблем нет.');
        $this->assertSelectorTextContains('body', 'Следующее действие: сгенерировать начисления');
    }

    public function testAdminCanGenerateDraftElectricityAccrualsForAccountsWithoutOpenIssues(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $problemAccount = $this->createAccount($workspace, '9-124');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $meter, $tariffZone, '170', '2026-06-04');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffProfileAssignment($workspace, $account, $tariffProfile, '2026-05-01');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $rule = $this->createConsumptionBandRule($workspace, $tariffProfile, 5, '2026-05-01');
        $socialBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма');
        $aboveBand = $this->createConsumptionBand($workspace, 'above_social_norm', 'Сверх социальной нормы');
        $this->createConsumptionBandRuleRange($workspace, $rule, $socialBand, '0', '100');
        $this->createConsumptionBandRuleRange($workspace, $rule, $aboveBand, '100', null);
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $socialBand, '5.000000');
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $aboveBand, '7.000000');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(2, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();
        $client->submitForm('Сгенерировать начисления');

        $accrual = $this->findAccrualByBillingRunAndAccount($billingRun, $account);

        self::assertInstanceOf(Accrual::class, $accrual);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('640.00', $accrual->getAmount());
        self::assertNull($accrual->getPostedAt());
        self::assertSame($billingRun->getUuid()->toRfc4122(), $accrual->getBillingRun()?->getUuid()->toRfc4122());
        self::assertSame(2, $this->countAccrualLines($accrual));

        $client->followRedirect();
        $billingRunAfterGeneration = $this->findBillingRunByUuid($billingRun->getUuid());

        self::assertInstanceOf(BillingRun::class, $billingRunAfterGeneration);
        self::assertNotNull($billingRunAfterGeneration->getAccrualsGeneratedAt());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Генерация начислений выполнена. Создано: 1, пропущено с открытыми проблемами: 1');
        $this->assertSelectorTextContains('body', 'Следующее действие: закрыть проблемы');
        $this->assertSelectorTextContains('body', 'Сгенерировано начислений: 1. Участки с открытыми проблемами пропущены.');
        $this->assertSelectorTextContains('body', '640,00 руб.');
        $this->assertSelectorExists(sprintf('a[href="/admin/accounts/%s"]', $account->getUuid()->toRfc4122()));
        $this->assertSelectorExists(sprintf('a[href="/admin/accounts/%s"]', $problemAccount->getUuid()->toRfc4122()));

        $client->submitForm('Сгенерировать начисления');
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame(1, $this->countAccruals($billingRun));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Создано: 0, пропущено с открытыми проблемами: 1, уже было: 1');

        $client->request('GET', sprintf('/admin/accruals/%s', $accrual->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Строки расчета');
        $this->assertSelectorTextContains('body', 'Социальная норма');
        $this->assertSelectorTextContains('body', '100,000 кВт*ч');
        $this->assertSelectorTextContains('body', 'Сверх социальной нормы');
        $this->assertSelectorTextContains('body', '20,000 кВт*ч');
    }

    public function testAdminCanRegenerateAfterIgnoringCalculationErrorWithoutDuplicatingIssue(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $validAccount = $this->createAccount($workspace, '9-123');
        $problemAccount = $this->createAccount($workspace, '9-124');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $validMeter = $this->createElectricityMeter($workspace, $validAccount, $tariffZone);
        $problemMeter = $this->createElectricityMeter($workspace, $problemAccount, $tariffZone);
        $this->createReading($workspace, $validMeter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $validMeter, $tariffZone, '170', '2026-06-04');
        $this->createReading($workspace, $problemMeter, $tariffZone, '100', '2026-05-01');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffProfileAssignment($workspace, $validAccount, $tariffProfile, '2026-05-01');
        $this->createTariffProfileAssignment($workspace, $problemAccount, $tariffProfile, '2026-05-01');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $rule = $this->createConsumptionBandRule($workspace, $tariffProfile, 5, '2026-05-01');
        $socialBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма');
        $aboveBand = $this->createConsumptionBand($workspace, 'above_social_norm', 'Сверх социальной нормы');
        $this->createConsumptionBandRuleRange($workspace, $rule, $socialBand, '0', '100');
        $this->createConsumptionBandRuleRange($workspace, $rule, $aboveBand, '100', null);
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $socialBand, '5.000000');
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $aboveBand, '7.000000');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(1, $this->countOpenBillingRunIssues($billingRun));
        self::assertInstanceOf(BillingRunAccountIssue::class, $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::StaleReading));

        $client->followRedirect();
        $client->submitForm('Закрыть', [
            'billing_run_account_issue_close[reason]' => BillingRunAccountIssueCloseReason::Ignored->value,
            'billing_run_account_issue_close[comment]' => 'Оператор разрешил использовать устаревшее показание.',
        ]);
        self::assertSame(0, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();
        $client->submitForm('Сгенерировать начисления');

        $calculationIssue = $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::CalculationError);
        $validAccrual = $this->findAccrualByBillingRunAndAccount($billingRun, $validAccount);

        self::assertInstanceOf(Accrual::class, $validAccrual);
        self::assertInstanceOf(BillingRunAccountIssue::class, $calculationIssue);
        self::assertTrue($calculationIssue->isOpen());
        self::assertSame(2, $this->countBillingRunIssues($billingRun));
        self::assertSame(1, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'ошибок расчета: 1');
        $client->submitForm('Закрыть', [
            'billing_run_account_issue_close[reason]' => BillingRunAccountIssueCloseReason::Ignored->value,
            'billing_run_account_issue_close[comment]' => 'Расчет по участку будет обработан вручную.',
        ]);
        self::assertSame(0, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Следующее действие: обновить начисления');
        $client->submitForm('Сгенерировать начисления');

        self::assertSame(2, $this->countBillingRunIssues($billingRun));
        self::assertSame(0, $this->countOpenBillingRunIssues($billingRun));
        self::assertSame(1, $this->countAccruals($billingRun));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'ошибок расчета: 0, проигнорированных ошибок расчета: 1');
        $this->assertSelectorTextContains('body', 'Следующее действие: провести расчет');

        $client->submitForm('Провести расчет');
        $postedBillingRun = $this->findBillingRunByUuid($billingRun->getUuid());

        self::assertInstanceOf(BillingRun::class, $postedBillingRun);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertTrue($postedBillingRun->isPosted());
    }

    public function testAdminCannotPostBillingRunWithOpenIssues(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(2, $this->countOpenBillingRunIssues($billingRun));

        $crawler = $client->followRedirect();

        $this->assertSelectorExists(sprintf(
            'form[action="/admin/billing-runs/%s/post"] button[disabled]',
            $billingRun->getUuid()->toRfc4122()
        ));

        $client->submit($crawler->filter(sprintf(
            'form[action="/admin/billing-runs/%s/post"]',
            $billingRun->getUuid()->toRfc4122()
        ))->form());

        $postedBillingRun = $this->findBillingRunByUuid($billingRun->getUuid());

        self::assertInstanceOf(BillingRun::class, $postedBillingRun);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertTrue($postedBillingRun->isDraft());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Нельзя провести расчет: открытые проблемы: 2.');
    }

    public function testAdminCannotPostBillingRunWhenIssuesChangedAfterAccrualGeneration(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $meter, $tariffZone, '170', '2026-06-04');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffProfileAssignment($workspace, $account, $tariffProfile, '2026-05-01');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $rule = $this->createConsumptionBandRule($workspace, $tariffProfile, 5, '2026-05-01');
        $band = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма');
        $this->createConsumptionBandRuleRange($workspace, $rule, $band, '0', null);
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $band, '5.000000');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);

        $client->followRedirect();
        $client->submitForm('Сгенерировать начисления');
        $client->followRedirect();

        $billingRun = $this->findBillingRunByUuid($billingRun->getUuid());
        $workspace = $this->findWorkspaceByUuid($workspace->getUuid());
        $account = $this->findAccountByUuid($account->getUuid());

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertInstanceOf(Account::class, $account);
        self::assertNotNull($billingRun->getAccrualsGeneratedAt());

        $issue = new BillingRunAccountIssue(
            $workspace,
            $billingRun,
            $account,
            BillingRunAccountIssueType::MissingTariff,
            'Оператор изменил проблему после генерации начислений.'
        );
        $this->entityManager()->persist($issue);
        $this->entityManager()->flush();
        $issue->close(BillingRunAccountIssueCloseReason::Ignored, 'Проверено вручную.');
        $this->entityManager()->flush();

        self::assertSame(0, $this->countOpenBillingRunIssues($billingRun));

        $crawler = $client->request('GET', sprintf('/admin/billing-runs/%s', $billingRun->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Следующее действие: обновить начисления');
        $this->assertSelectorExists(sprintf(
            'form[action="/admin/billing-runs/%s/post"] button[disabled]',
            $billingRun->getUuid()->toRfc4122()
        ));

        $client->submit($crawler->filter(sprintf(
            'form[action="/admin/billing-runs/%s/post"]',
            $billingRun->getUuid()->toRfc4122()
        ))->form());

        $blockedBillingRun = $this->findBillingRunByUuid($billingRun->getUuid());

        self::assertInstanceOf(BillingRun::class, $blockedBillingRun);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertTrue($blockedBillingRun->isDraft());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Нельзя провести расчет: после последней генерации начислений менялись проблемы расчета.');

        $client->submitForm('Сгенерировать начисления');
        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Создано: 0');
        $this->assertSelectorTextContains('body', 'Следующее действие: провести расчет');

        $client->submitForm('Провести расчет');
        $postedBillingRun = $this->findBillingRunByUuid($billingRun->getUuid());

        self::assertInstanceOf(BillingRun::class, $postedBillingRun);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertTrue($postedBillingRun->isPosted());
    }

    public function testAdminCanPostBillingRunAndPostedAccrualAffectsAccountBalance(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $meter, $tariffZone, '170', '2026-06-04');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffProfileAssignment($workspace, $account, $tariffProfile, '2026-05-01');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $rule = $this->createConsumptionBandRule($workspace, $tariffProfile, 5, '2026-05-01');
        $socialBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма');
        $aboveBand = $this->createConsumptionBand($workspace, 'above_social_norm', 'Сверх социальной нормы');
        $this->createConsumptionBandRuleRange($workspace, $rule, $socialBand, '0', '100');
        $this->createConsumptionBandRuleRange($workspace, $rule, $aboveBand, '100', null);
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $socialBand, '5.000000');
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $aboveBand, '7.000000');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);

        $client->followRedirect();
        $client->submitForm('Сгенерировать начисления');
        $client->followRedirect();
        $this->assertSelectorTextContains('body', 'Следующее действие: провести расчет');
        $client->submitForm('Провести расчет');

        $postedBillingRun = $this->findBillingRunByUuid($billingRun->getUuid());
        $accrual = $this->findAccrualByBillingRunAndAccount($billingRun, $account);

        self::assertInstanceOf(BillingRun::class, $postedBillingRun);
        self::assertInstanceOf(Accrual::class, $accrual);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertTrue($postedBillingRun->isPosted());
        self::assertNotNull($postedBillingRun->getPostedAt());
        self::assertTrue($accrual->isActivePosted());
        self::assertNotNull($accrual->getPostedAt());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Расчет проведен. Проведено начислений: 1.');
        $this->assertSelectorTextContains('body', 'Проведен');
        $this->assertSelectorTextContains('body', 'Проведено');

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Начислено');
        $this->assertSelectorTextContains('body', '640,00 руб.');
        $this->assertSelectorTextContains('body', 'К оплате');
    }

    public function testAdminCanPostBillingRunByUsingMatchingPostedAccrualFromImport(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '2-2');
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович', 'owner@example.test');
        $this->createAccess($workspace, $subscriber, $account, $admin);
        $billingRun = $this->createBillingRun($workspace, '2026-04-01', '2026-05-01');
        $draftAccrual = $this->createDraftAccrual($workspace, $account, $billingRun, '14009.89', '2026-04-01', '2026-05-01');
        $postedAccrual = $this->createPostedAccrual($workspace, $account, '14009.89', '2026-04-01', '2026-05-01', $admin);
        $billingRun->markAccrualsGenerated($admin);
        $this->entityManager()->flush();
        $conflictingAccrual = $this->entityManager()->getRepository(Accrual::class)->findOneActivePostedByAccountTypeAndPeriod(
            $workspace,
            $account,
            AccrualType::Electricity,
            new DateTimeImmutable('2026-04-01'),
            new DateTimeImmutable('2026-05-01'),
        );

        self::assertInstanceOf(Accrual::class, $conflictingAccrual);
        self::assertSame($postedAccrual->getUuid()->toRfc4122(), $conflictingAccrual->getUuid()->toRfc4122());
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/billing-runs/%s', $billingRun->getUuid()));

        $this->assertResponseIsSuccessful();
        $client->submitForm('Провести расчет');

        $postedBillingRun = $this->findBillingRunByUuid($billingRun->getUuid());
        $draftAccrual = $this->entityManager()->getRepository(Accrual::class)->find($draftAccrual->getUuid());
        $postedAccrual = $this->entityManager()->getRepository(Accrual::class)->find($postedAccrual->getUuid());

        self::assertInstanceOf(BillingRun::class, $postedBillingRun);
        self::assertInstanceOf(Accrual::class, $draftAccrual);
        self::assertInstanceOf(Accrual::class, $postedAccrual);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertTrue($postedBillingRun->isPosted());
        self::assertNull($draftAccrual->getPostedAt());
        self::assertNotNull($draftAccrual->getCancelledAt());
        self::assertSame($billingRun->getUuid()->toRfc4122(), $postedAccrual->getBillingRun()?->getUuid()->toRfc4122());
        self::assertTrue($postedAccrual->isActivePosted());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Расчет проведен. Проведено новых начислений: 0, использовано существующих: 1.');
        $this->assertSelectorTextContains('body', 'Следующее действие: сформировать квитанции');
        $this->assertSelectorTextContains('body', '14 009,89 руб.');
        $client->submitForm('Сформировать квитанции');

        $statement = $this->findStatementByBillingRunAndAccount($billingRun, $account);

        self::assertInstanceOf(AccountStatementSnapshot::class, $statement);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('14009.89', $statement->getActiveAccrualTotal());
        self::assertSame('-14009.89', $statement->getBalanceAmount());
        self::assertSame('14009.89', $statement->getAmountToPay());
    }

    public function testAdminCanGenerateBillingRunAccrualsByReusingExistingPostedAccrual(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '2-2');
        $billingRun = $this->createBillingRun($workspace, '2026-04-01', '2026-05-01');
        $postedAccrual = $this->createPostedAccrual($workspace, $account, '14009.89', '2026-04-01', '2026-05-01', $admin);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/billing-runs/%s', $billingRun->getUuid()));

        $this->assertResponseIsSuccessful();
        $client->submitForm('Сгенерировать начисления');

        $billingRunAfterGeneration = $this->findBillingRunByUuid($billingRun->getUuid());
        $postedAccrual = $this->entityManager()->getRepository(Accrual::class)->find($postedAccrual->getUuid());

        self::assertInstanceOf(BillingRun::class, $billingRunAfterGeneration);
        self::assertInstanceOf(Accrual::class, $postedAccrual);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNotNull($billingRunAfterGeneration->getAccrualsGeneratedAt());
        self::assertSame($billingRun->getUuid()->toRfc4122(), $postedAccrual->getBillingRun()?->getUuid()->toRfc4122());
        self::assertSame(1, $this->countAccruals($billingRun));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'использовано проведенных: 1');
        $this->assertSelectorTextContains('body', 'Следующее действие: провести расчет');
        $client->submitForm('Провести расчет');

        $postedBillingRun = $this->findBillingRunByUuid($billingRun->getUuid());

        self::assertInstanceOf(BillingRun::class, $postedBillingRun);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertTrue($postedBillingRun->isPosted());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Расчет проведен. Проведено новых начислений: 0, использовано существующих: 1.');
    }

    public function testAdminCanPostBillingRunByUsingMatchingPostedAccrualWhenDuplicateDraftWasAlreadyCancelled(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '2-2');
        $billingRun = $this->createBillingRun($workspace, '2026-04-01', '2026-05-01');
        $cancelledDraftAccrual = $this->createDraftAccrual($workspace, $account, $billingRun, '14009.89', '2026-04-01', '2026-05-01');
        $cancelledDraftAccrual->cancel('Предыдущая попытка была отменена.', $admin);
        $postedAccrual = $this->createPostedAccrual($workspace, $account, '14009.89', '2026-04-01', '2026-05-01', $admin);
        $billingRun->markAccrualsGenerated($admin);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/billing-runs/%s', $billingRun->getUuid()));

        $this->assertResponseIsSuccessful();
        $client->submitForm('Провести расчет');

        $postedBillingRun = $this->findBillingRunByUuid($billingRun->getUuid());
        $postedAccrual = $this->entityManager()->getRepository(Accrual::class)->find($postedAccrual->getUuid());

        self::assertInstanceOf(BillingRun::class, $postedBillingRun);
        self::assertInstanceOf(Accrual::class, $postedAccrual);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertTrue($postedBillingRun->isPosted());
        self::assertSame($billingRun->getUuid()->toRfc4122(), $postedAccrual->getBillingRun()?->getUuid()->toRfc4122());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Расчет проведен. Проведено новых начислений: 0, использовано существующих: 1.');
    }

    public function testAdminCanGenerateStatementsAndQueueDeliveriesForPostedBillingRun(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович', 'owner@example.test');
        $this->createAccess($workspace, $subscriber, $account, $admin);
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $meter, $tariffZone, '170', '2026-06-04');
        $tariffProfile = $this->createTariffProfile($workspace, 'snt', 'СНТ');
        $this->createTariffProfileAssignment($workspace, $account, $tariffProfile, '2026-05-01');
        $tariffPeriod = $this->createTariffPeriod($workspace, $tariffProfile, '2026-05-01');
        $rule = $this->createConsumptionBandRule($workspace, $tariffProfile, 5, '2026-05-01');
        $socialBand = $this->createConsumptionBand($workspace, 'social_norm', 'Социальная норма');
        $aboveBand = $this->createConsumptionBand($workspace, 'above_social_norm', 'Сверх социальной нормы');
        $this->createConsumptionBandRuleRange($workspace, $rule, $socialBand, '0', '100');
        $this->createConsumptionBandRuleRange($workspace, $rule, $aboveBand, '100', null);
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $socialBand, '5.000000');
        $this->createTariffRate($workspace, $tariffPeriod, $tariffZone, $aboveBand, '7.000000');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);

        $client->followRedirect();
        $client->submitForm('Сгенерировать начисления');
        $client->followRedirect();
        $client->submitForm('Провести расчет');
        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf(
            'form[action="/admin/billing-runs/%s/statements/generate"]',
            $billingRun->getUuid()->toRfc4122()
        ));
        $this->assertSelectorTextContains('body', 'Следующее действие: сформировать квитанции');
        $this->assertSelectorTextContains('body', 'Нужно сформировать квитанции: 0 из 1.');
        $this->assertSelectorTextContains('body', 'Квитанции по расчету пока не сформированы.');

        $client->submitForm('Сформировать квитанции');

        $statement = $this->findStatementByBillingRunAndAccount($billingRun, $account);

        self::assertInstanceOf(AccountStatementSnapshot::class, $statement);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($billingRun->getUuid()->toRfc4122(), $statement->getBillingRun()?->getUuid()->toRfc4122());
        self::assertSame($account->getUuid()->toRfc4122(), $statement->getAccount()?->getUuid()->toRfc4122());
        self::assertSame('640.00', $statement->getActiveAccrualTotal());
        self::assertSame('-640.00', $statement->getBalanceAmount());
        self::assertSame('640.00', $statement->getAmountToPay());

        $delivery = $this->findDeliveryByStatement($statement);

        self::assertInstanceOf(AccountStatementDelivery::class, $delivery);
        self::assertSame('owner@example.test', $delivery->getRecipientEmail());
        self::assertSame('Иванов Иван Иванович', $delivery->getRecipientName());
        self::assertSame('В очереди', $delivery->getStatusLabel());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Квитанции обработаны. Создано: 1, уже было: 0, доставок в очередь: 1');
        $this->assertSelectorTextContains('body', 'Расчет завершен: квитанции сформированы');
        $this->assertSelectorTextContains('body', 'Квитанции сформированы по всем участкам с начислениями.');
        $this->assertSelectorTextContains('body', '1 / 1');
        $this->assertSelectorTextContains('body', 'Обработать повторно');
        $this->assertSelectorTextContains('body', $statement->getNumber());
        $this->assertSelectorTextContains('body', 'owner@example.test');
        $this->assertSelectorTextContains('body', 'в очереди: 1');
        $this->assertSelectorTextContains('body', 'В очереди');
        $this->assertSelectorExists(sprintf(
            'a[href="/admin/accounts/%s/statements/%s"]',
            $account->getUuid()->toRfc4122(),
            $statement->getUuid()->toRfc4122()
        ));
        $this->assertSelectorExists(sprintf(
            'a[href="/admin/accounts/%s/statements/%s/pdf"]',
            $account->getUuid()->toRfc4122(),
            $statement->getUuid()->toRfc4122()
        ));

        $client->submitForm('Обработать повторно');

        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame(1, $this->countStatements($billingRun));
        self::assertSame(1, $this->countDeliveries($statement));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Создано: 0, уже было: 1, доставок в очередь: 0');
        $this->assertSelectorTextContains('body', 'уже были доставки: 1');
    }

    public function testAdminCanRepairMissingStatementPaymentRequisitesByReprocessingBillingRunStatements(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '2-2');
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович', 'owner@example.test');
        $this->createAccess($workspace, $subscriber, $account, $admin);
        $billingRun = $this->createBillingRun($workspace, '2026-04-01', '2026-05-01');
        $postedAccrual = $this->createPostedAccrual($workspace, $account, '14009.89', '2026-04-01', '2026-05-01', $admin);
        $postedAccrual->setBillingRun($billingRun);
        $billingRun->markAccrualsGenerated($admin);
        $billingRun->post($admin);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/billing-runs/%s', $billingRun->getUuid()));
        $client->submitForm('Сформировать квитанции');

        $statement = $this->findStatementByBillingRunAndAccount($billingRun, $account);

        self::assertInstanceOf(AccountStatementSnapshot::class, $statement);
        self::assertFalse($statement->hasPaymentRequisites());

        $workspace = $this->findWorkspaceByUuid($workspace->getUuid());
        $admin = $this->entityManager()->getRepository(User::class)->find($admin->getUuid());
        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertInstanceOf(User::class, $admin);
        $this->createElectricityPaymentRequisiteProfile($workspace, $admin);

        $client->request('GET', sprintf('/admin/billing-runs/%s', $billingRun->getUuid()));
        $client->submitForm('Обработать повторно');

        $statement = $this->findStatementByBillingRunAndAccount($billingRun, $account);

        self::assertInstanceOf(AccountStatementSnapshot::class, $statement);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertTrue($statement->hasPaymentRequisites());
        self::assertSame('ТСН "Ромашка"', $statement->getPaymentRecipientName());
        self::assertSame('40703810900000000001', $statement->getPaymentBankAccount());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'реквизиты дозаполнены: 1');

        $client->request('GET', sprintf('/admin/accounts/%s/statements/%s', $account->getUuid(), $statement->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('img[alt^="QR-код оплаты"]');
    }

    public function testAdminCanCloseBillingRunIssue(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $meter, $tariffZone, '100', '2026-06-04');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(1, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();
        $client->submitForm('Закрыть', [
            'billing_run_account_issue_close[reason]' => BillingRunAccountIssueCloseReason::Ignored->value,
            'billing_run_account_issue_close[comment]' => 'Тариф будет проверен вручную перед начислением.',
        ]);

        $issue = $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::MissingTariff);

        self::assertInstanceOf(BillingRunAccountIssue::class, $issue);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertFalse($issue->isOpen());
        self::assertSame(BillingRunAccountIssueCloseReason::Ignored, $issue->getCloseReason());
        self::assertSame('Тариф будет проверен вручную перед начислением.', $issue->getCloseComment());
        self::assertSame($admin->getUuid()->toRfc4122(), $issue->getClosedBy()?->getUuid()->toRfc4122());
        self::assertSame(0, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Проигнорировано');
        $this->assertSelectorTextContains('body', 'Открытых проблем нет.');
    }

    public function testAdminCanIgnoreAllOpenBillingRunIssues(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(2, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf(
            'form[action="/admin/billing-runs/%s/issues/ignore-all"][onsubmit*="confirm"]',
            $billingRun->getUuid()->toRfc4122(),
        ));
        $client->submitForm('Игнорировать всё');

        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame(0, $this->countOpenBillingRunIssues($billingRun));
        self::assertSame(2, $this->countBillingRunIssuesByCloseReason($billingRun, BillingRunAccountIssueCloseReason::Ignored));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Проигнорировано проблем: 2.');
        $this->assertSelectorTextContains('body', 'Открытых проблем нет.');
        $this->assertSelectorTextNotContains('body', 'Игнорировать всё');
    }

    public function testAdminCanRecheckBillingRunIssuesAndCloseResolvedOnes(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(2, $this->countOpenBillingRunIssues($billingRun));

        $workspace = $this->findWorkspaceByUuid($workspace->getUuid());
        $account = $this->findAccountByUuid($account->getUuid());

        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertInstanceOf(Account::class, $account);

        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $meter, $tariffZone, '100', '2026-06-04');

        $client->followRedirect();
        $client->submitForm('Проверить повторно');

        $missingReadingIssue = $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::MissingReading);
        $missingTariffIssue = $this->findBillingRunIssue($billingRun, BillingRunAccountIssueType::MissingTariff);

        self::assertInstanceOf(BillingRunAccountIssue::class, $missingReadingIssue);
        self::assertInstanceOf(BillingRunAccountIssue::class, $missingTariffIssue);
        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertFalse($missingReadingIssue->isOpen());
        self::assertSame(BillingRunAccountIssueCloseReason::Resolved, $missingReadingIssue->getCloseReason());
        self::assertTrue($missingTariffIssue->isOpen());
        self::assertSame(1, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Повторная проверка выполнена. Создано: 0, обновлено: 0, закрыто: 1');
        $this->assertSelectorTextContains('body', 'Исправлено');
        $this->assertSelectorTextContains('body', 'Открытые проблемы: 1');
    }

    public function testAdminRecheckDoesNotReopenIgnoredIssue(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tariffZone = $this->createTariffZone($workspace, 'single', 'Однотарифная зона');
        $meter = $this->createElectricityMeter($workspace, $account, $tariffZone);
        $this->createReading($workspace, $meter, $tariffZone, '50', '2026-05-01');
        $this->createReading($workspace, $meter, $tariffZone, '100', '2026-06-04');
        $client->loginUser($admin);

        $this->submitBillingRunForm($client);
        $billingRun = $this->findBillingRunByWorkspace($workspace);

        self::assertInstanceOf(BillingRun::class, $billingRun);
        self::assertSame(1, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();
        $client->submitForm('Закрыть', [
            'billing_run_account_issue_close[reason]' => BillingRunAccountIssueCloseReason::Ignored->value,
            'billing_run_account_issue_close[comment]' => 'Оператор разрешил продолжить вручную.',
        ]);
        $client->followRedirect();
        $client->submitForm('Проверить повторно');

        $this->assertResponseRedirects(sprintf('/admin/billing-runs/%s', $billingRun->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame(1, $this->countBillingRunIssues($billingRun));
        self::assertSame(0, $this->countOpenBillingRunIssues($billingRun));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'проигнорировано ранее: 1');
    }

    private function submitBillingRunForm(KernelBrowser $client): void
    {
        $client->request('GET', '/admin/billing-runs/new');
        $client->submitForm('Сохранить', [
            'billing_run[kind]' => BillingRunKind::Electricity->value,
            'billing_run[periodStart]' => '01.05.2026',
            'billing_run[periodEnd]' => '01.06.2026',
        ]);
    }

    private function createBillingRun(Workspace $workspace, string $periodStart, string $periodEnd): BillingRun
    {
        $billingRun = new BillingRun(
            $workspace,
            BillingRunKind::Electricity,
            new DateTimeImmutable($periodStart),
            new DateTimeImmutable($periodEnd),
        );

        $this->entityManager()->persist($billingRun);
        $this->entityManager()->flush();

        return $billingRun;
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createSubscriber(Workspace $workspace, string $lastName, string $firstName, ?string $secondName = null, ?string $email = null): Subscriber
    {
        $subscriber = (new Subscriber($workspace))
            ->setLastName($lastName)
            ->setFirstName($firstName)
            ->setSecondName($secondName)
            ->setContactEmail($email);

        $this->entityManager()->persist($subscriber);
        $this->entityManager()->flush();

        return $subscriber;
    }

    private function createAccess(Workspace $workspace, Subscriber $subscriber, Account $account, ?User $grantedBy = null): SubscriberAccountAccess
    {
        $access = new SubscriberAccountAccess(
            $workspace,
            $subscriber,
            $account,
            SubscriberAccountAccessRole::Owner,
            $grantedBy,
        );

        $this->entityManager()->persist($access);
        $this->entityManager()->flush();

        return $access;
    }

    private function createElectricityPaymentRequisiteProfile(Workspace $workspace, ?User $assignedBy = null): PaymentRequisiteProfile
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
        $assignment = new PaymentRequisiteAssignment($workspace, $profile, AccrualType::Electricity, new DateTimeImmutable('2026-01-01'), $assignedBy);

        $this->entityManager()->persist($profile);
        $this->entityManager()->persist($assignment);
        $this->entityManager()->flush();

        return $profile;
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

    private function createElectricityMeter(Workspace $workspace, Account $account, ElectricityTariffZone $tariffZone): ElectricityMeter
    {
        $meter = new ElectricityMeter($workspace, $account, new DateTimeImmutable('2026-05-01'));
        $register = new ElectricityMeterRegister($workspace, $meter, $tariffZone);

        $this->entityManager()->persist($meter);
        $this->entityManager()->persist($register);
        $this->entityManager()->flush();

        return $meter;
    }

    private function createReading(
        Workspace $workspace,
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
        string $value,
        string $takenOn,
    ): ElectricityMeterReading {
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

    private function createTariffProfile(Workspace $workspace, string $code, string $name): ElectricityTariffProfile
    {
        $tariffProfile = (new ElectricityTariffProfile($workspace))
            ->setCode($code)
            ->setName($name);

        $this->entityManager()->persist($tariffProfile);
        $this->entityManager()->flush();

        return $tariffProfile;
    }

    private function createTariffProfileAssignment(
        Workspace $workspace,
        Account $account,
        ElectricityTariffProfile $tariffProfile,
        string $validFrom,
    ): AccountElectricityTariffProfileAssignment {
        $assignment = new AccountElectricityTariffProfileAssignment($workspace, $account, $tariffProfile, new DateTimeImmutable($validFrom));

        $this->entityManager()->persist($assignment);
        $this->entityManager()->flush();

        return $assignment;
    }

    private function createTariffPeriod(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        string $validFrom,
    ): ElectricityTariffPeriod {
        $tariffPeriod = new ElectricityTariffPeriod($workspace, $tariffProfile, new DateTimeImmutable($validFrom));

        $this->entityManager()->persist($tariffPeriod);
        $this->entityManager()->flush();

        return $tariffPeriod;
    }

    private function createConsumptionBandRule(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        int $month,
        string $validFrom,
    ): ElectricityConsumptionBandRule {
        $rule = new ElectricityConsumptionBandRule($workspace, $tariffProfile, new DateTimeImmutable($validFrom), $month);

        $this->entityManager()->persist($rule);
        $this->entityManager()->persist(new ElectricityConsumptionBandRuleAllScope($workspace, $rule, ElectricityConsumptionBandRuleScopeMode::Include));
        $this->entityManager()->flush();

        return $rule;
    }

    private function createConsumptionBand(Workspace $workspace, string $code, string $name): ElectricityConsumptionBand
    {
        $band = (new ElectricityConsumptionBand($workspace))
            ->setCode($code)
            ->setName($name);

        $this->entityManager()->persist($band);
        $this->entityManager()->flush();

        return $band;
    }

    private function createConsumptionBandRuleRange(
        Workspace $workspace,
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBand $band,
        string $lowerBoundKwh,
        ?string $upperBoundKwh,
    ): ElectricityConsumptionBandRuleRange {
        $range = new ElectricityConsumptionBandRuleRange($workspace, $rule, $band, $lowerBoundKwh, $upperBoundKwh);

        $this->entityManager()->persist($range);
        $this->entityManager()->flush();

        return $range;
    }

    private function createTariffRate(
        Workspace $workspace,
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffZone $tariffZone,
        ElectricityConsumptionBand $band,
        string $rate,
    ): ElectricityTariffRate {
        $tariffRate = new ElectricityTariffRate($workspace, $tariffPeriod, $tariffZone, $band, $rate);

        $this->entityManager()->persist($tariffRate);
        $this->entityManager()->flush();

        return $tariffRate;
    }

    private function createDraftAccrual(
        Workspace $workspace,
        Account $account,
        BillingRun $billingRun,
        string $amount,
        string $periodStart,
        string $periodEnd,
    ): Accrual {
        $accrual = new Accrual(
            $workspace,
            $account,
            AccrualType::Electricity,
            new DateTimeImmutable($periodStart),
            new DateTimeImmutable($periodEnd),
            $amount,
        );
        $accrual->setBillingRun($billingRun);

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }

    private function createPostedAccrual(
        Workspace $workspace,
        Account $account,
        string $amount,
        string $periodStart,
        string $periodEnd,
        ?User $postedBy = null,
    ): Accrual {
        $accrual = new Accrual(
            $workspace,
            $account,
            AccrualType::Electricity,
            new DateTimeImmutable($periodStart),
            new DateTimeImmutable($periodEnd),
            $amount,
        );
        $accrual->post($postedBy);

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }

    private function findBillingRunByUuid(Uuid $uuid): ?BillingRun
    {
        return $this->entityManager()
            ->getRepository(BillingRun::class)
            ->find($uuid);
    }

    private function findBillingRunByWorkspace(Workspace $workspace): ?BillingRun
    {
        return $this->entityManager()
            ->getRepository(BillingRun::class)
            ->findOneBy(['workspace' => $workspace]);
    }

    private function findWorkspaceByUuid(Uuid $uuid): ?Workspace
    {
        return $this->entityManager()
            ->getRepository(Workspace::class)
            ->find($uuid);
    }

    private function findAccountByUuid(Uuid $uuid): ?Account
    {
        return $this->entityManager()
            ->getRepository(Account::class)
            ->find($uuid);
    }

    private function countBillingRuns(Workspace $workspace): int
    {
        return (int) $this->entityManager()
            ->getRepository(BillingRun::class)
            ->createQueryBuilder('billingRun')
            ->select('COUNT(billingRun.uuid)')
            ->andWhere('billingRun.workspace = :workspace')
            ->setParameter('workspace', $workspace)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countBillingRunIssues(BillingRun $billingRun): int
    {
        return (int) $this->entityManager()
            ->getRepository(BillingRunAccountIssue::class)
            ->createQueryBuilder('issue')
            ->select('COUNT(issue.uuid)')
            ->andWhere('issue.billingRun = :billingRun')
            ->setParameter('billingRun', $billingRun)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countAccruals(BillingRun $billingRun): int
    {
        return (int) $this->entityManager()
            ->getRepository(Accrual::class)
            ->createQueryBuilder('accrual')
            ->select('COUNT(accrual.uuid)')
            ->andWhere('accrual.billingRun = :billingRun')
            ->setParameter('billingRun', $billingRun)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countAccrualLines(Accrual $accrual): int
    {
        return (int) $this->entityManager()
            ->getRepository(ElectricityAccrualLine::class)
            ->createQueryBuilder('line')
            ->select('COUNT(line.amount)')
            ->andWhere('line.accrual = :accrual')
            ->setParameter('accrual', $accrual)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countOpenBillingRunIssues(BillingRun $billingRun): int
    {
        return (int) $this->entityManager()
            ->getRepository(BillingRunAccountIssue::class)
            ->createQueryBuilder('issue')
            ->select('COUNT(issue.uuid)')
            ->andWhere('issue.billingRun = :billingRun')
            ->andWhere('issue.closedAt IS NULL')
            ->setParameter('billingRun', $billingRun)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countBillingRunIssuesByCloseReason(BillingRun $billingRun, BillingRunAccountIssueCloseReason $closeReason): int
    {
        return (int) $this->entityManager()
            ->getRepository(BillingRunAccountIssue::class)
            ->createQueryBuilder('issue')
            ->select('COUNT(issue.uuid)')
            ->andWhere('issue.billingRun = :billingRun')
            ->andWhere('issue.closeReason = :closeReason')
            ->setParameter('billingRun', $billingRun)
            ->setParameter('closeReason', $closeReason)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function findBillingRunIssue(BillingRun $billingRun, BillingRunAccountIssueType $issueType): ?BillingRunAccountIssue
    {
        return $this->entityManager()
            ->getRepository(BillingRunAccountIssue::class)
            ->findOneBy([
                'billingRun' => $billingRun,
                'issueType' => $issueType,
            ]);
    }

    private function findAccrualByBillingRunAndAccount(BillingRun $billingRun, Account $account): ?Accrual
    {
        return $this->entityManager()
            ->getRepository(Accrual::class)
            ->findOneBy([
                'billingRun' => $billingRun,
                'account' => $account,
            ]);
    }

    private function findStatementByBillingRunAndAccount(BillingRun $billingRun, Account $account): ?AccountStatementSnapshot
    {
        return $this->entityManager()
            ->getRepository(AccountStatementSnapshot::class)
            ->findOneBy([
                'billingRun' => $billingRun,
                'account' => $account,
            ]);
    }

    private function findDeliveryByStatement(AccountStatementSnapshot $statement): ?AccountStatementDelivery
    {
        return $this->entityManager()
            ->getRepository(AccountStatementDelivery::class)
            ->findOneBy(['accountStatement' => $statement]);
    }

    private function countStatements(BillingRun $billingRun): int
    {
        return (int) $this->entityManager()
            ->getRepository(AccountStatementSnapshot::class)
            ->createQueryBuilder('statement')
            ->select('COUNT(statement.uuid)')
            ->andWhere('statement.billingRun = :billingRun')
            ->setParameter('billingRun', $billingRun)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countDeliveries(AccountStatementSnapshot $statement): int
    {
        return (int) $this->entityManager()
            ->getRepository(AccountStatementDelivery::class)
            ->createQueryBuilder('delivery')
            ->select('COUNT(delivery.uuid)')
            ->andWhere('delivery.accountStatement = :statement')
            ->setParameter('statement', $statement)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
