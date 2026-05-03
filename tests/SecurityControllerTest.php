<?php

namespace App\Tests;

use Symfony\Component\HttpFoundation\Response;

final class SecurityControllerTest extends FunctionalWebTestCase
{
    public function testAdminCanLoginThroughFormAndOpenAdminDashboard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $this->createAdminUser();
        $this->createWorkspace();

        $client->request('GET', '/login');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Вход');

        $client->submitForm('Войти', [
            '_email' => 'admin@example.test',
            '_password' => 'test-password-123',
        ]);

        $this->assertResponseRedirects('/', Response::HTTP_FOUND);

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'КомУчёт');
        $this->assertSelectorTextContains('body', 'admin@example.test');
        $this->assertSelectorExists('a[href="/admin"]');

        $client->clickLink('Админка');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Рабочий стол');
    }
}
