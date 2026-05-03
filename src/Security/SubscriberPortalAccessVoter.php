<?php

namespace App\Security;

use App\Entity\Account;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\Workspace;
use App\Repository\SubscriberAccountAccessRepository;
use App\Service\SubscriberPortalContext;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class SubscriberPortalAccessVoter extends Voter
{
    public const PORTAL_ACCESS = 'SUBSCRIBER_PORTAL_ACCESS';
    public const ACCOUNT_VIEW = 'SUBSCRIBER_ACCOUNT_VIEW';
    public const ACCOUNT_READING_SUBMIT = 'SUBSCRIBER_ACCOUNT_READING_SUBMIT';

    public function __construct(
        private readonly SubscriberPortalContext $subscriberPortalContext,
        private readonly SubscriberAccountAccessRepository $accessRepository,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if ($attribute === self::PORTAL_ACCESS) {
            return true;
        }

        if (in_array($attribute, [self::ACCOUNT_VIEW, self::ACCOUNT_READING_SUBMIT], true)) {
            return $subject instanceof Account;
        }

        return false;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token->getUser() instanceof User) {
            return false;
        }

        $subscriber = $this->subscriberPortalContext->getCurrentSubscriber();
        $workspace = $this->subscriberPortalContext->getCurrentWorkspace();

        if ($subscriber === null || !$workspace instanceof Workspace) {
            return false;
        }

        if ($attribute === self::PORTAL_ACCESS) {
            return true;
        }

        if (!$subject instanceof Account || !$subject->isActive()) {
            return false;
        }

        if (!$subject->getWorkspace()?->getUuid()->equals($workspace->getUuid())) {
            return false;
        }

        return $this->accessRepository->findOneActiveBySubscriberAndAccount($workspace, $subscriber, $subject) instanceof SubscriberAccountAccess;
    }
}
