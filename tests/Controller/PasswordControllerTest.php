<?php

namespace App\Tests\Controller;

use App\Tests\FunctionalWebTestCase;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Response;

final class PasswordControllerTest extends FunctionalWebTestCase
{
    public function testUserCanChangePasswordFromProfileAndReturnToProfile(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser('profile-password@example.test');
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/password/change');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Смена пароля');

        $client->submitForm('Сохранить пароль', [
            'password_change[currentPassword]' => 'test-password-123',
            'password_change[plainPassword][first]' => 'updated-password-123',
            'password_change[plainPassword][second]' => 'updated-password-123',
        ]);

        $this->assertResponseRedirects('/profile', Response::HTTP_SEE_OTHER);
        self::assertSame(
            1,
            (int) $this->entityManager()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM audit_logs WHERE action = ?',
                ['user.password_changed'],
            ),
        );

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Профиль');
        $this->assertSelectorTextContains('body', 'Пароль изменен.');
    }

    public function testExpiredPasswordRequiresPasswordChangeAfterLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser('expired-admin@example.test', new DateTimeImmutable('@0'));
        $this->createWorkspace();

        $client->request('GET', '/login');
        $client->submitForm('Войти', [
            '_email' => 'expired-admin@example.test',
            '_password' => 'test-password-123',
        ]);

        $this->assertResponseRedirects('/', Response::HTTP_FOUND);

        $client->followRedirect();

        $this->assertResponseRedirects('/password/change', Response::HTTP_SEE_OTHER);

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Смена пароля');
        $this->assertSelectorTextContains('body', 'Пароль больше не действителен');

        $client->request('GET', '/admin');

        $this->assertResponseRedirects('/password/change', Response::HTTP_SEE_OTHER);

        $client->request('GET', '/password/change');
        $client->submitForm('Сохранить пароль', [
            'password_change[currentPassword]' => 'wrong-password',
            'password_change[plainPassword][first]' => 'updated-password-123',
            'password_change[plainPassword][second]' => 'updated-password-123',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Текущий пароль указан неверно.');

        $client->request('GET', '/password/change');
        $client->submitForm('Сохранить пароль', [
            'password_change[currentPassword]' => 'test-password-123',
            'password_change[plainPassword][first]' => 'updated-password-123',
            'password_change[plainPassword][second]' => 'updated-password-123',
        ]);

        $this->assertResponseRedirects('/', Response::HTTP_SEE_OTHER);

        $expiresAt = $this->entityManager()->getConnection()->fetchOne(
            'SELECT expires_at FROM user_password_credentials WHERE user_uuid = ?',
            [$admin->getUuid()->toRfc4122()],
        );

        self::assertNull($expiresAt);
        self::assertSame(
            1,
            (int) $this->entityManager()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM audit_logs WHERE action = ?',
                ['user.password_changed'],
            ),
        );

        $client->followRedirect();

        self::assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'КомУчёт');
    }
}
