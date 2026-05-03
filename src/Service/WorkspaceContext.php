<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\WorkspaceUserRoleCode;
use App\Repository\WorkspaceRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Uuid;

class WorkspaceContext
{
    private const SESSION_KEY = '_snt_current_workspace_uuid';

    /**
     * @var list<Workspace>|null
     */
    private ?array $availableWorkspaces = null;

    private ?Workspace $currentWorkspace = null;

    public function __construct(
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly RequestStack $requestStack,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function getCurrentWorkspace(): ?Workspace
    {
        if ($this->currentWorkspace instanceof Workspace) {
            return $this->currentWorkspace;
        }

        $availableWorkspaces = $this->getAvailableWorkspaces();

        if ($availableWorkspaces === []) {
            return null;
        }

        $selectedWorkspaceUuid = $this->getSelectedWorkspaceUuid();

        if ($selectedWorkspaceUuid instanceof Uuid) {
            foreach ($availableWorkspaces as $workspace) {
                if ($workspace->getUuid()->equals($selectedWorkspaceUuid)) {
                    return $this->currentWorkspace = $workspace;
                }
            }

            $this->clearSelectedWorkspace();
        }

        $this->currentWorkspace = $availableWorkspaces[0];
        $this->storeSelectedWorkspace($this->currentWorkspace);

        return $this->currentWorkspace;
    }

    public function requireCurrentWorkspace(): Workspace
    {
        $workspace = $this->getCurrentWorkspace();

        if (!$workspace instanceof Workspace) {
            throw new NotFoundHttpException('Workspace is not configured.');
        }

        return $workspace;
    }

    /**
     * @return list<Workspace>
     */
    public function getAvailableWorkspaces(): array
    {
        if ($this->availableWorkspaces !== null) {
            return $this->availableWorkspaces;
        }

        $user = $this->getCurrentUser();

        if (!$user instanceof User) {
            return $this->availableWorkspaces = [];
        }

        if ($user->isAdmin()) {
            return $this->availableWorkspaces = $this->workspaceRepository->findAllOrderedByCode();
        }

        return $this->availableWorkspaces = $this->workspaceRepository->findAccessibleByUser($user, [
            WorkspaceUserRoleCode::Admin,
            WorkspaceUserRoleCode::Operator,
        ]);
    }

    public function switchCurrentWorkspace(Workspace $workspace): void
    {
        if (!$this->isWorkspaceAvailable($workspace)) {
            throw new \InvalidArgumentException('Workspace is not available for current user.');
        }

        $this->currentWorkspace = $workspace;
        $this->storeSelectedWorkspace($workspace);
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
