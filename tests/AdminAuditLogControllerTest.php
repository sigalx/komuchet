<?php

namespace App\Tests;

use App\Entity\AuditLog;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\UserPasswordCredential;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\WorkspaceUserRoleCode;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

final class AdminAuditLogControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/audit-logs');

        $this->assertResponseRedirects('/login');
    }

    public function testWorkspaceOperatorCannotSeeAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace();
        $operator = $this->createUser('operator@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $operator, WorkspaceUserRoleCode::Operator);
        $client->loginUser($operator);

        $client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextNotContains('body', 'Аудит');

        $client->request('GET', '/admin/audit-logs');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testWorkspaceAdminSeesOnlyCurrentWorkspaceAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $otherWorkspace = $this->createWorkspace('other', 'Другое хозяйство');
        $workspaceAdmin = $this->createUser('workspace-admin@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $workspaceAdmin, WorkspaceUserRoleCode::Admin);
        $this->createAuditLog('payment.created', $workspace, $workspaceAdmin);
        $this->createAuditLog('payment.other_workspace', $otherWorkspace, $workspaceAdmin);
        $this->createAuditLog('user.global_admin_granted', null, $workspaceAdmin);
        $client->loginUser($workspaceAdmin);

        $client->request('GET', '/admin/audit-logs');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Аудит');
        $this->assertSelectorTextContains('body', 'payment.created');
        $this->assertSelectorTextContains('body', 'Основное хозяйство');
        $this->assertSelectorTextContains('body', 'workspace-admin@example.test');
        $this->assertSelectorTextNotContains('body', 'payment.other_workspace');
        $this->assertSelectorTextNotContains('body', 'user.global_admin_granted');
        $this->assertSelectorNotExists('select[name="workspace"]');
        $this->assertSelectorExists('a[href="/admin/audit-logs"].active');
    }

    public function testGlobalAdminCanSortAndPaginateAuditLogs(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $globalAdmin = $this->createAdminUser('global-admin@example.test');
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');

        for ($i = 1; $i <= 55; ++$i) {
            $this->createAuditLog(sprintf('event-%03d', $i), $workspace, $globalAdmin);
        }

        $client->loginUser($globalAdmin);

        $client->request('GET', '/admin/audit-logs?sort=action&dir=desc');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $content = (string) $client->getResponse()->getContent();
        $event55Position = strpos($content, 'event-055');
        $event54Position = strpos($content, 'event-054');
        self::assertNotFalse($event55Position);
        self::assertNotFalse($event54Position);
        self::assertLessThan($event54Position, $event55Position);
        $this->assertSelectorExists('a[href="/admin/audit-logs?sort=action&dir=asc&page=1"]');

        $client->request('GET', '/admin/audit-logs?sort=action&dir=desc&page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', 'event-005');
        $this->assertSelectorTextNotContains('body', 'event-055');
    }

    public function testGlobalAdminSeesAllAuditLogsAndCanFilter(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $globalAdmin = $this->createAdminUser('global-admin@example.test');
        $workspace = $this->createWorkspace('main', 'Основное хозяйство');
        $otherWorkspace = $this->createWorkspace('other', 'Другое хозяйство');
        $otherEntityUuid = Uuid::v7();
        $this->createAuditLog('payment.created', $workspace, $globalAdmin);
        $this->createAuditLog('payment.other_workspace', $otherWorkspace, $globalAdmin, $otherEntityUuid);
        $this->createAuditLog('user.global_admin_granted', null, $globalAdmin);
        $client->loginUser($globalAdmin);

        $client->request('GET', '/admin/audit-logs');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'payment.created');
        $this->assertSelectorTextContains('body', 'payment.other_workspace');
        $this->assertSelectorTextContains('body', 'user.global_admin_granted');
        $this->assertSelectorExists('select[name="workspace"]');

        $client->request('GET', '/admin/audit-logs?workspace=global');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'user.global_admin_granted');
        $this->assertSelectorTextNotContains('body', 'payment.created');
        $this->assertSelectorTextNotContains('body', 'payment.other_workspace');

        $client->request('GET', sprintf('/admin/audit-logs?workspace=%s&entity_uuid=%s', $otherWorkspace->getUuid()->toRfc4122(), $otherEntityUuid->toRfc4122()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'payment.other_workspace');
        $this->assertSelectorTextContains('body', $otherEntityUuid->toRfc4122());
        $this->assertSelectorTextNotContains('body', 'payment.created');

        $client->request('GET', '/admin/audit-logs?actor=global-admin@example.test&action=payment');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'payment.created');
        $this->assertSelectorTextContains('body', 'payment.other_workspace');
        $this->assertSelectorTextNotContains('body', 'user.global_admin_granted');
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->approve();

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

    private function createAuditLog(string $action, ?Workspace $workspace, ?User $actorUser, ?Uuid $entityUuid = null): AuditLog
    {
        $auditLog = (new AuditLog($action))
            ->setWorkspace($workspace)
            ->setActorUser($actorUser)
            ->setEntityTable('payments')
            ->setEntityUuid($entityUuid ?? Uuid::v7())
            ->setChangedFields(['amount'])
            ->setReason('Тестовое событие аудита.')
            ->setNewValues(['amount' => '100.00']);

        $this->entityManager()->persist($auditLog);
        $this->entityManager()->flush();

        return $auditLog;
    }
}
