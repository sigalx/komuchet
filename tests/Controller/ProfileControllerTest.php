<?php

namespace App\Tests\Controller;

use App\Entity\Subscriber;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\UserPasswordCredential;
use App\Entity\Workspace;
use App\Tests\FunctionalWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/profile');

        $this->assertResponseRedirects('/login');
    }

    public function testGlobalAdminCanSeeProfile(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser('admin-profile@example.test');
        $this->createWorkspace(code: 'main', name: 'СНТ Основное');
        $client->loginUser($admin);

        $client->request('GET', '/profile');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Профиль');
        $this->assertSelectorTextContains('body', 'admin-profile@example.test');
        $this->assertSelectorTextContains('body', 'Глобальный администратор');
        $this->assertSelectorTextContains('body', 'СНТ Основное');
        $this->assertSelectorExists('a[href="/password/change"]');
        $this->assertSelectorExists('a[href="/admin"]');
        $this->assertSelectorNotExists('a[href="/portal"]');
    }

    public function testSubscriberCanSeeProfileWithoutWorkspaceAdminAccess(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace(code: 'summer', name: 'СНТ Летнее');
        $user = $this->createRegularUser('subscriber-profile@example.test');
        $this->createSubscriber($workspace, $user);
        $client->loginUser($user);

        $client->request('GET', '/profile');

        self::assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Профиль');
        $this->assertSelectorTextContains('body', 'subscriber-profile@example.test');
        $this->assertSelectorTextContains('body', 'СНТ Летнее');
        $this->assertSelectorTextContains('body', 'Абонент');
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');
        $this->assertSelectorExists('a[href="/portal"]');
        $this->assertSelectorNotExists('a[href="/admin"]');
    }

    private function createRegularUser(string $email): User
    {
        $user = new User();
        $user->approve();

        $emailIdentity = new UserEmailIdentity($user, $email);
        $emailIdentity->markVerified();
        $user->addEmailIdentity($emailIdentity);

        $passwordHash = static::getContainer()
            ->get(UserPasswordHasherInterface::class)
            ->hashPassword($user, 'test-password-123');

        $passwordCredential = new UserPasswordCredential($user, $passwordHash);
        $user->setPasswordCredential($passwordCredential);

        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function createSubscriber(Workspace $workspace, User $user): Subscriber
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
}
