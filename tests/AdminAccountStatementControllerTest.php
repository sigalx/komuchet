<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementDeliveryAttempt;
use App\Entity\AccountStatementSnapshot;
use App\Entity\BillingRun;
use App\Entity\Workspace;
use App\Enum\BillingRunKind;
use DateTimeImmutable;

final class AdminAccountStatementControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/account-statements');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyStatementList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-statements');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Квитанции');
        $this->assertSelectorTextContains('td', 'Квитанции пока не сформированы.');
        $this->assertSelectorExists('a[href="/admin/account-statements"].active');
    }

    public function testAdminCanSortAndPaginateStatements(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $this->createStatement($workspace, sprintf('9-%03d', $i), (string) (100 + $i), null, '2026-05-10 09:00:00+00');
        }

        $client->loginUser($admin);

        $client->request('GET', '/admin/account-statements?sort=account_number&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $account55Position = strpos($content, '9-055');
        $account54Position = strpos($content, '9-054');
        self::assertNotFalse($account55Position);
        self::assertNotFalse($account54Position);
        self::assertLessThan($account54Position, $account55Position);
        $this->assertSelectorExists('a[href="/admin/account-statements?sort=account_number&dir=asc&page=1"]');

        $client->request('GET', '/admin/account-statements?sort=account_number&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', '9-005');
        $this->assertSelectorTextNotContains('body', '9-055');
    }

    public function testAdminCanFilterStatements(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $mayBillingRun = $this->createBillingRun($workspace, '2026-05-01', '2026-06-01');
        $aprilBillingRun = $this->createBillingRun($workspace, '2026-04-01', '2026-05-01');
        $withDelivery = $this->createStatement($workspace, '9-001', '500.00', $mayBillingRun, '2026-05-10 09:00:00+00');
        $withoutDelivery = $this->createStatement($workspace, '9-002', '150.00', $aprilBillingRun, '2026-04-10 09:00:00+00');
        $manual = $this->createStatement($workspace, '9-003', '900.00', null, '2026-05-12 09:00:00+00');
        $this->createDelivery($workspace, $withDelivery, 'owner@example.test');
        $manual->cancel('Ошибочная квитанция.', $admin);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-statements');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextContains('body', '9-003');
        $this->assertSelectorTextContains('body', 'owner@example.test');
        $this->assertSelectorTextContains('body', 'Без расчета');
        $this->assertSelectorTextContains('body', 'Ошибочная квитанция.');

        $client->request('GET', '/admin/account-statements?status=active');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="status"] option[value="active"][selected]');
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-003');

        $client->request('GET', '/admin/account-statements?status=cancelled');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="status"] option[value="cancelled"][selected]');
        $this->assertSelectorTextContains('body', '9-003');
        $this->assertSelectorTextContains('body', 'Отменена');
        $this->assertSelectorTextContains('body', 'Ошибочная квитанция.');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/account-statements?q=9-002');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="q"][value="9-002"]');
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', sprintf('/admin/account-statements?billing_run=%s', $mayBillingRun->getUuid()->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('select[name="billing_run"] option[value="%s"][selected]', $mayBillingRun->getUuid()->toRfc4122()));
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-003');

        $client->request('GET', '/admin/account-statements?billing_run=none');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="billing_run"] option[value="none"][selected]');
        $this->assertSelectorTextContains('body', '9-003');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/account-statements?delivery=with');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="delivery"] option[value="with"][selected]');
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-003');

        $client->request('GET', '/admin/account-statements?delivery=without');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextContains('body', '9-003');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/account-statements?amount_to_pay_from=800');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="amount_to_pay_from"][value="800"]');
        $this->assertSelectorTextContains('body', '9-003');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/account-statements?generated_at_from=10.05.2026&generated_at_to=10.05.2026');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="generated_at_from"][value="10.05.2026"]');
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-003');

        $this->assertSelectorExists(sprintf(
            'a[href="/admin/accounts/%s/statements/%s"]',
            $withDelivery->getAccount()?->getUuid()->toRfc4122(),
            $withDelivery->getUuid()->toRfc4122(),
        ));
        $this->assertSelectorExists(sprintf(
            'a[href="/admin/accounts/%s/statements/%s/pdf"]',
            $withDelivery->getAccount()?->getUuid()->toRfc4122(),
            $withDelivery->getUuid()->toRfc4122(),
        ));

        self::assertInstanceOf(AccountStatementSnapshot::class, $withoutDelivery);
        self::assertInstanceOf(AccountStatementSnapshot::class, $manual);
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

    private function createStatement(
        Workspace $workspace,
        string $accountNumber,
        string $amountToPay,
        ?BillingRun $billingRun,
        string $generatedAt,
    ): AccountStatementSnapshot {
        $account = (new Account($workspace))
            ->setNumber($accountNumber);
        $statement = new AccountStatementSnapshot(
            workspace: $workspace,
            account: $account,
            statementDate: new DateTimeImmutable('2026-05-13'),
            activeAccrualTotal: $amountToPay,
            activePaymentTotal: '0.00',
            balanceAmount: $amountToPay,
            amountToPay: $amountToPay,
            overpaymentAmount: '0.00',
            billingRun: $billingRun,
        );

        $this->entityManager()->persist($account);
        $this->entityManager()->persist($statement);
        $this->entityManager()->flush();
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE account_statements SET generated_at = ? WHERE uuid = ?',
            [$generatedAt, $statement->getUuid()->toRfc4122()],
        );

        return $statement;
    }

    private function createDelivery(
        Workspace $workspace,
        AccountStatementSnapshot $statement,
        string $email,
    ): AccountStatementDelivery {
        $delivery = new AccountStatementDelivery(
            workspace: $workspace,
            accountStatement: $statement,
            recipientEmail: $email,
            recipientName: 'Иванов Иван Иванович',
        );
        $attempt = new AccountStatementDeliveryAttempt($workspace, $delivery);
        $delivery->addAttempt($attempt);

        $this->entityManager()->persist($delivery);
        $this->entityManager()->persist($attempt);
        $this->entityManager()->flush();

        return $delivery;
    }
}
