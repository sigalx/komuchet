<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\Accrual;
use App\Entity\Payment;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use App\Enum\PaymentSource;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminPaymentControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/payments');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyPaymentsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/payments');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Оплаты');
        $this->assertSelectorTextContains('td', 'Оплаты пока не внесены.');
    }

    public function testAdminCanSortAndPaginatePaymentsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $account = $this->createAccount($workspace, sprintf('9-%03d', $i));
            $this->createPayment($workspace, $account, (string) $i, '2026-05-09');
        }

        $client->loginUser($admin);

        $client->request('GET', '/admin/payments?sort=account_number&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $account55Position = strpos($content, '9-055');
        $account54Position = strpos($content, '9-054');
        self::assertNotFalse($account55Position);
        self::assertNotFalse($account54Position);
        self::assertLessThan($account54Position, $account55Position);
        $this->assertSelectorExists('a[href="/admin/payments?sort=account_number&dir=asc&page=1"]');

        $client->request('GET', '/admin/payments?sort=account_number&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', '9-005');
        $this->assertSelectorTextNotContains('body', '9-055');
    }

    public function testAdminCanCreatePaymentFromAccountCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Оплаты пока не внесены.');
        $this->assertSelectorTextContains('body', 'Оплачено');
        $this->assertSelectorTextContains('body', '0,00 руб.');

        $client->request('GET', sprintf('/admin/accounts/%s/payments/new', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="payment[paidOn]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Сохранить', [
            'payment[amount]' => '1234,50',
            'payment[paidOn]' => '09.05.2026',
            'payment[payerName]' => 'Иванов Иван Иванович',
            'payment[purpose]' => 'Оплата света май 2026',
            'payment[externalReference]' => 'bank-row-42',
        ]);

        $payment = $this->findPaymentByAccount($account);

        self::assertInstanceOf(Payment::class, $payment);
        $this->assertResponseRedirects(sprintf('/admin/payments/%s', $payment->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $payment->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame($account->getUuid()->toRfc4122(), $payment->getAccount()?->getUuid()->toRfc4122());
        self::assertSame('1234.50', $payment->getAmount());
        self::assertSame('2026-05-09', $payment->getPaidOn()->format('Y-m-d'));
        self::assertSame(PaymentSource::Manual, $payment->getSource());
        self::assertSame('Иванов Иван Иванович', $payment->getPayerName());
        self::assertSame('Оплата света май 2026', $payment->getPurpose());
        self::assertSame('bank-row-42', $payment->getExternalReference());
        self::assertSame($admin->getUuid()->toRfc4122(), $payment->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertTrue($payment->isActive());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Оплата');
        $this->assertSelectorTextContains('body', '1 234,50 руб.');
        $this->assertSelectorTextContains('body', 'Активно');

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Оплачено');
        $this->assertSelectorTextContains('body', '1 234,50 руб.');
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');

        $client->request('GET', '/admin/payments');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', '1 234,50 руб.');
    }

    public function testAdminCanFilterPaymentsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $activeAccount = $this->createAccount($workspace, '9-001');
        $cancelledAccount = $this->createAccount($workspace, '9-002');
        $importAccount = $this->createAccount($workspace, '9-003');
        $supersededAccount = $this->createAccount($workspace, '9-004');
        $activePayment = $this->createPayment($workspace, $activeAccount, '1234.50', '2026-05-09')
            ->setPayerName('Иванов Иван Иванович')
            ->setPurpose('Оплата света май 2026')
            ->setExternalReference('bank-row-1');
        $cancelledPayment = $this->createPayment($workspace, $cancelledAccount, '250.00', '2026-05-10')
            ->setPayerName('Петров Петр Петрович')
            ->setPurpose('Ошибочная оплата');
        $cancelledPayment->cancel('Дубль платежа', $admin);
        $importPayment = $this->createPayment($workspace, $importAccount, '700.00', '2026-05-11')
            ->setSource(PaymentSource::Import)
            ->setPayerName('Сидоров Сидор Сидорович')
            ->setExternalReference('bank-import-77');
        $supersededPayment = $this->createPayment($workspace, $supersededAccount, '100.00', '2026-05-12');
        $replacementPayment = $this->createPayment($workspace, $supersededAccount, '120.00', '2026-05-12');
        $supersededPayment->markReplacedBy($replacementPayment, 'Уточнена сумма', $admin);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/payments?q=иванов');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');
        $this->assertSelectorExists('input[name="q"][value="иванов"]');

        $client->request('GET', '/admin/payments?q=1234,50');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');

        $client->request('GET', '/admin/payments?status=cancelled');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextContains('body', 'Отменено');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/payments?status=superseded');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-004');
        $this->assertSelectorTextContains('body', 'Заменено');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/payments?source=import');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-003');
        $this->assertSelectorTextContains('body', 'Импорт');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/payments?paid_on_from=10.05.2026&paid_on_to=10.05.2026');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-003');
        $this->assertSelectorExists('input[name="paid_on_from"][value="10.05.2026"]');

        self::assertTrue($activePayment->isActive());
        self::assertFalse($cancelledPayment->isActive());
        self::assertTrue($importPayment->isActive());
        self::assertFalse($supersededPayment->isActive());
    }

    public function testAdminCannotCreatePaymentWithInvalidAmount(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/payments/new', $account->getUuid()));
        $client->submitForm('Сохранить', [
            'payment[amount]' => '0',
            'payment[paidOn]' => '09.05.2026',
            'payment[payerName]' => '',
            'payment[purpose]' => '',
            'payment[externalReference]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Сумма должна быть положительным числом с точностью до копеек.');
        self::assertSame(0, $this->countPayments($account));
    }

    public function testAdminCanCancelActivePayment(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $payment = $this->createPayment($workspace, $account, '1234.50', '2026-05-09');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/payments/%s', $payment->getUuid()));

        $this->assertResponseIsSuccessful();

        $client->submitForm('Отменить', [
            'payment_cancel[reason]' => 'Платеж внесен не на тот участок',
        ]);

        $cancelledPayment = $this->findPaymentByUuid($payment->getUuid());

        self::assertInstanceOf(Payment::class, $cancelledPayment);
        $this->assertResponseRedirects(sprintf('/admin/payments/%s', $payment->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNotNull($cancelledPayment->getCancelledAt());
        self::assertSame('Платеж внесен не на тот участок', $cancelledPayment->getCancellationReason());
        self::assertSame($admin->getUuid()->toRfc4122(), $cancelledPayment->getCancelledBy()?->getUuid()->toRfc4122());
        self::assertFalse($cancelledPayment->isActive());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Отменено');
        $this->assertSelectorTextContains('body', 'Платеж внесен не на тот участок');

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Оплачено');
        $this->assertSelectorTextContains('body', '0,00 руб.');
        $this->assertSelectorTextContains('body', 'Отменено');
    }

    public function testAccountCardShowsComputedBalanceFromActivePostedAccrualsAndActivePayments(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $this->createPostedAccrual($workspace, $account, '2000.00', '2026-05-01', '2026-06-01');
        $this->createDraftAccrual($workspace, $account, '999.00', '2026-06-01', '2026-07-01');
        $this->createCancelledAccrual($workspace, $account, '700.00', '2026-07-01', '2026-08-01');
        $this->createPayment($workspace, $account, '1234.50', '2026-05-09');
        $this->createCancelledPayment($workspace, $account, '250.00', '2026-05-10');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Начислено');
        $this->assertSelectorTextContains('body', '2 000,00 руб.');
        $this->assertSelectorTextContains('body', 'Оплачено');
        $this->assertSelectorTextContains('body', '1 234,50 руб.');
        $this->assertSelectorTextContains('body', 'Баланс');
        $this->assertSelectorTextContains('body', '765,50 руб.');
        $this->assertSelectorTextContains('body', 'К оплате');
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createPayment(Workspace $workspace, Account $account, string $amount, string $paidOn): Payment
    {
        $payment = new Payment(
            $workspace,
            $account,
            $amount,
            new DateTimeImmutable($paidOn),
            PaymentSource::Manual,
        );

        $this->entityManager()->persist($payment);
        $this->entityManager()->flush();

        return $payment;
    }

    private function createCancelledPayment(Workspace $workspace, Account $account, string $amount, string $paidOn): Payment
    {
        $payment = $this->createPayment($workspace, $account, $amount, $paidOn);
        $payment->cancel('Не участвует в балансе');
        $this->entityManager()->flush();

        return $payment;
    }

    private function createPostedAccrual(Workspace $workspace, Account $account, string $amount, string $periodStart, string $periodEnd): Accrual
    {
        $accrual = new Accrual(
            $workspace,
            $account,
            AccrualType::Electricity,
            new DateTimeImmutable($periodStart),
            new DateTimeImmutable($periodEnd),
            $amount,
        );
        $accrual->post();

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }

    private function createDraftAccrual(Workspace $workspace, Account $account, string $amount, string $periodStart, string $periodEnd): Accrual
    {
        $accrual = new Accrual(
            $workspace,
            $account,
            AccrualType::Electricity,
            new DateTimeImmutable($periodStart),
            new DateTimeImmutable($periodEnd),
            $amount,
        );

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }

    private function createCancelledAccrual(Workspace $workspace, Account $account, string $amount, string $periodStart, string $periodEnd): Accrual
    {
        $accrual = $this->createPostedAccrual($workspace, $account, $amount, $periodStart, $periodEnd);
        $accrual->cancel('Не участвует в балансе');
        $this->entityManager()->flush();

        return $accrual;
    }

    private function findPaymentByUuid(Uuid $uuid): ?Payment
    {
        return $this->entityManager()
            ->getRepository(Payment::class)
            ->find($uuid);
    }

    private function findPaymentByAccount(Account $account): ?Payment
    {
        return $this->entityManager()
            ->getRepository(Payment::class)
            ->findOneBy(['account' => $account]);
    }

    private function countPayments(Account $account): int
    {
        return (int) $this->entityManager()
            ->getRepository(Payment::class)
            ->createQueryBuilder('payment')
            ->select('COUNT(payment.uuid)')
            ->andWhere('payment.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
