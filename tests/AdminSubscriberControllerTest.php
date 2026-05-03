<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\UserPasswordCredential;
use App\Entity\Workspace;
use App\Enum\SubscriberAccountAccessRole;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class AdminSubscriberControllerTest extends FunctionalWebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $client = static::createClient();
        $this->resetDatabase();

        $client->request('GET', '/admin/subscribers');

        $this->assertResponseRedirects('/login');
    }

    public function testAdminCanSeeEmptySubscribersList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/subscribers');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Абоненты');
        $this->assertSelectorTextContains('td', 'Абоненты пока не созданы.');
    }

    public function testAdminCanCreateSubscriber(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/subscribers/new');
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'subscriber[lastName]' => 'Иванов',
            'subscriber[firstName]' => 'Иван',
            'subscriber[secondName]' => 'Иванович',
            'subscriber[contactEmail]' => 'ivanov@example.test',
            'subscriber[contactPhone]' => '+7 900 000-00-00',
            'subscriber[notes]' => 'Создано тестом',
        ]);

        $subscriber = $this->findSubscriberByName('Иванов', 'Иван');

        self::assertInstanceOf(Subscriber::class, $subscriber);
        $this->assertResponseRedirects(sprintf('/admin/subscribers/%s', $subscriber->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame($workspace->getUuid()->toRfc4122(), $subscriber->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame('Иванов Иван Иванович', $subscriber->getDisplayName());
        self::assertSame('ivanov@example.test', $subscriber->getContactEmail());
        self::assertSame('+7 900 000-00-00', $subscriber->getContactPhone());
        self::assertSame('Создано тестом', $subscriber->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $subscriber->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertNull($subscriber->getDeletedAt());
    }

    public function testAdminCanFilterSubscribersList(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $linkedSubscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $linkedSubscriber
            ->setContactEmail('ivanov@example.test')
            ->setContactPhone('+7 900 000-00-00')
            ->setUser($this->createUser('ivanov-login@example.test'));
        $unlinkedSubscriber = $this->createSubscriber($workspace, 'Петров', 'Петр', 'Петрович');
        $unlinkedSubscriber->setContactEmail('petrov@example.test');
        $deletedSubscriber = $this->createSubscriber($workspace, 'Сидоров', 'Сидор', 'Сидорович');
        $deletedSubscriber->delete();
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $linkedSubscriber, $account);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/subscribers?q=ivanov@example.test');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');
        $this->assertSelectorTextNotContains('body', 'Петров Петр Петрович');
        $this->assertSelectorTextNotContains('body', 'Сидоров Сидор Сидорович');
        $this->assertSelectorExists('input[name="q"][value="ivanov@example.test"]');

        $client->request('GET', '/admin/subscribers?portal=linked');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');
        $this->assertSelectorTextNotContains('body', 'Петров Петр Петрович');

        $client->request('GET', '/admin/subscribers?portal=unlinked');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Петров Петр Петрович');
        $this->assertSelectorTextNotContains('body', 'Иванов Иван Иванович');

        $client->request('GET', '/admin/subscribers?accounts=with_accounts');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Иванов Иван Иванович');
        $this->assertSelectorTextNotContains('body', 'Петров Петр Петрович');

        $client->request('GET', '/admin/subscribers?accounts=without_accounts');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Петров Петр Петрович');
        $this->assertSelectorTextNotContains('body', 'Иванов Иван Иванович');

        $client->request('GET', '/admin/subscribers?sort=full_name&dir=desc');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $petrovPosition = strpos($content, 'Петров Петр Петрович');
        $ivanovPosition = strpos($content, 'Иванов Иван Иванович');
        self::assertNotFalse($petrovPosition);
        self::assertNotFalse($ivanovPosition);
        self::assertLessThan($ivanovPosition, $petrovPosition);
        $this->assertSelectorExists('a[href="/admin/subscribers?sort=full_name&dir=asc&page=1"]');

        $client->request('GET', '/admin/subscribers?sort=email&dir=asc');

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $ivanovPosition = strpos($content, 'ivanov@example.test');
        $petrovPosition = strpos($content, 'petrov@example.test');
        self::assertNotFalse($ivanovPosition);
        self::assertNotFalse($petrovPosition);
        self::assertLessThan($petrovPosition, $ivanovPosition);
    }

    public function testAdminSubscriberSearchTreatsYoAndEAsEqual(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $this->createSubscriber($workspace, 'Семёнов', 'Пётр', 'Сергеевич');
        $this->createSubscriber($workspace, 'Семенов', 'Андрей', 'Иванович');
        $this->createSubscriber($workspace, 'Петров', 'Иван', 'Иванович');
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', '/admin/subscribers?q=Семенов');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Семёнов Пётр Сергеевич');
        $this->assertSelectorTextContains('body', 'Семенов Андрей Иванович');
        $this->assertSelectorTextNotContains('body', 'Петров Иван Иванович');

        $client->request('GET', '/admin/subscribers?q=Семёнов');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Семёнов Пётр Сергеевич');
        $this->assertSelectorTextContains('body', 'Семенов Андрей Иванович');

        $client->request('GET', '/admin/subscribers?q=петр');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Семёнов Пётр Сергеевич');
        $this->assertSelectorTextNotContains('body', 'Семенов Андрей Иванович');
    }

    public function testAdminCannotCreateSubscriberWithoutRequiredName(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $this->createWorkspace();
        $client->loginUser($admin);

        $client->request('GET', '/admin/subscribers/new');
        $client->submitForm('Сохранить', [
            'subscriber[lastName]' => '',
            'subscriber[firstName]' => '',
            'subscriber[secondName]' => '',
            'subscriber[contactEmail]' => 'not-an-email',
            'subscriber[contactPhone]' => '',
            'subscriber[notes]' => '',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(0, $this->countSubscribers());
    }

    public function testAdminCanEditSubscriber(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/subscribers/%s/edit', $subscriber->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Сохранить', [
            'subscriber[lastName]' => 'Петров',
            'subscriber[firstName]' => 'Петр',
            'subscriber[secondName]' => 'Петрович',
            'subscriber[contactEmail]' => 'petrov@example.test',
            'subscriber[contactPhone]' => '+7 900 000-00-01',
            'subscriber[notes]' => 'После исправления',
        ]);

        $updatedSubscriber = $this->findSubscriberByUuid($subscriber->getUuid());

        self::assertInstanceOf(Subscriber::class, $updatedSubscriber);
        $this->assertResponseRedirects(sprintf('/admin/subscribers/%s', $updatedSubscriber->getUuid()), Response::HTTP_SEE_OTHER);
        self::assertSame('Петров Петр Петрович', $updatedSubscriber->getDisplayName());
        self::assertSame('petrov@example.test', $updatedSubscriber->getContactEmail());
        self::assertSame('+7 900 000-00-01', $updatedSubscriber->getContactPhone());
        self::assertSame('После исправления', $updatedSubscriber->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $updatedSubscriber->getUpdatedBy()?->getUuid()->toRfc4122());
    }

    public function testAdminCanSoftDeleteSubscriber(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $subscriberUuid = $subscriber->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/subscribers/%s/edit', $subscriberUuid));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Удалить');
        $this->assertResponseRedirects('/admin/subscribers', Response::HTTP_SEE_OTHER);

        $deletedSubscriber = $this->findSubscriberByUuid($subscriberUuid);

        self::assertInstanceOf(Subscriber::class, $deletedSubscriber);
        self::assertNotNull($deletedSubscriber->getDeletedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $deletedSubscriber->getDeletedBy()?->getUuid()->toRfc4122());

        $client->request('GET', '/admin/subscribers');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Абоненты пока не созданы.');
    }

    public function testAdminCanGrantSubscriberAccessToAccount(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $account = $this->createAccount($workspace, '9-123');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/subscribers/%s', $subscriber->getUuid()));
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select.js-searchable-select[name="subscriber_account_access_grant[account]"]');

        $client->submitForm('Выдать доступ', [
            'subscriber_account_access_grant[account]' => $account->getUuid()->toRfc4122(),
            'subscriber_account_access_grant[accessRole]' => SubscriberAccountAccessRole::Owner->value,
            'subscriber_account_access_grant[notes]' => 'Право собственности проверено',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/subscribers/%s', $subscriber->getUuid()), Response::HTTP_SEE_OTHER);

        $client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', '9-123');
        $this->assertSelectorTextContains('body', 'Владелец');
        $this->assertSelectorExists(sprintf('a[href="/admin/accounts/%s"]', $account->getUuid()->toRfc4122()));

        $access = $this->findActiveAccess($subscriber, $account);

        self::assertInstanceOf(SubscriberAccountAccess::class, $access);
        self::assertSame($workspace->getUuid()->toRfc4122(), $access->getWorkspace()?->getUuid()->toRfc4122());
        self::assertSame(SubscriberAccountAccessRole::Owner, $access->getAccessRole());
        self::assertSame('Право собственности проверено', $access->getNotes());
        self::assertSame($admin->getUuid()->toRfc4122(), $access->getGrantedBy()?->getUuid()->toRfc4122());
        self::assertNull($access->getRevokedAt());
    }

    public function testAdminCannotGrantDuplicateActiveAccess(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccount($workspace, '9-124');
        $this->createAccess($workspace, $subscriber, $account);
        $client->loginUser($admin);

        $crawler = $client->request('GET', sprintf('/admin/subscribers/%s', $subscriber->getUuid()));
        $token = $crawler->filter('#subscriber_account_access_grant__token')->attr('value');

        $client->request('POST', sprintf('/admin/subscribers/%s/accesses/grant', $subscriber->getUuid()), [
            'subscriber_account_access_grant' => [
                'account' => $account->getUuid()->toRfc4122(),
                'accessRole' => SubscriberAccountAccessRole::Owner->value,
                'notes' => '',
                '_token' => $token,
            ],
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(1, $this->countActiveAccesses($subscriber, $account));
    }

    public function testAdminCanRevokeSubscriberAccess(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/subscribers/%s', $subscriber->getUuid()));
        $this->assertResponseIsSuccessful();

        $client->submitForm('Отозвать');
        $this->assertResponseRedirects(sprintf('/admin/subscribers/%s', $subscriber->getUuid()), Response::HTTP_SEE_OTHER);

        $access = $this->findAnyAccess($subscriber, $account);

        self::assertInstanceOf(SubscriberAccountAccess::class, $access);
        self::assertNotNull($access->getRevokedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $access->getRevokedBy()?->getUuid()->toRfc4122());
        self::assertSame(0, $this->countActiveAccesses($subscriber, $account));

        $client->request('GET', sprintf('/admin/subscribers/%s', $subscriber->getUuid()));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('td', 'Доступ к участкам пока не выдан.');
    }

    public function testAdminCanGrantPortalAccessAndCreateUserFromSubscriberCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $subscriber->setContactEmail('new-subscriber@example.test');
        $this->entityManager()->flush();
        $subscriberUuid = $subscriber->getUuid();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/subscribers/%s', $subscriberUuid));

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('input[name="subscriber_portal_access_grant[email]"]');

        $client->submitForm('Подключить к порталу', [
            'subscriber_portal_access_grant[email]' => 'new-subscriber@example.test',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/subscribers/%s', $subscriberUuid), Response::HTTP_SEE_OTHER);
        $linkedSubscriber = $this->findSubscriberByUuid($subscriberUuid);

        self::assertInstanceOf(Subscriber::class, $linkedSubscriber);
        $user = $this->findUserByEmail('new-subscriber@example.test');

        self::assertInstanceOf(User::class, $user);
        self::assertSame($user->getUuid()->toRfc4122(), $linkedSubscriber->getUser()?->getUuid()->toRfc4122());
        self::assertNotNull($user->getApprovedAt());
        self::assertSame($admin->getUuid()->toRfc4122(), $user->getCreatedBy()?->getUuid()->toRfc4122());
        self::assertInstanceOf(UserPasswordCredential::class, $user->getPasswordCredential());
        self::assertSame(0, $user->getPasswordCredential()?->getExpiresAt()?->getTimestamp());
        self::assertSame(1, $this->countPasswordHistory($user));
        self::assertSame(1, $this->countAuditLogs('subscriber.portal_access.created_user'));

        $client->followRedirect();

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'new-subscriber@example.test');
        $this->assertSelectorTextContains('body', 'Временный пароль');

        $client->submitForm('Отвязать пользователя');

        $this->assertResponseRedirects(sprintf('/admin/subscribers/%s', $subscriberUuid), Response::HTTP_SEE_OTHER);
        $unlinkedSubscriber = $this->findSubscriberByUuid($subscriberUuid);

        self::assertInstanceOf(Subscriber::class, $unlinkedSubscriber);
        self::assertNull($unlinkedSubscriber->getUser());
    }

    public function testAdminCanGrantPortalAccessToExistingUserFromSubscriberCard(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван', 'Иванович');
        $subscriberUuid = $subscriber->getUuid();
        $user = $this->createUser('existing-subscriber@example.test');
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/subscribers/%s', $subscriberUuid));
        $client->submitForm('Подключить к порталу', [
            'subscriber_portal_access_grant[email]' => 'existing-subscriber@example.test',
        ]);

        $this->assertResponseRedirects(sprintf('/admin/subscribers/%s', $subscriberUuid), Response::HTTP_SEE_OTHER);
        $linkedSubscriber = $this->findSubscriberByUuid($subscriberUuid);

        self::assertInstanceOf(Subscriber::class, $linkedSubscriber);
        self::assertSame($user->getUuid()->toRfc4122(), $linkedSubscriber->getUser()?->getUuid()->toRfc4122());
        self::assertSame(1, $this->countActiveEmails('existing-subscriber@example.test'));
        self::assertSame(1, $this->countAuditLogs('subscriber.portal_access.granted'));
    }

    public function testAdminCannotGrantPortalAccessToUserAlreadyLinkedInWorkspace(): void
    {
        $client = static::createClient();
        $this->resetDatabase();
        $admin = $this->createAdminUser();
        $workspace = $this->createWorkspace();
        $existingSubscriber = $this->createSubscriber($workspace, 'Петров', 'Петр');
        $subscriber = $this->createSubscriber($workspace, 'Иванов', 'Иван');
        $user = $this->createUser('linked-subscriber@example.test');
        $existingSubscriber->setUser($user);
        $this->entityManager()->flush();
        $client->loginUser($admin);

        $client->request('GET', sprintf('/admin/subscribers/%s', $subscriber->getUuid()));
        $client->submitForm('Подключить к порталу', [
            'subscriber_portal_access_grant[email]' => 'linked-subscriber@example.test',
        ]);

        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSelectorTextContains('body', 'Пользователь уже связан с абонентом Петров Петр.');

        $reloadedSubscriber = $this->findSubscriberByUuid($subscriber->getUuid());

        self::assertInstanceOf(Subscriber::class, $reloadedSubscriber);
        self::assertNull($reloadedSubscriber->getUser());
    }

    private function createSubscriber(Workspace $workspace, string $lastName, string $firstName, ?string $secondName = null): Subscriber
    {
        $subscriber = (new Subscriber($workspace))
            ->setLastName($lastName)
            ->setFirstName($firstName)
            ->setSecondName($secondName);

        $this->entityManager()->persist($subscriber);
        $this->entityManager()->flush();

        return $subscriber;
    }

    private function createAccount(Workspace $workspace, string $number): Account
    {
        $account = (new Account($workspace))
            ->setNumber($number);

        $this->entityManager()->persist($account);
        $this->entityManager()->flush();

        return $account;
    }

    private function createAccess(Workspace $workspace, Subscriber $subscriber, Account $account): SubscriberAccountAccess
    {
        $access = new SubscriberAccountAccess($workspace, $subscriber, $account);

        $this->entityManager()->persist($access);
        $this->entityManager()->flush();

        return $access;
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

    private function findSubscriberByUuid(Uuid $uuid): ?Subscriber
    {
        return $this->entityManager()
            ->getRepository(Subscriber::class)
            ->find($uuid);
    }

    private function findUserByEmail(string $email): ?User
    {
        $identity = $this->entityManager()
            ->getRepository(UserEmailIdentity::class)
            ->findOneActiveByEmailNormalized(UserEmailIdentity::normalizeEmail($email));

        return $identity?->getUser();
    }

    private function findSubscriberByName(string $lastName, string $firstName): ?Subscriber
    {
        return $this->entityManager()
            ->getRepository(Subscriber::class)
            ->findOneBy([
                'lastName' => $lastName,
                'firstName' => $firstName,
            ]);
    }

    private function countSubscribers(): int
    {
        return (int) $this->entityManager()
            ->getRepository(Subscriber::class)
            ->createQueryBuilder('subscriber')
            ->select('COUNT(subscriber.uuid)')
            ->getQuery()
            ->getSingleScalarResult();
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

    private function countAuditLogs(string $action): int
    {
        return (int) $this->entityManager()
            ->getConnection()
            ->fetchOne('SELECT COUNT(*) FROM audit_logs WHERE action = ?', [$action]);
    }

    private function findActiveAccess(Subscriber $subscriber, Account $account): ?SubscriberAccountAccess
    {
        return $this->entityManager()
            ->getRepository(SubscriberAccountAccess::class)
            ->findOneBy([
                'subscriber' => $subscriber,
                'account' => $account,
                'revokedAt' => null,
            ]);
    }

    private function findAnyAccess(Subscriber $subscriber, Account $account): ?SubscriberAccountAccess
    {
        return $this->entityManager()
            ->getRepository(SubscriberAccountAccess::class)
            ->findOneBy([
                'subscriber' => $subscriber,
                'account' => $account,
            ]);
    }

    private function countActiveAccesses(Subscriber $subscriber, Account $account): int
    {
        return (int) $this->entityManager()
            ->getRepository(SubscriberAccountAccess::class)
            ->createQueryBuilder('access')
            ->select('COUNT(access.grantedAt)')
            ->andWhere('access.subscriber = :subscriber')
            ->andWhere('access.account = :account')
            ->andWhere('access.revokedAt IS NULL')
            ->setParameter('subscriber', $subscriber)
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
