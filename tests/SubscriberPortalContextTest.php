<?php

namespace App\Tests;

use App\Entity\Subscriber;
use App\Entity\User;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\WorkspaceUserRoleCode;
use App\Repository\SubscriberRepository;
use App\Service\SubscriberPortalContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class SubscriberPortalContextTest extends FunctionalWebTestCase
{
    private const PORTAL_SESSION_KEY = '_snt_current_portal_workspace_uuid';
    private const ADMIN_SESSION_KEY = '_snt_current_workspace_uuid';

    public function testAnonymousUserHasNoSubscriberPortalContext(): void
    {
        static::createClient();
        $this->resetDatabase();

        $context = $this->createSubscriberPortalContext();

        self::assertSame([], $context->getAvailableSubscribers());
        self::assertSame([], $context->getAvailableWorkspaces());
        self::assertNull($context->getCurrentSubscriber());
        self::assertNull($context->getCurrentWorkspace());
    }

    public function testCurrentWorkspaceDefaultsToFirstSubscriberWorkspaceAndCanBeSwitched(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $betaWorkspace = $this->createWorkspace('beta', 'Бета');
        $alphaWorkspace = $this->createWorkspace('alpha', 'Альфа');
        $betaSubscriber = $this->createSubscriber($betaWorkspace, $user, 'Бета');
        $alphaSubscriber = $this->createSubscriber($alphaWorkspace, $user, 'Альфа');
        $session = $this->createSession();
        $context = $this->createSubscriberPortalContext($user, $session);

        self::assertSame([$alphaSubscriber, $betaSubscriber], $context->getAvailableSubscribers());
        self::assertSame([$alphaWorkspace, $betaWorkspace], $context->getAvailableWorkspaces());
        self::assertSame($alphaSubscriber, $context->getCurrentSubscriber());
        self::assertSame($alphaWorkspace, $context->getCurrentWorkspace());
        self::assertSame($alphaWorkspace->getUuid()->toRfc4122(), $session->get(self::PORTAL_SESSION_KEY));

        $context->switchCurrentWorkspace($betaWorkspace);

        self::assertSame($betaSubscriber, $context->getCurrentSubscriber());
        self::assertSame($betaWorkspace, $context->getCurrentWorkspace());
        self::assertSame($betaWorkspace->getUuid()->toRfc4122(), $session->get(self::PORTAL_SESSION_KEY));
    }

    public function testPortalContextUsesSeparateSessionKeyFromAdminContext(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $portalWorkspace = $this->createWorkspace('portal', 'Абонентское хозяйство');
        $adminWorkspace = $this->createWorkspace('admin', 'Админское хозяйство');
        $subscriber = $this->createSubscriber($portalWorkspace, $user, 'Абонент');
        $session = $this->createSession();
        $session->set(self::ADMIN_SESSION_KEY, $adminWorkspace->getUuid()->toRfc4122());
        $context = $this->createSubscriberPortalContext($user, $session);

        self::assertSame($subscriber, $context->getCurrentSubscriber());
        self::assertSame($portalWorkspace, $context->getCurrentWorkspace());
        self::assertSame($adminWorkspace->getUuid()->toRfc4122(), $session->get(self::ADMIN_SESSION_KEY));
        self::assertSame($portalWorkspace->getUuid()->toRfc4122(), $session->get(self::PORTAL_SESSION_KEY));
    }

    public function testInvalidOrUnavailableStoredWorkspaceFallsBackToFirstAvailableWorkspace(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $availableWorkspace = $this->createWorkspace('available', 'Доступное хозяйство');
        $unavailableWorkspace = $this->createWorkspace('unavailable', 'Недоступное хозяйство');
        $subscriber = $this->createSubscriber($availableWorkspace, $user, 'Абонент');
        $session = $this->createSession();
        $session->set(self::PORTAL_SESSION_KEY, 'not-a-uuid');

        $context = $this->createSubscriberPortalContext($user, $session);

        self::assertSame($subscriber, $context->getCurrentSubscriber());
        self::assertSame($availableWorkspace->getUuid()->toRfc4122(), $session->get(self::PORTAL_SESSION_KEY));

        $session->set(self::PORTAL_SESSION_KEY, $unavailableWorkspace->getUuid()->toRfc4122());
        $context = $this->createSubscriberPortalContext($user, $session);

        self::assertSame($subscriber, $context->getCurrentSubscriber());
        self::assertSame($availableWorkspace->getUuid()->toRfc4122(), $session->get(self::PORTAL_SESSION_KEY));
    }

    public function testAdminWorkspaceRoleDoesNotGrantSubscriberPortalWorkspaceAccess(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $portalWorkspace = $this->createWorkspace('portal', 'Абонентское хозяйство');
        $adminWorkspace = $this->createWorkspace('admin', 'Админское хозяйство');
        $this->createSubscriber($portalWorkspace, $user, 'Абонент');
        $this->createWorkspaceRoleAssignment($adminWorkspace, $user, WorkspaceUserRoleCode::Admin);
        $context = $this->createSubscriberPortalContext($user);

        self::assertSame([$portalWorkspace], $context->getAvailableWorkspaces());
        self::assertTrue($context->isWorkspaceAvailable($portalWorkspace));
        self::assertFalse($context->isWorkspaceAvailable($adminWorkspace));
    }

    public function testSwitchingToUnavailableWorkspaceFails(): void
    {
        static::createClient();
        $this->resetDatabase();
        $user = $this->createUser();
        $portalWorkspace = $this->createWorkspace('portal', 'Абонентское хозяйство');
        $unavailableWorkspace = $this->createWorkspace('unavailable', 'Недоступное хозяйство');
        $this->createSubscriber($portalWorkspace, $user, 'Абонент');
        $context = $this->createSubscriberPortalContext($user);

        $this->expectException(\InvalidArgumentException::class);

        $context->switchCurrentWorkspace($unavailableWorkspace);
    }

    private function createSubscriberPortalContext(?User $user = null, ?Session $session = null): SubscriberPortalContext
    {
        $requestStack = new RequestStack();
        $request = Request::create('/portal');

        if ($session instanceof Session) {
            $request->setSession($session);
        }

        $requestStack->push($request);

        $tokenStorage = new TokenStorage();

        if ($user instanceof User) {
            $tokenStorage->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
        }

        return new SubscriberPortalContext(
            static::getContainer()->get(SubscriberRepository::class),
            $requestStack,
            $tokenStorage,
        );
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

    private function createWorkspaceRoleAssignment(Workspace $workspace, User $user, WorkspaceUserRoleCode $roleCode): WorkspaceUserRoleAssignment
    {
        $assignment = new WorkspaceUserRoleAssignment($workspace, $user, $roleCode);

        $this->entityManager()->persist($assignment);
        $this->entityManager()->flush();

        return $assignment;
    }
}
