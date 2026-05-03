<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementDeliveryAttempt;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Workspace;
use DateTimeImmutable;

final class AdminAccountStatementDeliveryControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/account-statement-deliveries');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyDeliveryQueue(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-statement-deliveries');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Доставка квитанций');
        $this->assertSelectorTextContains('td', 'Доставки квитанций пока не создавались.');
        $this->assertSelectorExists('a[href="/admin/account-statement-deliveries"].active');
    }

    public function testAdminCanSortAndPaginateDeliveryQueue(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $this->createDelivery(
                $workspace,
                sprintf('9-%03d', $i),
                sprintf('owner%03d@example.test', $i),
                sprintf('Получатель %03d', $i),
            );
        }

        $client->loginUser($admin);

        $client->request('GET', '/admin/account-statement-deliveries?sort=account_number&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $account55Position = strpos($content, '9-055');
        $account54Position = strpos($content, '9-054');
        self::assertNotFalse($account55Position);
        self::assertNotFalse($account54Position);
        self::assertLessThan($account54Position, $account55Position);
        $this->assertSelectorExists('a[href="/admin/account-statement-deliveries?sort=account_number&dir=asc&page=1"]');

        $client->request('GET', '/admin/account-statement-deliveries?sort=account_number&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', '9-005');
        $this->assertSelectorTextNotContains('body', '9-055');
    }

    public function testAdminCanFilterDeliveryQueue(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $queued = $this->createDelivery($workspace, '9-001', 'queued@example.test', 'Иванов Иван Иванович');
        $sent = $this->createDelivery($workspace, '9-002', 'sent@example.test', 'Петров Петр Петрович');
        $sent->getLatestAttempt()?->markSucceeded();
        $failed = $this->createDelivery($workspace, '9-003', 'failed@example.test', 'Сидоров Сидор Сидорович');
        $failed->getLatestAttempt()?->markFailed('SMTP server is unavailable.');
        $cancelled = $this->createDelivery($workspace, '9-004', 'cancelled@example.test', 'Кузнецов Кирилл Кириллович');
        $cancelled->cancel('Ошибочный получатель.', $admin);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-statement-deliveries');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'queued@example.test');
        $this->assertSelectorTextContains('body', 'sent@example.test');
        $this->assertSelectorTextContains('body', 'failed@example.test');
        $this->assertSelectorTextContains('body', 'cancelled@example.test');
        $this->assertSelectorTextContains('body', 'В очереди');
        $this->assertSelectorTextContains('body', 'Отправлено');
        $this->assertSelectorTextContains('body', 'Ошибка');
        $this->assertSelectorTextContains('body', 'Отменена');

        $client->request('GET', '/admin/account-statement-deliveries?status=queued');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="status"] option[value="queued"][selected]');
        $this->assertSelectorTextContains('body', 'queued@example.test');
        $this->assertSelectorTextNotContains('body', 'sent@example.test');
        $this->assertSelectorTextNotContains('body', 'failed@example.test');

        $client->request('GET', '/admin/account-statement-deliveries?status=sent');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'sent@example.test');
        $this->assertSelectorTextNotContains('body', 'queued@example.test');
        $this->assertSelectorTextNotContains('body', 'failed@example.test');

        $client->request('GET', '/admin/account-statement-deliveries?status=failed');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'failed@example.test');
        $this->assertSelectorTextContains('body', 'SMTP server is unavailable.');
        $this->assertSelectorTextNotContains('body', 'sent@example.test');

        $client->request('GET', '/admin/account-statement-deliveries?status=cancelled');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'cancelled@example.test');
        $this->assertSelectorTextContains('body', 'Ошибочный получатель.');
        $this->assertSelectorTextNotContains('body', 'queued@example.test');

        $client->request('GET', '/admin/account-statement-deliveries?q=9-002');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="q"][value="9-002"]');
        $this->assertSelectorTextContains('body', 'sent@example.test');
        $this->assertSelectorTextNotContains('body', 'queued@example.test');

        $statement = $sent->getAccountStatement();
        $account = $statement?->getAccount();

        self::assertInstanceOf(AccountStatementSnapshot::class, $statement);
        self::assertInstanceOf(Account::class, $account);
        $this->assertSelectorExists(sprintf(
            'a[href="/admin/accounts/%s/statements/%s"]',
            $account->getUuid()->toRfc4122(),
            $statement->getUuid()->toRfc4122(),
        ));
        $this->assertSelectorExists(sprintf(
            'a[href="/admin/accounts/%s/statements/%s/pdf"]',
            $account->getUuid()->toRfc4122(),
            $statement->getUuid()->toRfc4122(),
        ));
    }

    private function createDelivery(Workspace $workspace, string $accountNumber, string $email, string $recipientName): AccountStatementDelivery
    {
        $account = (new Account($workspace))
            ->setNumber($accountNumber);
        $statement = new AccountStatementSnapshot(
            workspace: $workspace,
            account: $account,
            statementDate: new DateTimeImmutable('2026-05-13'),
            activeAccrualTotal: '100.00',
            activePaymentTotal: '0.00',
            balanceAmount: '-100.00',
            amountToPay: '100.00',
            overpaymentAmount: '0.00',
        );
        $delivery = new AccountStatementDelivery(
            workspace: $workspace,
            accountStatement: $statement,
            recipientEmail: $email,
            recipientName: $recipientName,
        );
        $attempt = new AccountStatementDeliveryAttempt($workspace, $delivery);
        $delivery->addAttempt($attempt);

        $this->entityManager()->persist($account);
        $this->entityManager()->persist($statement);
        $this->entityManager()->persist($delivery);
        $this->entityManager()->persist($attempt);
        $this->entityManager()->flush();

        return $delivery;
    }
}
