<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\Subscriber;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\UserPasswordCredential;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\WorkspaceUserRoleCode;
use App\Service\UserPasswordManager;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class AdminUserControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/users');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeUsersList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $subscriberUser = $this->createUser('subscriber@example.test');
        $operatorUser = $this->createUser('operator@example.test');
        $this->createSubscriber($workspace, $subscriberUser);
        $this->createWorkspaceRoleAssignment($workspace, $operatorUser, WorkspaceUserRoleCode::Operator, $admin);
        $client->loginUser($admin);

        $client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Пользователи');
        $this->assertSelectorTextContains('body', 'admin@example.test');
        $this->assertSelectorTextContains('body', 'subscriber@example.test');
        $this->assertSelectorTextContains('body', 'Абонент');
        $this->assertSelectorTextContains('body', 'operator@example.test');
        $this->assertSelectorTextContains('body', 'Оператор хозяйства');
        $this->assertSelectorExists('a[href="/admin/users"].active');
    }

    public function testAdminCanSortAndPaginateUsersList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();

        for ($i = 1; $i <= 54; ++$i) {
            $this->createUser(sprintf('user-%03d@example.test', $i));
        }

        $client->loginUser($admin);

        $client->request('GET', '/admin/users?sort=email&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $user54Position = strpos($content, 'user-054@example.test');
        $user53Position = strpos($content, 'user-053@example.test');
        self::assertNotFalse($user54Position);
        self::assertNotFalse($user53Position);
        self::assertLessThan($user53Position, $user54Position);
        $this->assertSelectorExists('a[href="/admin/users?sort=email&dir=asc&page=1"]');

        $client->request('GET', '/admin/users?sort=email&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'user-004@example.test');
        $this->assertSelectorTextContains('body', 'admin@example.test');
        $this->assertSelectorTextNotContains('body', 'user-054@example.test');
    }

    public function testWorkspaceAdminCanAccessAdminWithoutGlobalAdminFlag(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace();
        $workspaceAdmin = $this->createUser('workspace-admin@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $workspaceAdmin, WorkspaceUserRoleCode::Admin);
        $client->loginUser($workspaceAdmin);

        $client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'workspace-admin@example.test');
        $this->assertSelectorTextContains('body', 'Администратор хозяйства');
    }

    public function testWorkspaceOperatorCanAccessAdminButCannotManageWorkspaceRoles(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace();
        $operator = $this->createUser('workspace-operator@example.test');
        $targetUser = $this->createUser('operator-target@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $operator, WorkspaceUserRoleCode::Operator);
        $client->loginUser($operator);

        $client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', 'Создать пользователя');
        $this->assertSelectorTextNotContains('body', 'Выдать доступ к хозяйству');

        $client->request('GET', sprintf('/admin/users/%s', $targetUser->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', 'Связать абонента');
        $this->assertSelectorTextNotContains('body', 'Добавить email');
        $this->assertSelectorTextNotContains('body', 'Установить пароль');
        $this->assertSelectorTextNotContains('body', 'Действия доступа');
        $this->assertSelectorTextNotContains('body', 'Назначить роль хозяйства');
        $this->assertSelectorTextContains('body', 'Оператор хозяйства');

        $forbiddenRequests = [
            ['GET', '/admin/users/new'],
            ['POST', sprintf('/admin/users/%s/emails/add', $targetUser->getUuid())],
            ['POST', sprintf('/admin/users/%s/emails/%s/delete', $targetUser->getUuid(), UserEmailIdentity::normalizeEmail('operator-target@example.test'))],
            ['POST', sprintf('/admin/users/%s/approve', $targetUser->getUuid())],
            ['POST', sprintf('/admin/users/%s/block', $targetUser->getUuid())],
            ['POST', sprintf('/admin/users/%s/unblock', $targetUser->getUuid())],
            ['POST', sprintf('/admin/users/%s/delete', $targetUser->getUuid())],
            ['POST', sprintf('/admin/users/%s/password/set', $targetUser->getUuid())],
            ['POST', sprintf('/admin/users/%s/subscriber/link', $targetUser->getUuid())],
            ['POST', sprintf('/admin/users/%s/subscriber/unlink', $targetUser->getUuid())],
            ['POST', sprintf('/admin/users/%s/workspace-roles/grant', $targetUser->getUuid())],
            ['POST', '/admin/users/workspace-access/grant'],
        ];

        foreach ($forbiddenRequests as [$method, $path]) {
            $client->request($method, $path);

            $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, sprintf('%s %s must require WORKSPACE_ADMIN.', $method, $path));
        }
    }

    public function testAdminCanGrantAndRevokeWorkspaceRole(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $targetUser = $this->createUser('workspace-role-target@example.test');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/users/%s', $targetUser->getUuid()));
        $client->submitForm('Назначить роль хозяйства', [
            'workspace_user_role_grant[roleCode]' => WorkspaceUserRoleCode::Operator->value,
        ]);

        $this->assertResponseRedirects(sprintf('/admin/users/%s', $targetUser->getUuid()), Response::HTTP_SEE_OTHER);
        $assignment = $this->findActiveWorkspaceRole($workspace, $targetUser, WorkspaceUserRoleCode::Operator);

        self::assertInstanceOf(WorkspaceUserRoleAssignment::class, $assignment);
        self::assertSame($admin->getUuid()->toRfc4122(), $assignment->getGrantedBy()?->getUuid()->toRfc4122());
        self::assertSame(1, $this->countAuditLogs('workspace_user_role.granted'));

        $crawler = $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Оператор хозяйства');

        $revokeButton = $crawler->filter(sprintf('form[action$="/workspace-roles/%s/revoke"] button', $assignment->getUuid()->toRfc4122()));
        self::assertSame(1, $revokeButton->count());

        $client->submit($revokeButton->form([
            'workspace_user_role_revoke[reason]' => 'Ошибочно выдана роль.',
        ]));

        $this->assertResponseRedirects(sprintf('/admin/users/%s', $targetUser->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNull($this->findActiveWorkspaceRole($workspace, $targetUser, WorkspaceUserRoleCode::Operator));
        self::assertSame(1, $this->countAuditLogs('workspace_user_role.revoked'));
    }

    public function testWorkspaceAdminCanGrantWorkspaceAccessAndCreateUserFromUsersIndex(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace();
        $workspaceAdmin = $this->createUser('workspace-admin@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $workspaceAdmin, WorkspaceUserRoleCode::Admin);
        $client->loginUser($workspaceAdmin);

        $client->request('GET', '/admin/users');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Выдать доступ к хозяйству');

        $client->submitForm('Выдать доступ', [
            'workspace_access_grant[email]' => 'new-operator@example.test',
            'workspace_access_grant[roleCode]' => WorkspaceUserRoleCode::Operator->value,
        ]);

        $user = $this->findUserByEmail('new-operator@example.test');

        self::assertInstanceOf(User::class, $user);
        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNotNull($user->getApprovedAt());
        self::assertSame($workspaceAdmin->getUuid()->toRfc4122(), $user->getApprovedBy()?->getUuid()->toRfc4122());
        self::assertInstanceOf(UserPasswordCredential::class, $user->getPasswordCredential());
        self::assertSame(0, $user->getPasswordCredential()?->getExpiresAt()?->getTimestamp());
        self::assertSame(1, $this->countPasswordHistory($user));
        self::assertInstanceOf(WorkspaceUserRoleAssignment::class, $this->findActiveWorkspaceRole($workspace, $user, WorkspaceUserRoleCode::Operator));
        self::assertSame(1, $this->countAuditLogs('user.created'));
        self::assertSame(1, $this->countAuditLogs('user_email_identity.created'));
        self::assertSame(1, $this->countAuditLogs('user.password_set'));
        self::assertSame(1, $this->countAuditLogs('workspace_user_role.granted'));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Доступ выдан. Email: new-operator@example.test. Временный пароль:');
        $this->assertSelectorTextContains('body', 'Оператор хозяйства');
    }

    public function testWorkspaceAdminCanGrantWorkspaceAccessToExistingUserFromUsersIndex(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace();
        $workspaceAdmin = $this->createUser('workspace-admin@example.test');
        $existingUser = $this->createUser('existing-operator@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $workspaceAdmin, WorkspaceUserRoleCode::Admin);
        $client->loginUser($workspaceAdmin);

        $client->request('GET', '/admin/users');
        $client->submitForm('Выдать доступ', [
            'workspace_access_grant[email]' => 'existing-operator@example.test',
            'workspace_access_grant[roleCode]' => WorkspaceUserRoleCode::Operator->value,
        ]);

        $this->assertResponseRedirects(sprintf('/admin/users/%s', $existingUser->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertInstanceOf(WorkspaceUserRoleAssignment::class, $this->findActiveWorkspaceRole($workspace, $existingUser, WorkspaceUserRoleCode::Operator));
        self::assertSame(0, $this->countAuditLogs('user.created'));
        self::assertSame(0, $this->countAuditLogs('user.password_set'));
        self::assertSame(1, $this->countAuditLogs('workspace_user_role.granted'));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Доступ к хозяйству выдан пользователю existing-operator@example.test.');
        $this->assertSelectorTextNotContains('body', 'Временный пароль:');
    }

    public function testWorkspaceAdminCannotGrantDuplicateWorkspaceAccessFromUsersIndex(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace();
        $workspaceAdmin = $this->createUser('workspace-admin@example.test');
        $targetUser = $this->createUser('duplicate-role@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $workspaceAdmin, WorkspaceUserRoleCode::Admin);
        $this->createWorkspaceRoleAssignment($workspace, $targetUser, WorkspaceUserRoleCode::Operator);
        $client->loginUser($workspaceAdmin);

        $client->request('GET', '/admin/users');
        $client->submitForm('Выдать доступ', [
            'workspace_access_grant[email]' => 'duplicate-role@example.test',
            'workspace_access_grant[roleCode]' => WorkspaceUserRoleCode::Operator->value,
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'У пользователя уже есть такая активная роль в текущем хозяйстве.');
        self::assertSame(1, $this->countActiveWorkspaceRoles($workspace, $targetUser, WorkspaceUserRoleCode::Operator));
        self::assertSame(0, $this->countAuditLogs('workspace_user_role.granted'));
    }

    public function testAdminCanCreateUser(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/users/new');

        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'user_create[email]' => 'new-user@example.test',
            'user_create[plainPassword][first]' => 'temporary-pass-123',
            'user_create[plainPassword][second]' => 'temporary-pass-123',
            'user_create[approved]' => '1',
        ]);

        $user = $this->findUserByEmail('new-user@example.test');

        self::assertInstanceOf(User::class, $user);
        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNotNull($user->getApprovedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $user->getApprovedBy()?->getUuid()->toRfc4122());
        self::assertSame('new-user@example.test', $user->getPrimaryEmail());
        self::assertFalse($user->isAdmin());
        self::assertInstanceOf(UserPasswordCredential::class, $user->getPasswordCredential());
        self::assertSame(1, $this->countPasswordHistory($user));
        self::assertSame(1, $this->countAuditLogs('user.created'));
        self::assertSame(1, $this->countAuditLogs('user_email_identity.created'));
        self::assertSame(1, $this->countAuditLogs('user.password_set'));

        $client->followRedirect();
        $content = (string) $client->getResponse()->getContent();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Пользователь new-user@example.test');
        $this->assertSelectorTextContains('body', 'Активен');
        self::assertStringNotContainsString($user->getPasswordCredential()->getPasswordHash(), $content);
    }

    public function testAdminCannotCreateUserWithDuplicateActiveEmail(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $this->createUser('duplicate@example.test');
        $client->loginUser($admin);

        $client->request('GET', '/admin/users/new');
        $client->submitForm('Сохранить', [
            'user_create[email]' => 'DUPLICATE@example.test',
            'user_create[plainPassword][first]' => 'temporary-pass-123',
            'user_create[plainPassword][second]' => 'temporary-pass-123',
            'user_create[approved]' => '1',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Активный пользователь с таким email уже существует.');
        self::assertSame(1, $this->countActiveEmails('duplicate@example.test'));
    }

    public function testAdminCanAddAndDetachEmail(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $user = $this->createUser('primary@example.test');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/users/%s', $user->getUuid()));
        $client->submitForm('Добавить email', [
            'user_email_add[email]' => 'secondary@example.test',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame(1, $this->countActiveEmails('secondary@example.test'));
        self::assertSame(1, $this->countAuditLogs('user_email_identity.created'));

        $client->followRedirect();
        $client->submitForm('Отвязать');

        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame(0, $this->countActiveEmails('primary@example.test'));
        self::assertSame(1, $this->countActiveEmails('secondary@example.test'));
        self::assertSame(1, $this->countAuditLogs('user_email_identity.deleted'));
    }

    public function testAdminCannotDetachLastActiveEmailWithPassword(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $user = $this->createUser('only@example.test');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/users/%s', $user->getUuid()));
        $client->submitForm('Отвязать');

        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame(1, $this->countActiveEmails('only@example.test'));
    }

    public function testAdminCanApprovePendingUser(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $user = $this->createUser('pending-user@example.test', approved: false);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/users/%s', $user->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Ожидает одобрения');

        $client->submitForm('Одобрить пользователя');

        $approvedUser = $this->findUserByEmail('pending-user@example.test');

        self::assertInstanceOf(User::class, $approvedUser);
        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNotNull($approvedUser->getApprovedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $approvedUser->getApprovedBy()?->getUuid()->toRfc4122());
        self::assertSame(1, $this->countAuditLogs('user.approved'));
    }

    public function testAdminCanBlockAndUnblockUser(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $user = $this->createUser('blocked-user@example.test');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/users/%s', $user->getUuid()));
        $client->submitForm('Заблокировать', [
            'user_block[reason]' => 'Документы требуют проверки.',
        ]);

        $blockedUser = $this->findUserByEmail('blocked-user@example.test');

        self::assertInstanceOf(User::class, $blockedUser);
        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNotNull($blockedUser->getBlockedAt());
        self::assertSame('Документы требуют проверки.', $blockedUser->getBlockedReason());
        self::assertSame(1, $this->countAuditLogs('user.blocked'));

        $client->followRedirect();
        $client->submitForm('Разблокировать');

        $unblockedUser = $this->findUserByEmail('blocked-user@example.test');

        self::assertInstanceOf(User::class, $unblockedUser);
        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNull($unblockedUser->getBlockedAt());
        self::assertSame(1, $this->countAuditLogs('user.unblocked'));
    }

    public function testAdminCannotBlockSelf(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/users/%s', $admin->getUuid()));
        $client->submitForm('Заблокировать', [
            'user_block[reason]' => 'Ошибка оператора.',
        ]);

        $reloadedAdmin = $this->findUserByEmail('admin@example.test');

        self::assertInstanceOf(User::class, $reloadedAdmin);
        $this->assertResponseRedirects(sprintf('/admin/users/%s', $admin->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertNull($reloadedAdmin->getBlockedAt());
    }

    public function testAdminCanSoftDeleteUserAndReuseEmail(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $user = $this->createUser('deleted-user@example.test');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/users/%s', $user->getUuid()));
        $client->submitForm('Удалить пользователя');

        $deletedUser = $this->entityManager()->getRepository(User::class)->find($user->getUuid());

        self::assertInstanceOf(User::class, $deletedUser);
        $this->assertResponseRedirects('/admin/users', Response::HTTP_SEE_OTHER);
        self::assertNotNull($deletedUser->getDeletedAt());
        self::assertSame(0, $this->countActiveEmails('deleted-user@example.test'));
        self::assertSame(1, $this->countAuditLogs('user.deleted'));
        self::assertSame(1, $this->countAuditLogs('user_email_identity.deleted'));

        $client->followRedirect();
        $client->request('GET', '/admin/users/new');
        $client->submitForm('Сохранить', [
            'user_create[email]' => 'deleted-user@example.test',
            'user_create[plainPassword][first]' => 'temporary-pass-123',
            'user_create[plainPassword][second]' => 'temporary-pass-123',
            'user_create[approved]' => '1',
        ]);

        $this->assertResponseRedirects(null, Response::HTTP_SEE_OTHER);
        self::assertSame(1, $this->countActiveEmails('deleted-user@example.test'));
    }

    public function testAdminCanSetPasswordAndHistoryIsUpdated(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $user = $this->createUser('password-user@example.test');
        $initialHistoryCount = $this->countPasswordHistory($user);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/users/%s', $user->getUuid()));
        $client->submitForm('Установить пароль', [
            'user_password_set[plainPassword][first]' => 'new-temporary-pass-123',
            'user_password_set[plainPassword][second]' => 'new-temporary-pass-123',
            'user_password_set[expiresAt]' => '31.12.2026',
        ]);

        $updatedUser = $this->findUserByEmail('password-user@example.test');

        self::assertInstanceOf(User::class, $updatedUser);
        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($initialHistoryCount + 1, $this->countPasswordHistory($updatedUser));
        self::assertSame('2026-12-31', $updatedUser->getPasswordCredential()?->getExpiresAt()?->format('Y-m-d'));
        self::assertSame(1, $this->countAuditLogs('user.password_set'));
        $passwordAuditLog = $this->findLatestAuditLog('user.password_set');

        self::assertInstanceOf(AuditLog::class, $passwordAuditLog);
        self::assertArrayNotHasKey('password_hash', $passwordAuditLog->getNewValues() ?? []);
    }

    public function testAdminCanLinkAndUnlinkSubscriberFromUserCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $user = $this->createUser('user-link@example.test');
        $subscriber = $this->createSubscriber($workspace);
        $subscriberUuid = $subscriber->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/users/%s', $user->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select.js-searchable-select[name="user_subscriber_link[subscriber]"]');

        $client->submitForm('Связать абонента', [
            'user_subscriber_link[subscriber]' => $subscriberUuid->toRfc4122(),
        ]);

        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        $linkedSubscriber = $this->findSubscriberByUuid($subscriberUuid);

        self::assertInstanceOf(Subscriber::class, $linkedSubscriber);
        self::assertSame($user->getUuid()->toRfc4122(), $linkedSubscriber->getUser()?->getUuid()->toRfc4122());

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');

        $client->submitForm('Отвязать абонента');

        $this->assertResponseRedirects(sprintf('/admin/users/%s', $user->getUuid()), Response::HTTP_SEE_OTHER);
        $unlinkedSubscriber = $this->findSubscriberByUuid($subscriberUuid);

        self::assertInstanceOf(Subscriber::class, $unlinkedSubscriber);
        self::assertNull($unlinkedSubscriber->getUser());
    }

    private function createUser(string $email, bool $approved = true): User
    {
        $user = new User();

        if ($approved) {
            $user->approve();
        }

        $identity = new UserEmailIdentity($user, $email);
        $identity->markVerified();
        $user->addEmailIdentity($identity);

        $passwordHash = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, 'test-password-123');
        $credential = new UserPasswordCredential($user, $passwordHash, new DateTimeImmutable());
        $user->setPasswordCredential($credential);

        $this->entityManager()->persist($user);
        $this->entityManager()->persist($identity);
        $this->entityManager()->persist($credential);
        $this->entityManager()->flush();
        static::getContainer()->get(UserPasswordManager::class)->setPassword($user, 'test-password-123');

        return $user;
    }

    private function createSubscriber(Workspace $workspace, ?User $user = null): Subscriber
    {
        $subscriber = (new Subscriber($workspace))
            ->setLastName('Иванов')
            ->setFirstName('Иван')
            ->setSecondName('Иванович')
            ->setUser($user);

        $this->entityManager()->persist($subscriber);
        $this->entityManager()->flush();

        return $subscriber;
    }

    private function createWorkspaceRoleAssignment(Workspace $workspace, User $user, WorkspaceUserRoleCode $roleCode, ?User $grantedBy = null): WorkspaceUserRoleAssignment
    {
        $assignment = new WorkspaceUserRoleAssignment($workspace, $user, $roleCode, $grantedBy);
        $user->addWorkspaceRoleAssignment($assignment);

        $this->entityManager()->persist($assignment);
        $this->entityManager()->flush();

        return $assignment;
    }

    private function findUserByEmail(string $email): ?User
    {
        $identity = $this->entityManager()
            ->getRepository(UserEmailIdentity::class)
            ->findOneActiveByEmailNormalized(UserEmailIdentity::normalizeEmail($email));

        return $identity?->getUser();
    }

    private function findSubscriberByUuid(Uuid $uuid): ?Subscriber
    {
        return $this->entityManager()
            ->getRepository(Subscriber::class)
            ->find($uuid);
    }

    private function countActiveEmails(string $email): int
    {
        return (int) $this->entityManager()
            ->getRepository(UserEmailIdentity::class)
            ->createQueryBuilder('identity')
            ->select('COUNT(identity.emailNormalized)')
            ->andWhere('identity.emailNormalized = :email')
            ->andWhere('identity.deletedAt IS NULL')
            ->setParameter('email', UserEmailIdentity::normalizeEmail($email))
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countPasswordHistory(User $user): int
    {
        return (int) $this->entityManager()
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM user_password_history WHERE user_uuid = ?', [$user->getUuid()->toRfc4122()]);
    }

    private function findActiveWorkspaceRole(Workspace $workspace, User $user, WorkspaceUserRoleCode $roleCode): ?WorkspaceUserRoleAssignment
    {
        return $this->entityManager()
            ->getRepository(WorkspaceUserRoleAssignment::class)
            ->findOneActiveByWorkspaceUserAndRole($workspace, $user, $roleCode);
    }

    private function countAuditLogs(string $action): int
    {
        return (int) $this->entityManager()
            ->getRepository(AuditLog::class)
            ->createQueryBuilder('audit_log')
            ->select('COUNT(audit_log.uuid)')
            ->andWhere('audit_log.action = :action')
            ->setParameter('action', $action)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function countActiveWorkspaceRoles(Workspace $workspace, User $user, WorkspaceUserRoleCode $roleCode): int
    {
        return (int) $this->entityManager()
            ->getRepository(WorkspaceUserRoleAssignment::class)
            ->createQueryBuilder('assignment')
            ->select('COUNT(assignment.uuid)')
            ->andWhere('assignment.workspace = :workspace')
            ->andWhere('assignment.user = :user')
            ->andWhere('assignment.roleCode = :roleCode')
            ->andWhere('assignment.revokedAt IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('user', $user)
            ->setParameter('roleCode', $roleCode->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function findLatestAuditLog(string $action): ?AuditLog
    {
        return $this->entityManager()
            ->getRepository(AuditLog::class)
            ->createQueryBuilder('audit_log')
            ->andWhere('audit_log.action = :action')
            ->setParameter('action', $action)
            ->orderBy('audit_log.occurredAt', 'DESC')
            ->addOrderBy('audit_log.uuid', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

}
