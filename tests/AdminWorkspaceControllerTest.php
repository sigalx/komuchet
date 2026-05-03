<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\WorkspaceUserRoleCode;
use Symfony\Component\HttpFoundation\Response;

final class AdminWorkspaceControllerTest extends FunctionalWebTestCase
{
    public function testGlobalAdminCanSortAndPaginateWorkspacesList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();

        for ($i = 1; $i <= 55; ++$i) {
            $workspace = (new Workspace())
                ->setCode(sprintf('workspace_%02d', $i))
                ->setName(sprintf('Хозяйство %02d', $i));

            $this->entityManager()->persist($workspace);
        }

        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/workspaces?sort=code&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $workspace55Position = strpos($content, 'workspace_55');
        $workspace54Position = strpos($content, 'workspace_54');
        self::assertNotFalse($workspace55Position);
        self::assertNotFalse($workspace54Position);
        self::assertLessThan($workspace54Position, $workspace55Position);
        $this->assertSelectorExists('a[href="/admin/workspaces?sort=code&dir=asc&page=1"]');

        $client->request('GET', '/admin/workspaces?sort=code&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'workspace_05');
        $this->assertSelectorTextNotContains('body', 'workspace_55');
    }

    public function testGlobalAdminCanCreateWorkspace(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace('main', 'Основное СНТ');
        $client->loginUser($admin);

        $client->request('GET', '/admin/workspaces');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Хозяйства');
        $this->assertSelectorExists('a[href="/admin/workspaces/new"]');

        $client->clickLink('Создать хозяйство');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Новое хозяйство');

        $client->submitForm('Создать', [
            'workspace[code]' => 'sandbox',
            'workspace[name]' => 'Песочница',
            'workspace[description]' => 'Тестовый контур',
            'workspace[timezone]' => 'Europe/Samara',
        ]);

        $workspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->findOneBy(['code' => 'sandbox']);

        self::assertInstanceOf(Workspace::class, $workspace);
        $this->assertResponseRedirects('/admin/workspaces/'.$workspace->getUuid()->toRfc4122(), Response::HTTP_SEE_OTHER);

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Хозяйство sandbox');
        $this->assertSelectorTextContains('body', 'Песочница');
        $this->assertSelectorTextContains('body', '(UTC+04:00) Самара, Ульяновск');
        self::assertSame(1, $this->countAuditLogs('workspace.created'));
    }

    public function testGlobalAdminCanEditWorkspace(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace('main', 'Основное СНТ');
        $client->loginUser($admin);

        $client->request('GET', '/admin/workspaces/'.$workspace->getUuid()->toRfc4122().'/edit');

        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'workspace[code]' => 'main-updated',
            'workspace[name]' => 'Основное СНТ обновлено',
            'workspace[description]' => 'Рабочий контур',
            'workspace[timezone]' => 'Asia/Yekaterinburg',
        ]);

        $this->assertResponseRedirects('/admin/workspaces/'.$workspace->getUuid()->toRfc4122(), Response::HTTP_SEE_OTHER);
        $this->entityManager()->clear();

        $updatedWorkspace = $this->entityManager()
            ->getRepository(Workspace::class)
            ->find($workspace->getUuid());

        self::assertInstanceOf(Workspace::class, $updatedWorkspace);
        self::assertSame('main-updated', $updatedWorkspace->getCode());
        self::assertSame('Основное СНТ обновлено', $updatedWorkspace->getName());
        self::assertSame('Рабочий контур', $updatedWorkspace->getDescription());
        self::assertSame('Asia/Yekaterinburg', $updatedWorkspace->getTimezone());
        self::assertSame(1, $this->countAuditLogs('workspace.updated'));
    }

    public function testDuplicateWorkspaceCodeIsRejected(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace('main', 'Основное СНТ');
        $sandbox = $this->createWorkspace('sandbox', 'Песочница');
        $client->loginUser($admin);

        $client->request('GET', '/admin/workspaces/'.$sandbox->getUuid()->toRfc4122().'/edit');
        $client->submitForm('Сохранить', [
            'workspace[code]' => 'main',
            'workspace[name]' => 'Песочница',
            'workspace[description]' => '',
            'workspace[timezone]' => 'Europe/Moscow',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Хозяйство с таким кодом уже существует.');
        self::assertSame(0, $this->countAuditLogs('workspace.updated'));
    }

    public function testWorkspaceAdminCannotManageWorkspaces(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace('main', 'Основное СНТ');
        $workspaceAdmin = $this->createUser('workspace-admin@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $workspaceAdmin, WorkspaceUserRoleCode::Admin);
        $client->loginUser($workspaceAdmin);

        $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('a[href="/admin/workspaces"]');

        $client->request('GET', '/admin/workspaces');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testGlobalAdminCanSwitchCurrentWorkspace(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $firstWorkspace = $this->createWorkspace('aaa', 'Первое СНТ');
        $secondWorkspace = $this->createWorkspace('bbb', 'Второе СНТ');
        $this->createAccount($firstWorkspace, '1-001');
        $this->createAccount($secondWorkspace, '2-001');
        $client->loginUser($admin);

        $crawler = $client->request('GET', '/admin/accounts');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '1-001');
        $this->assertSelectorTextNotContains('body', '2-001');
        $this->assertSelectorExists(sprintf('select[name="workspace_uuid"] option[value="%s"][selected]', $firstWorkspace->getUuid()->toRfc4122()));

        $client->submit($crawler->selectButton('Сменить')->form([
            'workspace_uuid' => $secondWorkspace->getUuid()->toRfc4122(),
        ]));

        $this->assertResponseRedirects('/admin', Response::HTTP_SEE_OTHER);
        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('select[name="workspace_uuid"] option[value="%s"][selected]', $secondWorkspace->getUuid()->toRfc4122()));

        $client->request('GET', '/admin/accounts');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '2-001');
        $this->assertSelectorTextNotContains('body', '1-001');
    }

    public function testAdminTopbarHidesWorkspaceSwitcherWhenOnlyOneWorkspaceIsAvailable(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace('main', 'Единственное хозяйство');
        $client->loginUser($admin);

        $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#admin-current-workspace');
        $this->assertSelectorNotExists('form[action="/admin/workspaces/switch"]');
        $this->assertSelectorTextContains('[data-current-workspace-summary]', 'Единственное хозяйство');
    }

    public function testWorkspaceUserTopbarHidesWorkspaceSwitcherWhenOnlyOneWorkspaceIsAvailable(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace('main', 'Доступное хозяйство');
        $operator = $this->createUser('single-workspace-operator@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $operator, WorkspaceUserRoleCode::Operator);
        $client->loginUser($operator);

        $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('#admin-current-workspace');
        $this->assertSelectorNotExists('form[action="/admin/workspaces/switch"]');
        $this->assertSelectorTextContains('[data-current-workspace-summary]', 'Доступное хозяйство');
    }

    public function testWorkspaceUserCanSwitchOnlyBetweenGrantedWorkspaces(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $firstWorkspace = $this->createWorkspace('aaa', 'Первое СНТ');
        $secondWorkspace = $this->createWorkspace('bbb', 'Второе СНТ');
        $operator = $this->createUser('operator@example.test');
        $this->createWorkspaceRoleAssignment($firstWorkspace, $operator, WorkspaceUserRoleCode::Operator);
        $this->createWorkspaceRoleAssignment($secondWorkspace, $operator, WorkspaceUserRoleCode::Operator);
        $this->createAccount($firstWorkspace, '1-001');
        $this->createAccount($secondWorkspace, '2-001');
        $client->loginUser($operator);

        $crawler = $client->request('GET', '/admin/accounts');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '1-001');
        $this->assertSelectorExists(sprintf('select[name="workspace_uuid"] option[value="%s"]', $firstWorkspace->getUuid()->toRfc4122()));
        $this->assertSelectorExists(sprintf('select[name="workspace_uuid"] option[value="%s"]', $secondWorkspace->getUuid()->toRfc4122()));

        $client->submit($crawler->selectButton('Сменить')->form([
            'workspace_uuid' => $secondWorkspace->getUuid()->toRfc4122(),
        ]));

        $this->assertResponseRedirects('/admin', Response::HTTP_SEE_OTHER);

        $client->request('GET', '/admin/accounts');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '2-001');
        $this->assertSelectorTextNotContains('body', '1-001');
    }

    public function testWorkspaceUserCannotSwitchToUngrantedWorkspace(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $grantedWorkspace = $this->createWorkspace('aaa', 'Доступное СНТ');
        $secondGrantedWorkspace = $this->createWorkspace('ccc', 'Второе доступное СНТ');
        $otherWorkspace = $this->createWorkspace('bbb', 'Чужое СНТ');
        $operator = $this->createUser('limited-operator@example.test');
        $this->createWorkspaceRoleAssignment($grantedWorkspace, $operator, WorkspaceUserRoleCode::Operator);
        $this->createWorkspaceRoleAssignment($secondGrantedWorkspace, $operator, WorkspaceUserRoleCode::Operator);
        $client->loginUser($operator);

        $crawler = $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists(sprintf('select[name="workspace_uuid"] option[value="%s"]', $grantedWorkspace->getUuid()->toRfc4122()));
        $this->assertSelectorExists(sprintf('select[name="workspace_uuid"] option[value="%s"]', $secondGrantedWorkspace->getUuid()->toRfc4122()));
        $this->assertSelectorNotExists(sprintf('select[name="workspace_uuid"] option[value="%s"]', $otherWorkspace->getUuid()->toRfc4122()));

        $token = $crawler->filter('form[action="/admin/workspaces/switch"] input[name="_token"]')->attr('value');
        $client->request('POST', '/admin/workspaces/switch', [
            '_token' => $token,
            'workspace_uuid' => $otherWorkspace->getUuid()->toRfc4122(),
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->approve();

        $identity = new UserEmailIdentity($user, $email);
        $identity->markVerified();
        $user->addEmailIdentity($identity);

        $this->entityManager()->persist($user);
        $this->entityManager()->persist($identity);
        $this->entityManager()->flush();

        return $user;
    }

    private function createWorkspaceRoleAssignment(Workspace $workspace, User $user, WorkspaceUserRoleCode $roleCode): WorkspaceUserRoleAssignment
    {
        $assignment = new WorkspaceUserRoleAssignment($workspace, $user, $roleCode);
        $user->addWorkspaceRoleAssignment($assignment);

        $this->entityManager()->persist($assignment);
        $this->entityManager()->flush();

        return $assignment;
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
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
