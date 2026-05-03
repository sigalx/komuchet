<?php

namespace App\Controller;

use App\Entity\Subscriber;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Repository\SubscriberRepository;
use App\Repository\WorkspaceUserRoleAssignmentRepository;
use App\Service\WorkspaceContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ProfileController extends AbstractController
{
    #[Route('/profile', name: 'app_profile', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function index(
        WorkspaceContext $workspaceContext,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        SubscriberRepository $subscriberRepository,
    ): Response {
        $user = $this->getUser();

        if (!$user instanceof User) {
            return $this->redirectToRoute('app_login');
        }

        return $this->render('profile/index.html.twig', [
            'user' => $user,
            'active_email_identities' => $this->activeEmailIdentities($user),
            'workspace_access_rows' => $this->workspaceAccessRows(
                $user,
                $workspaceContext->getAvailableWorkspaces(),
                $workspaceRoleAssignmentRepository->findActiveByUser($user),
                $subscriberRepository->findActiveByUser($user),
            ),
        ]);
    }

    /**
     * @return list<UserEmailIdentity>
     */
    private function activeEmailIdentities(User $user): array
    {
        $identities = array_values(array_filter(
            $user->getEmailIdentities()->toArray(),
            static fn (UserEmailIdentity $identity): bool => $identity->isActive(),
        ));

        usort(
            $identities,
            static fn (UserEmailIdentity $left, UserEmailIdentity $right): int => $left->getCreatedAt() <=> $right->getCreatedAt(),
        );

        return $identities;
    }

    /**
     * @param list<Workspace> $administrativeWorkspaces
     * @param list<WorkspaceUserRoleAssignment> $roleAssignments
     * @param list<Subscriber> $subscribers
     *
     * @return list<array{workspace: Workspace, role_labels: list<string>, subscriber: ?Subscriber}>
     */
    private function workspaceAccessRows(
        User $user,
        array $administrativeWorkspaces,
        array $roleAssignments,
        array $subscribers,
    ): array {
        $rows = [];

        foreach ($administrativeWorkspaces as $workspace) {
            $rows[$workspace->getUuid()->toRfc4122()] = [
                'workspace' => $workspace,
                'role_labels' => $user->isAdmin() ? ['Глобальный администратор'] : [],
                'subscriber' => null,
            ];
        }

        foreach ($roleAssignments as $assignment) {
            $workspace = $assignment->getWorkspace();

            if (!$workspace instanceof Workspace) {
                continue;
            }

            $workspaceUuid = $workspace->getUuid()->toRfc4122();
            $rows[$workspaceUuid] ??= [
                'workspace' => $workspace,
                'role_labels' => [],
                'subscriber' => null,
            ];

            $rows[$workspaceUuid]['role_labels'][] = $assignment->getRoleCodeEnum()->label();
        }

        foreach ($subscribers as $subscriber) {
            $workspace = $subscriber->getWorkspace();

            if (!$workspace instanceof Workspace) {
                continue;
            }

            $workspaceUuid = $workspace->getUuid()->toRfc4122();
            $rows[$workspaceUuid] ??= [
                'workspace' => $workspace,
                'role_labels' => [],
                'subscriber' => null,
            ];

            $rows[$workspaceUuid]['subscriber'] = $subscriber;
        }

        uasort(
            $rows,
            static fn (array $left, array $right): int => $left['workspace']->getCode() <=> $right['workspace']->getCode(),
        );

        return array_values($rows);
    }
}
