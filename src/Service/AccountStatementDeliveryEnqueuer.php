<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementDeliveryAttempt;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Subscriber;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\Workspace;
use App\Enum\AccountStatementDeliveryChannel;
use App\Repository\AccountStatementDeliveryRepository;
use App\Repository\SubscriberAccountAccessRepository;
use App\Repository\UserEmailIdentityRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AccountStatementDeliveryEnqueuer
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SubscriberAccountAccessRepository $accessRepository,
        private UserEmailIdentityRepository $emailIdentityRepository,
        private AccountStatementDeliveryRepository $deliveryRepository,
    ) {
    }

    public function enqueueForActiveAccountSubscribers(
        Workspace $workspace,
        AccountStatementSnapshot $statement,
        ?User $queuedBy = null,
    ): AccountStatementDeliveryEnqueueResult {
        $account = $statement->getAccount();

        if (!$account instanceof Account) {
            throw new \LogicException('Account statement snapshot must be linked to an account before delivery enqueue.');
        }

        $createdDeliveries = [];
        $skippedWithoutEmail = [];
        $skippedExisting = [];
        $seenRecipientEmails = [];

        foreach ($this->accessRepository->findActiveByAccount($workspace, $account) as $access) {
            $subscriber = $access->getSubscriber();

            if (!$subscriber instanceof Subscriber) {
                continue;
            }

            $recipientEmail = $this->resolveRecipientEmail($subscriber);

            if ($recipientEmail === null) {
                $skippedWithoutEmail[] = $subscriber;
                continue;
            }

            $recipientEmailNormalized = UserEmailIdentity::normalizeEmail($recipientEmail);

            if (isset($seenRecipientEmails[$recipientEmailNormalized])) {
                $skippedExisting[] = $subscriber;
                continue;
            }

            if ($this->deliveryRepository->findOneActiveByStatementAndRecipient($workspace, $statement, AccountStatementDeliveryChannel::Email, $recipientEmailNormalized) instanceof AccountStatementDelivery) {
                $skippedExisting[] = $subscriber;
                continue;
            }

            $delivery = new AccountStatementDelivery(
                workspace: $workspace,
                accountStatement: $statement,
                channel: AccountStatementDeliveryChannel::Email,
                recipientEmail: $recipientEmail,
                recipientName: $subscriber->getDisplayName(),
                recipientSubscriber: $subscriber,
                createdBy: $queuedBy,
            );
            $attempt = new AccountStatementDeliveryAttempt($workspace, $delivery, 1, $queuedBy);
            $delivery->addAttempt($attempt);

            $this->entityManager->persist($delivery);
            $this->entityManager->persist($attempt);

            $createdDeliveries[] = $delivery;
            $seenRecipientEmails[$recipientEmailNormalized] = true;
        }

        return new AccountStatementDeliveryEnqueueResult(
            createdDeliveries: $createdDeliveries,
            skippedWithoutEmail: $skippedWithoutEmail,
            skippedExisting: $skippedExisting,
        );
    }

    private function resolveRecipientEmail(Subscriber $subscriber): ?string
    {
        $contactEmail = $this->normalizeValidEmail($subscriber->getContactEmail());

        if ($contactEmail !== null) {
            return $contactEmail;
        }

        $user = $subscriber->getUser();

        if (!$user instanceof User) {
            return null;
        }

        foreach ($this->emailIdentityRepository->findActiveVerifiedByUser($user) as $identity) {
            $email = $this->normalizeValidEmail($identity->getEmail());

            if ($email !== null) {
                return $email;
            }
        }

        return null;
    }

    private function normalizeValidEmail(?string $email): ?string
    {
        $email = $email === null ? null : trim($email);

        if ($email === null || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $email;
    }
}
