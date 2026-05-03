<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\Workspace;

final class AdminPaginationTest extends FunctionalWebTestCase
{
    public function testAdminAccountsListIsPaginated(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();

        for ($i = 1; $i <= 55; ++$i) {
            $this->createAccount($workspace, sprintf('9-%03d', $i));
        }

        $client->loginUser($admin);

        $client->request('GET', '/admin/accounts');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 1-50 из 55');
        $this->assertSelectorTextContains('body', '9-001');
        $this->assertSelectorTextContains('body', '9-050');
        $this->assertSelectorTextNotContains('body', '9-051');
        $this->assertSelectorExists('a.page-link[href="/admin/accounts?page=2"]');

        $client->request('GET', '/admin/accounts?page=2');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Показано 51-55 из 55');
        $this->assertSelectorTextContains('body', '9-051');
        $this->assertSelectorTextContains('body', '9-055');
        $this->assertSelectorTextNotContains('body', '9-050');
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }
}
