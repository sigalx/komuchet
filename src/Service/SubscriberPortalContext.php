<?php

namespace App\Service;

use App\Entity\Subscriber;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\SubscriberRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

class SubscriberPortalContext
{
    private const SESSION_KEY = '_snt_current_portal_workspace_uuid';

    /**
     * @var list<Subscriber>|null
     */
    private ?array $availableSubscribers = null;

    private ?Subscriber $currentSubscriber = null;

    public function __construct(
        private readonly SubscriberRepository $subscriberRepository,
        private readonly RequestStack $requestStack,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    /**
     * @return list<Subscriber>
     */
    public function getAvailableSubscribers(): array
    {
        if ($this->availableSubscribers !== null) {
            return $this->availableSubscribers;
        }

        $user = $this->getCurrentUser();

        if (!$user instanceof User) {
            return $this->availableSubscribers = [];
        }

        return $this->availableSubscribers = $this->subscriberRepository->findActiveByUser($user);
    }

    /**
     * @return list<Workspace>
     */
    public function getAvailableWorkspaces(): array
    {
        $workspaces = [];

        foreach ($this->getAvailableSubscribers() as $subscriber) {
            $workspace = $subscriber->getWorkspace();

            if (!$workspace instanceof Workspace) {
                continue;
            }

            $workspaces[$workspace->getUuid()->toRfc4122()] = $workspace;
        }

        return array_values($workspaces);
    }

    public function getCurrentSubscriber(): ?Subscriber
    {
        if ($this->currentSubscriber instanceof Subscriber) {
            return $this->currentSubscriber;
        }

        $availableSubscribers = $this->getAvailableSubscribers();

        if ($availableSubscribers === []) {
            return null;
        }

        $selectedWorkspaceUuid = $this->getSelectedWorkspaceUuid();

        if ($selectedWorkspaceUuid instanceof Uuid) {
            foreach ($availableSubscribers as $subscriber) {
                $workspace = $subscriber->getWorkspace();

                if ($workspace instanceof Workspace && $workspace->getUuid()->equals($selectedWorkspaceUuid)) {
                    return $this->currentSubscriber = $subscriber;
                }
            }

            $this->clearSelectedWorkspace();
        }

        $this->currentSubscriber = $availableSubscribers[0];
        $workspace = $this->currentSubscriber->getWorkspace();

        if ($workspace instanceof Workspace) {
            $this->storeSelectedWorkspace($workspace);
        }

        return $this->currentSubscriber;
    }

    public function requireCurrentSubscriber(): Subscriber
    {
        $subscriber = $this->getCurrentSubscriber();

        if (!$subscriber instanceof Subscriber) {
            throw new NotFoundHttpException('Subscriber portal is not available for current user.');
        }

        return $subscriber;
    }

    public function getCurrentWorkspace(): ?Workspace
    {
        return $this->getCurrentSubscriber()?->getWorkspace();
    }

    public function requireCurrentWorkspace(): Workspace
    {
        $workspace = $this->getCurrentWorkspace();

        if (!$workspace instanceof Workspace) {
            throw new NotFoundHttpException('Subscriber portal workspace is not configured.');
        }

        return $workspace;
    }

    public function switchCurrentWorkspace(Workspace $workspace): void
    {
        foreach ($this->getAvailableSubscribers() as $subscriber) {
            $availableWorkspace = $subscriber->getWorkspace();

            if ($availableWorkspace instanceof Workspace && $availableWorkspace->getUuid()->equals($workspace->getUuid())) {
                $this->currentSubscriber = $subscriber;
                $this->storeSelectedWorkspace($availableWorkspace);

                return;
            }
        }

        throw new \InvalidArgumentException('Workspace is not available for current subscriber portal user.');
    }

    public function isWorkspaceAvailable(Workspace $workspace): bool
    {
        foreach ($this->getAvailableWorkspaces() as $availableWorkspace) {
            if ($availableWorkspace->getUuid()->equals($workspace->getUuid())) {
                return true;
            }
        }

        return false;
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    private function getSelectedWorkspaceUuid(): ?Uuid
    {
        $session = $this->getSession();
        $workspaceUuid = $session?->get(self::SESSION_KEY);

        if (!is_string($workspaceUuid) || $workspaceUuid === '') {
            return null;
        }

        try {
            return Uuid::fromString($workspaceUuid);
        } catch (\InvalidArgumentException) {
            $this->clearSelectedWorkspace();

            return null;
        }
    }

    private function storeSelectedWorkspace(Workspace $workspace): void
    {
        $this->getSession()?->set(self::SESSION_KEY, $workspace->getUuid()->toRfc4122());
    }

    private function clearSelectedWorkspace(): void
    {
        $this->getSession()?->remove(self::SESSION_KEY);
    }

    private function getSession(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null || !$request->hasSession()) {
            return null;
        }

        return $request->getSession();
    }
}
