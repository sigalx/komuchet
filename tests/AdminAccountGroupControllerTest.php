<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AccountGroup;
use App\Entity\AccountGroupMember;
use App\Entity\Workspace;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminAccountGroupControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/account-groups');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptyAccountGroupsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-groups');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Группы участков');
        $this->assertSelectorTextContains('td', 'Группы участков пока не созданы.');
    }

    public function testAdminCanSortAndPaginateAccountGroupsList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $accountGroup = (new AccountGroup($workspace))
                ->setCode(sprintf('group_%02d', $i))
                ->setName(sprintf('Группа %02d', $i));

            $this->entityManager()->persist($accountGroup);
        }

        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-groups?sort=code&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $group55Position = strpos($content, 'group_55');
        $group54Position = strpos($content, 'group_54');
        self::assertNotFalse($group55Position);
        self::assertNotFalse($group54Position);
        self::assertLessThan($group54Position, $group55Position);
        $this->assertSelectorExists('a[href="/admin/account-groups?sort=code&dir=asc&page=1"]');

        $client->request('GET', '/admin/account-groups?sort=code&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'group_05');
        $this->assertSelectorTextNotContains('body', 'group_55');
    }

    public function testAdminCanCreateAccountGroup(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-groups/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'account_group[code]' => 'summer',
            'account_group[name]' => 'Летние участки',
            'account_group[description]' => 'Используются только летом',
        ]);

        $accountGroup = $this->findAccountGroupByCode('summer');

        self::assertInstanceOf(AccountGroup::class, $accountGroup);
        $this->assertResponseRedirects(sprintf('/admin/account-groups/%s', $accountGroup->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $accountGroup->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame('Летние участки', $accountGroup->getName());
        self::assertSame('Используются только летом', $accountGroup->getDescription());
        self::assertSame($admin->getUuid()->toRfc4122(), $accountGroup->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertNull($accountGroup->getDeletedAt());
    }

    public function testAdminCannotCreateDuplicateActiveAccountGroupCode(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createAccountGroup($workspace, 'summer', 'Летние участки');
        $client->loginUser($admin);

        $client->request('GET', '/admin/account-groups/new');
        $client->submitForm('Сохранить', [
            'account_group[code]' => 'summer',
            'account_group[name]' => 'Другая группа',
            'account_group[description]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Активная группа с таким кодом уже существует.');
        self::assertSame(1, $this->countAccountGroupsByCode('summer'));
    }

    public function testAdminCanEditAccountGroup(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $accountGroup = $this->createAccountGroup($workspace, 'summer', 'Летние участки', 'До исправления');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/account-groups/%s/edit', $accountGroup->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'account_group[code]' => 'year_round',
            'account_group[name]' => 'Круглогодичные участки',
            'account_group[description]' => 'После исправления',
        ]);

        $updatedGroup = $this->findAccountGroupByUuid($accountGroup->getUuid());

        self::assertInstanceOf(AccountGroup::class, $updatedGroup);
        $this->assertResponseRedirects(sprintf('/admin/account-groups/%s', $updatedGroup->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('year_round', $updatedGroup->getCode());
        self::assertSame('Круглогодичные участки', $updatedGroup->getName());
        self::assertSame('После исправления', $updatedGroup->getDescription());
        self::assertSame($admin->getUuid()->toRfc4122(), $updatedGroup->getUpdatedBy()?->getUuid()->toRfc4122());
    }

    public function testAdminCanSoftDeleteAccountGroup(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $accountGroup = $this->createAccountGroup($workspace, 'summer', 'Летние участки');
        $accountGroupUuid = $accountGroup->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/account-groups/%s/edit', $accountGroupUuid));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Удалить');
        $this->assertResponseRedirects('/admin/account-groups', Response::HTTP_SEE_OTHER);

        $deletedGroup = $this->findAccountGroupByUuid($accountGroupUuid);

        self::assertInstanceOf(AccountGroup::class, $deletedGroup);
        self::assertNotNull($deletedGroup->getDeletedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $deletedGroup->getDeletedBy()?->getUuid()->toRfc4122());

        $client->request('GET', '/admin/account-groups');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Группы участков пока не созданы.');
    }

    public function testAdminCanAddAccountToGroup(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $accountGroup = $this->createAccountGroup($workspace, 'summer', 'Летние участки');
        $account = $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/account-groups/%s', $accountGroup->getUuid()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select.js-searchable-select[name="account_group_member_add[account]"]');
        $this->assertSelectorExists('input[type="text"].js-date-picker[name="account_group_member_add[validFrom]"][placeholder="дд.мм.гггг"]');

        $client->submitForm('Добавить участок', [
            'account_group_member_add[account]' => $account->getUuid()->toRfc4122(),
            'account_group_member_add[validFrom]' => '09.05.2026',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/account-groups/%s', $accountGroup->getUuid()), Response::HTTP_SEE_OTHER);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', '09.05.2026');
        $this->assertSelectorExists(sprintf('a[href="/admin/accounts/%s"]', $account->getUuid()->toRfc4122()));

        $member = $this->findActiveMember($accountGroup, $account);

        self::assertInstanceOf(AccountGroupMember::class, $member);
        self::assertSame($workspace->getUuid()->toRfc4122(), $member->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame('2026-05-09', $member->getValidFrom()->format('Y-m-d'));
        self::assertSame($admin->getUuid()->toRfc4122(), $member->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertNull($member->getValidTo());
    }

    public function testAdminCannotAddDuplicateActiveGroupMember(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $accountGroup = $this->createAccountGroup($workspace, 'summer', 'Летние участки');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccount($workspace, '9-124');
        $this->createMember($workspace, $accountGroup, $account, '2026-05-09');
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/account-groups/%s', $accountGroup->getUuid()));
        $token = $crawler->filter('#account_group_member_add__token')->attr('value');

        $client->request('POST', sprintf('/admin/account-groups/%s/members/add', $accountGroup->getUuid()), [
            'account_group_member_add' => [
                'account' => $account->getUuid()->toRfc4122(),
                'validFrom' => '10.05.2026',
                '_token' => $token,
            ],
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(1, $this->countActiveMembers($accountGroup, $account));
    }

    public function testAdminCanCloseGroupMember(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $accountGroup = $this->createAccountGroup($workspace, 'summer', 'Летние участки');
        $account = $this->createAccount($workspace, '9-123');
        $this->createMember($workspace, $accountGroup, $account, '2026-05-09');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/account-groups/%s', $accountGroup->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Исключить');
        $this->assertResponseRedirects(sprintf('/admin/account-groups/%s', $accountGroup->getUuid()), Response::HTTP_SEE_OTHER);

        $member = $this->findAnyMember($accountGroup, $account);

        self::assertInstanceOf(AccountGroupMember::class, $member);
        self::assertNotNull($member->getValidTo());
        self::assertSame(0, $this->countActiveMembers($accountGroup, $account));

        $client->request('GET', sprintf('/admin/account-groups/%s', $accountGroup->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'В группе пока нет участков.');
    }

    public function testAdminCanCloseCurrentGroupMemberWithoutChangingHistoricalMembership(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $accountGroup = $this->createAccountGroup($workspace, 'summer', 'Летние участки');
        $account = $this->createAccount($workspace, '9-123');
        $this->createMember($workspace, $accountGroup, $account, '2099-05-01', '2099-05-31');
        $this->createMember($workspace, $accountGroup, $account, '2099-06-01');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/account-groups/%s', $accountGroup->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Исключить');
        $this->assertResponseRedirects(sprintf('/admin/account-groups/%s', $accountGroup->getUuid()), Response::HTTP_SEE_OTHER);

        $historicalMember = $this->findMemberByValidFrom($accountGroup, $account, '2099-05-01');
        $currentMember = $this->findMemberByValidFrom($accountGroup, $account, '2099-06-01');

        self::assertInstanceOf(AccountGroupMember::class, $historicalMember);
        self::assertInstanceOf(AccountGroupMember::class, $currentMember);
        self::assertSame('2099-05-31', $historicalMember->getValidTo()?->format('Y-m-d'));
        self::assertSame('2099-06-02', $currentMember->getValidTo()?->format('Y-m-d'));
    }

    private function createAccountGroup(Workspace $workspace, string $code, string $name, ?string $description = null): AccountGroup
    {
        $accountGroup = (new AccountGroup($workspace))
            ->setCode($code)
            ->setName($name)
            ->setDescription($description);

        $this->entityManager()->persist($accountGroup);
        $this->entityManager()->flush();

        return $accountGroup;
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createMember(Workspace $workspace, AccountGroup $accountGroup, Account $account, string $validFrom, ?string $validTo = null): AccountGroupMember
    {
        $member = new AccountGroupMember($workspace, $accountGroup, $account, new DateTimeImmutable($validFrom));

        if ($validTo !== null) {
            $member->setValidTo(new DateTimeImmutable($validTo));
        }

        $this->entityManager()->persist($member);
        $this->entityManager()->flush();

        return $member;
    }

    private function findAccountGroupByUuid(Uuid $uuid): ?AccountGroup
    {
        return $this->entityManager()
            ->getRepository(AccountGroup::class)
            ->find($uuid);
    }

    private function findAccountGroupByCode(string $code): ?AccountGroup
    {
        return $this->entityManager()
            ->getRepository(AccountGroup::class)
            ->findOneBy(['code' => $code]);
    }

    private function countAccountGroupsByCode(string $code): int
    {
        return (int) $this->entityManager()
            ->getRepository(AccountGroup::class)
            ->createQueryBuilder('accountGroup')
            ->select('COUNT(accountGroup.uuid)')
            ->andWhere('accountGroup.code = :code')
            ->setParameter('code', $code)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function findActiveMember(AccountGroup $accountGroup, Account $account): ?AccountGroupMember
    {
        return $this->entityManager()
            ->getRepository(AccountGroupMember::class)
            ->findOneBy([
                'accountGroup' => $accountGroup,
                'account' => $account,
                'validTo' => null,
            ]);
    }

    private function findAnyMember(AccountGroup $accountGroup, Account $account): ?AccountGroupMember
    {
        return $this->entityManager()
            ->getRepository(AccountGroupMember::class)
            ->findOneBy([
                'accountGroup' => $accountGroup,
                'account' => $account,
            ]);
    }

    private function findMemberByValidFrom(AccountGroup $accountGroup, Account $account, string $validFrom): ?AccountGroupMember
    {
        return $this->entityManager()
            ->getRepository(AccountGroupMember::class)
            ->findOneBy([
                'accountGroup' => $accountGroup,
                'account' => $account,
                'validFrom' => $validFrom,
            ]);
    }

    private function countActiveMembers(AccountGroup $accountGroup, Account $account): int
    {
        return (int) $this->entityManager()
            ->getRepository(AccountGroupMember::class)
            ->createQueryBuilder('groupMember')
            ->select('COUNT(groupMember.validFrom)')
            ->andWhere('groupMember.accountGroup = :accountGroup')
            ->andWhere('groupMember.account = :account')
            ->andWhere('groupMember.validTo IS NULL')
            ->setParameter('accountGroup', $accountGroup)
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
