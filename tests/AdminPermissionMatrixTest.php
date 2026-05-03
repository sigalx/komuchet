<?php

namespace App\Tests;

use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\WorkspaceUserRoleCode;
use Symfony\Component\HttpFoundation\Response;

final class AdminPermissionMatrixTest extends FunctionalWebTestCase
{
    public function testWorkspaceOperatorCanOpenWorkingAdminSectionsButNotSystemAdminSections(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace();
        $operator = $this->createUser('operator@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $operator, WorkspaceUserRoleCode::Operator);
        $client->loginUser($operator);

        foreach ($this->operatorWorkingSectionPaths() as $path) {
            $client->request('GET', $path);

            $this->assertResponseIsSuccessful(sprintf('Operator must be able to open %s.', $path));
        }

        foreach (['/admin/audit-logs', '/admin/workspaces'] as $path) {
            $client->request('GET', $path);

            $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, sprintf('Operator must not be able to open %s.', $path));
        }
    }

    public function testWorkspaceAdminCanOpenAuditButCannotManageWorkspaceRegistry(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace();
        $workspaceAdmin = $this->createUser('workspace-admin@example.test');
        $this->createWorkspaceRoleAssignment($workspace, $workspaceAdmin, WorkspaceUserRoleCode::Admin);
        $client->loginUser($workspaceAdmin);

        $client->request('GET', '/admin/audit-logs');

        $this->assertResponseIsSuccessful();

        $client->request('GET', '/admin/workspaces');

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testGlobalAdminCanOpenSystemAdminSections(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $globalAdmin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($globalAdmin);

        foreach (['/admin/audit-logs', '/admin/workspaces'] as $path) {
            $client->request('GET', $path);

            $this->assertResponseIsSuccessful(sprintf('Global admin must be able to open %s.', $path));
        }
    }

    /**
     * @return list<string>
     */
    private function operatorWorkingSectionPaths(): array
    {
        return [
            '/admin',
            '/admin/accounts',
            '/admin/account-balances',
            '/admin/account-groups',
            '/admin/account-statement-deliveries',
            '/admin/account-statements',
            '/admin/accruals',
            '/admin/billing-run-issues',
            '/admin/billing-runs',
            '/admin/billing-settings',
            '/admin/electricity-consumption-band-rules',
            '/admin/electricity-consumption-bands',
            '/admin/electricity-meter-readings',
            '/admin/electricity-meters',
            '/admin/electricity-tariff-profiles',
            '/admin/electricity-tariff-zones',
            '/admin/payment-requisite-profiles',
            '/admin/payments',
            '/admin/subscribers',
            '/admin/users',
        ];
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
}
