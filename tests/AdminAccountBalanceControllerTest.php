<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\Accrual;
use App\Entity\Payment;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use App\Enum\PaymentSource;
use DateTimeImmutable;

final class AdminAccountBalanceControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/account-balances');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyAccountBalancesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-balances');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Баланс участков');
        $this->assertSelectorTextContains('td', 'Участки не найдены.');
        $this->assertSelectorExists('a[href="/admin/account-balances"].active');
    }

    public function testAdminCanPaginateAccountBalancesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $this->createAccount($workspace, sprintf('9-%03d', $i));
        }

        $client->loginUser($admin);

        $client->request('GET', '/admin/account-balances?sort=number');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-055');

        $client->request('GET', '/admin/account-balances?sort=number&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', '9-055');
        $this->assertSelectorTextNotContains('body', '9-001');
    }

    public function testAdminCanSeeComputedAccountBalances(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $debtLowAccount = $this->createAccount($workspace, '9-001');
        $overpaymentAccount = $this->createAccount($workspace, '9-002');
        $settledAccount = $this->createAccount($workspace, '9-003');
        $debtHighAccount = $this->createAccount($workspace, '9-010');
        $deletedAccount = $this->createAccount($workspace, '9-999');
        $deletedAccount->delete();

        $this->createPostedAccrual($workspace, $debtLowAccount, '2000.00');
        $this->createDraftAccrual($workspace, $debtLowAccount, '999.00');
        $this->createCancelledAccrual($workspace, $debtLowAccount, '700.00');
        $this->createPayment($workspace, $debtLowAccount, '500.00');

        $this->createPostedAccrual($workspace, $overpaymentAccount, '300.00');
        $this->createPayment($workspace, $overpaymentAccount, '800.00');
        $this->createCancelledPayment($workspace, $overpaymentAccount, '1000.00');

        $this->createPostedAccrual($workspace, $settledAccount, '100.00');
        $this->createPayment($workspace, $settledAccount, '100.00');

        $this->createPostedAccrual($workspace, $debtHighAccount, '3500.00');
        $this->createPayment($workspace, $debtHighAccount, '100.00');

        $this->createPostedAccrual($workspace, $deletedAccount, '5000.00');
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-balances');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextContains('body', '9-003');
        $this->assertSelectorTextContains('body', '9-010');
        $this->assertSelectorTextNotContains('body', '9-999');
        $this->assertSelectorTextContains('body', '2 000,00 руб.');
        $this->assertSelectorTextContains('body', '1 500,00 руб.');
        $this->assertSelectorTextContains('body', '500,00 руб.');
        $this->assertSelectorTextContains('body', 'Переплата');
        $this->assertSelectorTextContains('body', 'Закрыто');
    }

    public function testAdminCanFilterAndSortAccountBalances(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $debtLowAccount = $this->createAccount($workspace, '9-001');
        $overpaymentAccount = $this->createAccount($workspace, '9-002');
        $settledAccount = $this->createAccount($workspace, '9-003');
        $debtHighAccount = $this->createAccount($workspace, '9-010');

        $this->createPostedAccrual($workspace, $debtLowAccount, '2000.00');
        $this->createPayment($workspace, $debtLowAccount, '500.00');
        $this->createPostedAccrual($workspace, $overpaymentAccount, '300.00');
        $this->createPayment($workspace, $overpaymentAccount, '800.00');
        $this->createPostedAccrual($workspace, $settledAccount, '100.00');
        $this->createPayment($workspace, $settledAccount, '100.00');
        $this->createPostedAccrual($workspace, $debtHighAccount, '3500.00');
        $this->createPayment($workspace, $debtHighAccount, '100.00');
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/account-balances?state=debt&sort=debt_desc');
        $content = (string) $client->getResponse()->getContent();
        $rowTexts = $crawler->filter('tbody tr')->each(static fn ($row): string => $row->text());

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('9-010', $content);
        self::assertStringContainsString('9-001', $content);
        self::assertStringContainsString('9-010', $rowTexts[0] ?? '');
        self::assertStringContainsString('9-001', $rowTexts[1] ?? '');
        self::assertStringNotContainsString('9-002', $content);
        self::assertStringNotContainsString('9-003', $content);

        $client->request('GET', '/admin/account-balances?state=overpayment&sort=overpayment_desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-003');
        $this->assertSelectorTextNotContains('body', '9-010');

        $client->request('GET', '/admin/account-balances?state=settled');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-003');
        $this->assertSelectorTextNotContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-010');
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createPayment(Workspace $workspace, Account $account, string $amount): Payment
    {
        $payment = new Payment(
            $workspace,
            $account,
            $amount,
            new DateTimeImmutable('2026-05-09'),
            PaymentSource::Manual,
        );

        $this->entityManager()->persist($payment);
        $this->entityManager()->flush();

        return $payment;
    }

    private function createCancelledPayment(Workspace $workspace, Account $account, string $amount): Payment
    {
        $payment = $this->createPayment($workspace, $account, $amount);
        $payment->cancel('Не участвует в балансе');
        $this->entityManager()->flush();

        return $payment;
    }

    private function createPostedAccrual(Workspace $workspace, Account $account, string $amount): Accrual
    {
        $accrual = new Accrual(
            $workspace,
            $account,
            AccrualType::Electricity,
            new DateTimeImmutable('2026-05-01'),
            new DateTimeImmutable('2026-06-01'),
            $amount,
        );
        $accrual->post();

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }

    private function createDraftAccrual(Workspace $workspace, Account $account, string $amount): Accrual
    {
        $accrual = new Accrual(
            $workspace,
            $account,
            AccrualType::Electricity,
            new DateTimeImmutable('2026-06-01'),
            new DateTimeImmutable('2026-07-01'),
            $amount,
        );

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }

    private function createCancelledAccrual(Workspace $workspace, Account $account, string $amount): Accrual
    {
        $accrual = new Accrual(
            $workspace,
            $account,
            AccrualType::Electricity,
            new DateTimeImmutable('2026-07-01'),
            new DateTimeImmutable('2026-08-01'),
            $amount,
        );
        $accrual->post();
        $accrual->cancel('Не участвует в балансе');

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }
}
