<?php

namespace App\Security;

use App\Entity\User;
use App\Enum\WorkspaceUserRoleCode;
use App\Repository\WorkspaceUserRoleAssignmentRepository;
use App\Service\WorkspaceContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class WorkspaceAccessVoter extends Voter
{
    public const ACCESS = 'WORKSPACE_ACCESS';
    public const ADMIN = 'WORKSPACE_ADMIN';

    public function __construct(
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::ACCESS, self::ADMIN], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $workspace = $this->workspaceContext->getCurrentWorkspace();

        if ($workspace === null) {
            return false;
        }

        return match ($attribute) {
            self::ACCESS => $this->workspaceRoleAssignmentRepository->hasActiveRole($workspace, $user, [
                WorkspaceUserRoleCode::Admin,
                WorkspaceUserRoleCode::Operator,
            ]),
            self::ADMIN => $this->workspaceRoleAssignmentRepository->hasActiveRole($workspace, $user, [
                WorkspaceUserRoleCode::Admin,
            ]),
            default => false,
        };
    }
}
