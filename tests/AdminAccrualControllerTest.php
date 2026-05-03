<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\Accrual;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminAccrualControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/accruals');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyAccrualsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/accruals');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Начисления');
        $this->assertSelectorTextContains('td', 'Начисления пока не внесены.');
    }

    public function testAdminCanSortAndPaginateAccrualsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $account = $this->createAccount($workspace, sprintf('9-%03d', $i));
            $this->createPostedAccrual($workspace, $account, AccrualType::Other, (string) $i, '2026-05-01', '2026-06-01');
        }

        $client->loginUser($admin);

        $client->request('GET', '/admin/accruals?sort=account_number&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $account55Position = strpos($content, '9-055');
        $account54Position = strpos($content, '9-054');
        self::assertNotFalse($account55Position);
        self::assertNotFalse($account54Position);
        self::assertLessThan($account54Position, $account55Position);
        $this->assertSelectorExists('a[href="/admin/accruals?sort=account_number&dir=asc&page=1"]');

        $client->request('GET', '/admin/accruals?sort=account_number&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', '9-005');
        $this->assertSelectorTextNotContains('body', '9-055');
    }

    public function testAdminCanCreateAccrualFromAccountCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Начисления пока не внесены.');
        $this->assertSelectorTextContains('body', 'Начислено');
        $this->assertSelectorTextContains('body', '0,00 руб.');

        $client->request('GET', sprintf('/admin/accounts/%s/accruals/new', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="accrual[periodStart]"][placeholder="дд.мм.гггг"]');
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="accrual[periodEnd]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Сохранить', [
            'accrual[type]' => AccrualType::MembershipFee->value,
            'accrual[amount]' => '2000,25',
            'accrual[periodStart]' => '01.05.2026',
            'accrual[periodEnd]' => '01.06.2026',
            'accrual[notes]' => 'Членский взнос за май',
        ]);

        $accrual = $this->findAccrualByAccount($account);

        self::assertInstanceOf(Accrual::class, $accrual);
        $this->assertResponseRedirects(sprintf('/admin/accruals/%s', $accrual->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $accrual->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame($account->getUuid()->toRfc4122(), $accrual->getAccount()?->getUuid()->toRfc4122());
        self::assertSame(AccrualType::MembershipFee, $accrual->getType());
        self::assertSame('2000.25', $accrual->getAmount());
        self::assertSame('2026-05-01', $accrual->getPeriodStart()->format('Y-m-d'));
        self::assertSame('2026-06-01', $accrual->getPeriodEnd()->format('Y-m-d'));
        self::assertSame('Членский взнос за май', $accrual->getNotes());
        self::assertNotNull($accrual->getPostedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $accrual->getPostedBy()?->getUuid()->toRfc4122());
        self::assertSame($admin->getUuid()->toRfc4122(), $accrual->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertTrue($accrual->isActivePosted());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Начисление');
        $this->assertSelectorTextContains('body', 'Членский взнос');
        $this->assertSelectorTextContains('body', '2 000,25 руб.');
        $this->assertSelectorTextContains('body', 'Проведено');

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Начислено');
        $this->assertSelectorTextContains('body', '2 000,25 руб.');
        $this->assertSelectorTextContains('body', 'К оплате');
        $this->assertSelectorTextContains('body', 'Членский взнос за май');

        $client->request('GET', '/admin/accruals');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', '2 000,25 руб.');
    }

    public function testAdminCanFilterAccrualsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $postedAccount = $this->createAccount($workspace, '9-001');
        $cancelledAccount = $this->createAccount($workspace, '9-002');
        $electricityAccount = $this->createAccount($workspace, '9-003');
        $supersededAccount = $this->createAccount($workspace, '9-004');
        $draftAccount = $this->createAccount($workspace, '9-005');
        $postedAccrual = $this->createPostedAccrual($workspace, $postedAccount, AccrualType::MembershipFee, '2000.25', '2026-05-01', '2026-06-01')
            ->setNotes('Членский взнос за май');
        $cancelledAccrual = $this->createPostedAccrual($workspace, $cancelledAccount, AccrualType::Other, '250.00', '2026-05-10', '2026-05-11')
            ->setNotes('Ошибочное начисление');
        $cancelledAccrual->cancel('Дубль начисления', $admin);
        $electricityAccrual = $this->createPostedAccrual($workspace, $electricityAccount, AccrualType::Electricity, '700.00', '2026-06-01', '2026-07-01')
            ->setNotes('Электроэнергия июнь');
        $supersededAccrual = $this->createPostedAccrual($workspace, $supersededAccount, AccrualType::Water, '100.00', '2026-07-01', '2026-08-01');
        $replacementAccrual = $this->createPostedAccrual($workspace, $supersededAccount, AccrualType::Water, '120.00', '2026-08-01', '2026-09-01');
        $supersededAccrual->markReplacedBy($replacementAccrual, 'Уточнена сумма', $admin);
        $draftAccrual = $this->createDraftAccrual($workspace, $draftAccount, AccrualType::Other, '300.00', '2026-05-15', '2026-06-15');
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/accruals?q=членский');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');
        $this->assertSelectorExists('input[name="q"][value="членский"]');

        $client->request('GET', '/admin/accruals?q=2000,25');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextNotContains('body', '9-002');

        $client->request('GET', sprintf('/admin/accruals?type=%s', AccrualType::Electricity->value));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-003');
        $this->assertSelectorTextContains('body', 'Электроэнергия');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/accruals?status=posted');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextContains('body', 'Проведено');
        $this->assertSelectorTextNotContains('body', '9-002');
        $this->assertSelectorTextNotContains('body', '9-005');

        $client->request('GET', '/admin/accruals?status=draft');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-005');
        $this->assertSelectorTextContains('body', 'Черновик');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/accruals?status=cancelled');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-002');
        $this->assertSelectorTextContains('body', 'Отменено');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/accruals?status=superseded');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-004');
        $this->assertSelectorTextContains('body', 'Заменено');
        $this->assertSelectorTextNotContains('body', '9-001');

        $client->request('GET', '/admin/accruals?period_start_from=01.06.2026&period_start_to=30.06.2026');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-003');
        $this->assertSelectorTextNotContains('body', '9-001');
        $this->assertSelectorExists('input[name="period_start_from"][value="01.06.2026"]');

        self::assertTrue($postedAccrual->isActivePosted());
        self::assertFalse($cancelledAccrual->isActivePosted());
        self::assertTrue($electricityAccrual->isActivePosted());
        self::assertFalse($supersededAccrual->isActivePosted());
        self::assertTrue($draftAccrual->isDraft());
    }

    public function testAdminCannotCreateAccrualWithInvalidAmount(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/accruals/new', $account->getUuid()));
        $client->submitForm('Сохранить', [
            'accrual[type]' => AccrualType::Other->value,
            'accrual[amount]' => '0',
            'accrual[periodStart]' => '01.05.2026',
            'accrual[periodEnd]' => '01.06.2026',
            'accrual[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Сумма должна быть положительным числом с точностью до копеек.');
        self::assertSame(0, $this->countAccruals($account));
    }

    public function testAdminCannotCreateAccrualWithInvalidPeriod(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/accruals/new', $account->getUuid()));
        $client->submitForm('Сохранить', [
            'accrual[type]' => AccrualType::Other->value,
            'accrual[amount]' => '100',
            'accrual[periodStart]' => '01.06.2026',
            'accrual[periodEnd]' => '01.05.2026',
            'accrual[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Конец периода должен быть позже начала периода.');
        self::assertSame(0, $this->countAccruals($account));
    }

    public function testAdminCannotCreateDuplicateActivePostedAccrualForSameTypeAndPeriod(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $this->createPostedAccrual($workspace, $account, AccrualType::Other, '100.00', '2026-05-01', '2026-06-01');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accounts/%s/accruals/new', $account->getUuid()));
        $client->submitForm('Сохранить', [
            'accrual[type]' => AccrualType::Other->value,
            'accrual[amount]' => '200',
            'accrual[periodStart]' => '01.05.2026',
            'accrual[periodEnd]' => '01.06.2026',
            'accrual[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Активное posted-начисление такого типа за этот период уже существует.');
        self::assertSame(1, $this->countAccruals($account));
    }

    public function testAdminCanCancelActivePostedAccrual(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $accrual = $this->createPostedAccrual($workspace, $account, AccrualType::Other, '1234.50', '2026-05-01', '2026-06-01');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/accruals/%s', $accrual->getUuid()));

        $this->assertResponseIsSuccessful();

        $client->submitForm('Отменить', [
            'accrual_cancel[reason]' => 'Начисление внесено не на тот участок',
        ]);

        $cancelledAccrual = $this->findAccrualByUuid($accrual->getUuid());

        self::assertInstanceOf(Accrual::class, $cancelledAccrual);
        $this->assertResponseRedirects(sprintf('/admin/accruals/%s', $accrual->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNotNull($cancelledAccrual->getCancelledAt());
        self::assertSame('Начисление внесено не на тот участок', $cancelledAccrual->getCancellationReason());
        self::assertSame($admin->getUuid()->toRfc4122(), $cancelledAccrual->getCancelledBy()?->getUuid()->toRfc4122());
        self::assertFalse($cancelledAccrual->isActivePosted());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Отменено');
        $this->assertSelectorTextContains('body', 'Начисление внесено не на тот участок');

        $client->request('GET', sprintf('/admin/accounts/%s', $account->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Начислено');
        $this->assertSelectorTextContains('body', '0,00 руб.');
        $this->assertSelectorTextContains('body', 'Отменено');
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createPostedAccrual(Workspace $workspace, Account $account, AccrualType $type, string $amount, string $periodStart, string $periodEnd): Accrual
    {
        $accrual = new Accrual(
            $workspace,
            $account,
            $type,
            new DateTimeImmutable($periodStart),
            new DateTimeImmutable($periodEnd),
            $amount,
        );
        $accrual->post();

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }

    private function createDraftAccrual(Workspace $workspace, Account $account, AccrualType $type, string $amount, string $periodStart, string $periodEnd): Accrual
    {
        $accrual = new Accrual(
            $workspace,
            $account,
            $type,
            new DateTimeImmutable($periodStart),
            new DateTimeImmutable($periodEnd),
            $amount,
        );

        $this->entityManager()->persist($accrual);
        $this->entityManager()->flush();

        return $accrual;
    }

    private function findAccrualByUuid(Uuid $uuid): ?Accrual
    {
        return $this->entityManager()
            ->getRepository(Accrual::class)
            ->find($uuid);
    }

    private function findAccrualByAccount(Account $account): ?Accrual
    {
        return $this->entityManager()
            ->getRepository(Accrual::class)
            ->findOneBy(['account' => $account]);
    }

    private function countAccruals(Account $account): int
    {
        return (int) $this->entityManager()
            ->getRepository(Accrual::class)
            ->createQueryBuilder('accrual')
            ->select('COUNT(accrual.uuid)')
            ->andWhere('accrual.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
