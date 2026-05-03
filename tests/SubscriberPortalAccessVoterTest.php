<?php

namespace App\Tests;

use App\Entity\Account;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\SubscriberAccountAccessRole;
use App\Repository\SubscriberAccountAccessRepository;
use App\Repository\SubscriberRepository;
use App\Security\SubscriberPortalAccessVoter;
use App\Service\SubscriberPortalContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class SubscriberPortalAccessVoterTest extends FunctionalWebTestCase
{
    private const PORTAL_SESSION_KEY = '_snt_current_portal_workspace_uuid';

    public function testAnonymousUserIsDenied(): void
    {
        static::createClient();
        $this->resetDatabase();
        $workspace = $this->createWorkspace();
        $account = $this->createAccount($workspace, '9-123');
        $tokenStorage = new TokenStorage();
        $voter = $this->createVoter($tokenStorage);
        $token = new NullToken();

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, [SubscriberPortalAccessVoter::PORTAL_ACCESS]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, $account, [SubscriberPortalAccessVoter::ACCOUNT_VIEW]));
    }

    public function testPortalAccessIsGrantedForLinkedSubscriber(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace();
        $this->createSubscriber($workspace, $user, 'Абонент');
        $tokenStorage = $this->createTokenStorage($user);
        $voter = $this->createVoter($tokenStorage);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($tokenStorage->getToken(), null, [SubscriberPortalAccessVoter::PORTAL_ACCESS]),
        );
    }

    public function testPortalAccessIsDeniedWithoutLinkedSubscriber(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $tokenStorage = $this->createTokenStorage($user);
        $voter = $this->createVoter($tokenStorage);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($tokenStorage->getToken(), null, [SubscriberPortalAccessVoter::PORTAL_ACCESS]),
        );
    }

    public function testAccountAccessIsGrantedForActiveSubscriberAccountAccess(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace();
        $subscriber = $this->createSubscriber($workspace, $user, 'Абонент');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $tokenStorage = $this->createTokenStorage($user);
        $voter = $this->createVoter($tokenStorage);

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($tokenStorage->getToken(), $account, [SubscriberPortalAccessVoter::ACCOUNT_VIEW]),
        );
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($tokenStorage->getToken(), $account, [SubscriberPortalAccessVoter::ACCOUNT_READING_SUBMIT]),
        );
    }

    public function testAccountAccessIsDeniedWithoutActiveAccess(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace();
        $subscriber = $this->createSubscriber($workspace, $user, 'Абонент');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account)->revoke('Ошибка привязки');
        $this->entityManager()->flush();
        $tokenStorage = $this->createTokenStorage($user);
        $voter = $this->createVoter($tokenStorage);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($tokenStorage->getToken(), $account, [SubscriberPortalAccessVoter::ACCOUNT_VIEW]),
        );
    }

    public function testAccountAccessIsDeniedForDeletedAccount(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $workspace = $this->createWorkspace();
        $subscriber = $this->createSubscriber($workspace, $user, 'Абонент');
        $account = $this->createAccount($workspace, '9-123');
        $this->createAccess($workspace, $subscriber, $account);
        $account->delete();
        $this->entityManager()->flush();
        $tokenStorage = $this->createTokenStorage($user);
        $voter = $this->createVoter($tokenStorage);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($tokenStorage->getToken(), $account, [SubscriberPortalAccessVoter::ACCOUNT_VIEW]),
        );
    }

    public function testAccountAccessIsDeniedOutsideCurrentPortalWorkspace(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $portalWorkspace = $this->createWorkspace('portal', 'Абонентское хозяйство');
        $otherWorkspace = $this->createWorkspace('other', 'Другое хозяйство');
        $this->createSubscriber($portalWorkspace, $user, 'Абонент');
        $otherAccount = $this->createAccount($otherWorkspace, '10-456');
        $session = $this->createSession();
        $session->set(self::PORTAL_SESSION_KEY, $portalWorkspace->getUuid()->toRfc4122());
        $tokenStorage = $this->createTokenStorage($user);
        $voter = $this->createVoter($tokenStorage, $session);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($tokenStorage->getToken(), $otherAccount, [SubscriberPortalAccessVoter::ACCOUNT_VIEW]),
        );
    }

    private function createVoter(TokenStorage $tokenStorage, ?Session $session = null): SubscriberPortalAccessVoter
    {
        $requestStack = new RequestStack();
        $request = Request::create('/portal');
        $request->setSession($session ?? $this->createSession());
        $requestStack->push($request);

        return new SubscriberPortalAccessVoter(
            new SubscriberPortalContext(
                static::getContainer()->get(SubscriberRepository::class),
                $requestStack,
                $tokenStorage,
            ),
            static::getContainer()->get(SubscriberAccountAccessRepository::class),
        );
    }

    private function createTokenStorage(User $user): TokenStorage
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        return $tokenStorage;
    }

    private function createSession(): Session
    {
        return new Session(new MockArraySessionStorage());
    }

    private function createUser(): User
    {
        $user = (new User())->approve();

        $this->entityManager()->persist($user);
        $this->entityManager()->flush();

        return $user;
    }

    private function createSubscriber(Workspace $workspace, User $user, string $name): Subscriber
    {
        $subscriber = (new Subscriber($workspace))
            ->setUser($user)
            ->setLastName($name)
            ->setFirstName('Иван')
            ->setSecondName('Иванович');

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
        $access = new SubscriberAccountAccess($workspace, $subscriber, $account, SubscriberAccountAccessRole::Owner);

        $this->entityManager()->persist($access);
        $this->entityManager()->flush();

        return $access;
    }
}
