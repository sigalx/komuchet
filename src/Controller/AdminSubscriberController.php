<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\UserPasswordCredential;
use App\Enum\SubscriberAccountAccessRole;
use App\Form\SubscriberAccountAccessGrantType;
use App\Form\SubscriberPortalAccessGrantType;
use App\Form\SubscriberType;
use App\Repository\AccountRepository;
use App\Repository\SubscriberAccountAccessRepository;
use App\Repository\SubscriberRepository;
use App\Repository\UserEmailIdentityRepository;
use App\Pagination\AdminPaginator;
use App\Service\AuditLogger;
use App\Service\UserPasswordManager;
use App\Service\WorkspaceContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/subscribers')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminSubscriberController extends AbstractController
{
    #[Route(name: 'app_admin_subscriber_index', methods: ['GET'])]
    public function index(Request $request, SubscriberRepository $subscriberRepository, WorkspaceContext $workspaceContext, AdminPaginator $paginator): Response
    {
        $search = trim($request->query->getString('q'));
        $portalFilter = $subscriberRepository->normalizePortalFilter($request->query->getString('portal', SubscriberRepository::PORTAL_FILTER_ALL));
        $accountFilter = $subscriberRepository->normalizeAccountFilter($request->query->getString('accounts', SubscriberRepository::ACCOUNT_FILTER_ALL));
        $sort = $subscriberRepository->normalizeSort($request->query->getString('sort', SubscriberRepository::SORT_FULL_NAME));
        $direction = $subscriberRepository->normalizeSortDirection($request->query->getString('dir', SubscriberRepository::SORT_ASC));
        $pagination = $paginator->paginate(
            $subscriberRepository->createActiveByWorkspaceForAdminListQuery($workspaceContext->requireCurrentWorkspace(), $search, $portalFilter, $accountFilter, $sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_subscriber/index.html.twig', [
            'subscribers' => $pagination->getItems(),
            'pagination' => $pagination,
            'filters' => [
                'q' => $search,
                'portal' => $portalFilter,
                'accounts' => $accountFilter,
            ],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_subscriber_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $subscriber = new Subscriber($workspace);
        $subscriber->setCreatedBy($this->getCurrentUser());

        $form = $this->createForm(SubscriberType::class, $subscriber);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($subscriber);
            $auditLogger->record(
                action: 'subscriber.created',
                workspace: $workspace,
                entityTable: 'subscribers',
                entityUuid: $subscriber->getUuid(),
                newValues: $this->subscriberAuditValues($subscriber),
                changedFields: ['last_name', 'first_name', 'second_name', 'contact_email', 'contact_phone', 'notes'],
            );
            $entityManager->flush();

            $this->addFlash('success', sprintf('Абонент %s создан.', $subscriber->getDisplayName()));

            return $this->redirectToRoute('app_admin_subscriber_show', ['uuid' => $subscriber->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_subscriber/new.html.twig', [
            'subscriber' => $subscriber,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}', name: 'app_admin_subscriber_show', methods: ['GET'])]
    public function show(
        string $uuid,
        SubscriberRepository $subscriberRepository,
        SubscriberAccountAccessRepository $accessRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
    ): Response {
        $subscriber = $this->findActiveSubscriber($uuid, $subscriberRepository, $workspaceContext);

        return $this->renderSubscriberShow($subscriber, $accessRepository, $accountRepository, $workspaceContext);
    }

    #[Route('/{uuid}/edit', name: 'app_admin_subscriber_edit', methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request, EntityManagerInterface $entityManager, SubscriberRepository $subscriberRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $subscriber = $this->findActiveSubscriber($uuid, $subscriberRepository, $workspaceContext);
        $oldValues = $this->subscriberAuditValues($subscriber);
        $form = $this->createForm(SubscriberType::class, $subscriber);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $subscriber->touch($this->getCurrentUser());
            $auditLogger->record(
                action: 'subscriber.updated',
                workspace: $subscriber->getWorkspace(),
                entityTable: 'subscribers',
                entityUuid: $subscriber->getUuid(),
                oldValues: $oldValues,
                newValues: $this->subscriberAuditValues($subscriber),
                changedFields: ['last_name', 'first_name', 'second_name', 'contact_email', 'contact_phone', 'notes'],
            );
            $entityManager->flush();

            $this->addFlash('success', sprintf('Абонент %s сохранен.', $subscriber->getDisplayName()));

            return $this->redirectToRoute('app_admin_subscriber_show', ['uuid' => $subscriber->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_subscriber/edit.html.twig', [
            'subscriber' => $subscriber,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}/delete', name: 'app_admin_subscriber_delete', methods: ['POST'])]
    public function delete(string $uuid, Request $request, EntityManagerInterface $entityManager, SubscriberRepository $subscriberRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $subscriber = $this->findActiveSubscriber($uuid, $subscriberRepository, $workspaceContext);
        $oldValues = $this->subscriberAuditValues($subscriber);

        if ($this->isCsrfTokenValid('delete'.$subscriber->getUuid(), $request->getPayload()->getString('_token'))) {
            $subscriber->delete($this->getCurrentUser());
            $auditLogger->record(
                action: 'subscriber.deleted',
                workspace: $subscriber->getWorkspace(),
                entityTable: 'subscribers',
                entityUuid: $subscriber->getUuid(),
                oldValues: $oldValues,
                newValues: $this->subscriberAuditValues($subscriber),
                changedFields: ['deleted_at', 'deleted_by'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Абонент %s удален.', $subscriber->getDisplayName()));
        }

        return $this->redirectToRoute('app_admin_subscriber_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/accesses/grant', name: 'app_admin_subscriber_access_grant', methods: ['POST'])]
    public function grantAccess(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        SubscriberRepository $subscriberRepository,
        SubscriberAccountAccessRepository $accessRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $subscriber = $this->findActiveSubscriber($uuid, $subscriberRepository, $workspaceContext);
        $form = $this->createAccessGrantForm($subscriber, $accessRepository, $accountRepository, $workspaceContext);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $account = $form->get('account')->getData();
            $accessRole = $form->get('accessRole')->getData();

            if (!$account instanceof Account) {
                $form->get('account')->addError(new FormError('Выберите участок.'));

                return $this->renderSubscriberShow($subscriber, $accessRepository, $accountRepository, $workspaceContext, accessGrantForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (!$accessRole instanceof SubscriberAccountAccessRole) {
                $form->get('accessRole')->addError(new FormError('Выберите роль доступа.'));

                return $this->renderSubscriberShow($subscriber, $accessRepository, $accountRepository, $workspaceContext, accessGrantForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($accessRepository->findOneActiveBySubscriberAndAccount($workspace, $subscriber, $account) instanceof SubscriberAccountAccess) {
                $form->get('account')->addError(new FormError('У абонента уже есть активный доступ к этому участку.'));

                return $this->renderSubscriberShow($subscriber, $accessRepository, $accountRepository, $workspaceContext, accessGrantForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $access = (new SubscriberAccountAccess($workspace, $subscriber, $account, $accessRole, $this->getCurrentUser()))
                ->setNotes($form->get('notes')->getData());

            $entityManager->persist($access);
            $auditLogger->record(
                action: 'subscriber_account_access.granted',
                workspace: $workspace,
                entityTable: 'subscriber_account_accesses',
                entityPk: $this->subscriberAccountAccessAuditPk($access),
                newValues: $this->subscriberAccountAccessAuditValues($access),
                changedFields: ['subscriber_uuid', 'account_uuid', 'access_role', 'granted_at', 'notes'],
                reason: 'Доступ выдан через карточку абонента.',
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Доступ к участку %s выдан.', $account->getNumber()));

            return $this->redirectToRoute('app_admin_subscriber_show', ['uuid' => $subscriber->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderSubscriberShow($subscriber, $accessRepository, $accountRepository, $workspaceContext, accessGrantForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{uuid}/accesses/{accountUuid}/revoke', name: 'app_admin_subscriber_access_revoke', methods: ['POST'])]
    public function revokeAccess(
        string $uuid,
        string $accountUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        SubscriberRepository $subscriberRepository,
        SubscriberAccountAccessRepository $accessRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $subscriber = $this->findActiveSubscriber($uuid, $subscriberRepository, $workspaceContext);
        $account = $this->findActiveAccount($accountUuid, $accountRepository, $workspaceContext);
        $access = $accessRepository->findOneActiveBySubscriberAndAccount($workspace, $subscriber, $account);

        if (!$access instanceof SubscriberAccountAccess) {
            throw new NotFoundHttpException('Subscriber account access was not found.');
        }

        if ($this->isCsrfTokenValid('revoke_access'.$subscriber->getUuid().$account->getUuid(), $request->getPayload()->getString('_token'))) {
            $oldValues = $this->subscriberAccountAccessAuditValues($access);
            $access->revoke('Доступ отозван администратором через админку.', $this->getCurrentUser());
            $auditLogger->record(
                action: 'subscriber_account_access.revoked',
                workspace: $workspace,
                entityTable: 'subscriber_account_accesses',
                entityPk: $this->subscriberAccountAccessAuditPk($access),
                oldValues: $oldValues,
                newValues: $this->subscriberAccountAccessAuditValues($access),
                changedFields: ['revoked_at', 'revoked_by', 'revoked_reason'],
                reason: $access->getRevokedReason(),
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Доступ к участку %s отозван.', $account->getNumber()));
        }

        return $this->redirectToRoute('app_admin_subscriber_show', ['uuid' => $subscriber->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/user/unlink', name: 'app_admin_subscriber_user_unlink', methods: ['POST'])]
    public function unlinkUser(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        SubscriberRepository $subscriberRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $subscriber = $this->findActiveSubscriber($uuid, $subscriberRepository, $workspaceContext);

        if (!$this->isCsrfTokenValid('unlink_subscriber_user'.$subscriber->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$subscriber->getUser() instanceof User) {
            $this->addFlash('warning', 'У абонента нет связанного пользователя.');

            return $this->redirectToRoute('app_admin_subscriber_show', ['uuid' => $subscriber->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $oldValues = $this->subscriberAuditValues($subscriber);
        $subscriber->setUser(null);
        $subscriber->touch($this->getCurrentUser());
        $auditLogger->record(
            action: 'subscriber.user_unlinked',
            workspace: $subscriber->getWorkspace(),
            entityTable: 'subscribers',
            entityUuid: $subscriber->getUuid(),
            oldValues: $oldValues,
            newValues: $this->subscriberAuditValues($subscriber),
            changedFields: ['user_uuid'],
            reason: 'Связь с пользователем удалена из карточки абонента.',
        );
        $entityManager->flush();
        $this->addFlash('success', 'Связь с пользователем удалена.');

        return $this->redirectToRoute('app_admin_subscriber_show', ['uuid' => $subscriber->getUuid()], Response::HTTP_SEE_OTHER);
    }

    private function findActiveSubscriber(string $uuid, SubscriberRepository $subscriberRepository, WorkspaceContext $workspaceContext): Subscriber
    {
        try {
            $subscriberUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Subscriber was not found.');
        }

        $subscriber = $subscriberRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $subscriberUuid,
        );

        if (!$subscriber instanceof Subscriber) {
            throw new NotFoundHttpException('Subscriber was not found.');
        }

        return $subscriber;
    }

    private function findActiveAccount(string $uuid, AccountRepository $accountRepository, WorkspaceContext $workspaceContext): Account
    {
        try {
            $accountUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Account was not found.');
        }

        $account = $accountRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $accountUuid,
        );

        if (!$account instanceof Account) {
            throw new NotFoundHttpException('Account was not found.');
        }

        return $account;
    }

    private function renderSubscriberShow(
        Subscriber $subscriber,
        SubscriberAccountAccessRepository $accessRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
        ?FormInterface $accessGrantForm = null,
        ?FormInterface $portalAccessGrantForm = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $activeAccesses = $accessRepository->findActiveBySubscriber($workspace, $subscriber);
        $accessGrantForm ??= $this->createAccessGrantForm($subscriber, $accessRepository, $accountRepository, $workspaceContext);
        $portalAccessGrantForm = $subscriber->getUser() instanceof User
            ? null
            : ($portalAccessGrantForm ?? $this->createPortalAccessGrantForm($subscriber));

        return $this->render('admin_subscriber/show.html.twig', [
            'subscriber' => $subscriber,
            'active_accesses' => $activeAccesses,
            'access_grant_form' => $accessGrantForm->createView(),
            'portal_access_grant_form' => $portalAccessGrantForm?->createView(),
        ], new Response(status: $status));
    }

    #[Route('/{uuid}/portal-access/grant', name: 'app_admin_subscriber_portal_access_grant', methods: ['POST'])]
    public function grantPortalAccess(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        SubscriberRepository $subscriberRepository,
        SubscriberAccountAccessRepository $accessRepository,
        AccountRepository $accountRepository,
        UserEmailIdentityRepository $emailIdentityRepository,
        UserPasswordManager $passwordManager,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $subscriber = $this->findActiveSubscriber($uuid, $subscriberRepository, $workspaceContext);

        if ($subscriber->getUser() instanceof User) {
            $this->addFlash('warning', 'Абонент уже связан с пользователем.');

            return $this->redirectToRoute('app_admin_subscriber_show', ['uuid' => $subscriber->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $form = $this->createPortalAccessGrantForm($subscriber);
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->redirectToRoute('app_admin_subscriber_show', ['uuid' => $subscriber->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $email = trim((string) $form->get('email')->getData());
        $emailNormalized = UserEmailIdentity::normalizeEmail($email);
        $identity = $emailIdentityRepository->findOneActiveByEmailNormalized($emailNormalized);
        $user = $identity?->getUser();

        if ($user instanceof User) {
            if ($user->getDeletedAt() !== null) {
                $form->get('email')->addError(new FormError('Пользователь с этим email удален.'));

                return $this->renderSubscriberShow($subscriber, $accessRepository, $accountRepository, $workspaceContext, portalAccessGrantForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($user->getBlockedAt() !== null) {
                $form->get('email')->addError(new FormError('Пользователь с этим email заблокирован.'));

                return $this->renderSubscriberShow($subscriber, $accessRepository, $accountRepository, $workspaceContext, portalAccessGrantForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $linkedSubscriber = $subscriberRepository->findOneActiveByWorkspaceAndUser($workspace, $user);

            if ($linkedSubscriber instanceof Subscriber) {
                $form->get('email')->addError(new FormError(sprintf('Пользователь уже связан с абонентом %s.', $linkedSubscriber->getDisplayName())));

                return $this->renderSubscriberShow($subscriber, $accessRepository, $accountRepository, $workspaceContext, portalAccessGrantForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        if (!$form->isValid()) {
            return $this->renderSubscriberShow($subscriber, $accessRepository, $accountRepository, $workspaceContext, portalAccessGrantForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $currentUser = $this->getCurrentUser();
        $createdUser = false;
        $temporaryPassword = null;

        if (!$user instanceof User) {
            $createdUser = true;
            $temporaryPassword = $passwordManager->generateTemporaryPassword();
            $user = new User();
            $user->setCreatedBy($currentUser);
            $user->approve($currentUser);

            $identity = new UserEmailIdentity($user, $email);
            $identity->markVerified();
            $identity->setCreatedBy($currentUser);
            $user->addEmailIdentity($identity);

            $entityManager->persist($user);
            $entityManager->persist($identity);
        } elseif (!($identity?->getVerifiedAt() instanceof DateTimeImmutable)) {
            $identity?->markVerified();
        }

        if ($user->getApprovedAt() === null) {
            $user->approve($currentUser);
        }

        if (!$user->getPasswordCredential() instanceof UserPasswordCredential) {
            $temporaryPassword ??= $passwordManager->generateTemporaryPassword();
        }

        $subscriber->setUser($user);
        $subscriber->touch($currentUser);

        $auditLogger->record(
            action: $createdUser ? 'subscriber.portal_access.created_user' : 'subscriber.portal_access.granted',
            workspace: $workspace,
            entityTable: 'subscribers',
            entityUuid: $subscriber->getUuid(),
            oldValues: ['user_uuid' => null],
            newValues: [
                'user_uuid' => $user->getUuid()->toRfc4122(),
                'email_normalized' => $emailNormalized,
                'created_user' => $createdUser,
                'temporary_password_issued' => $temporaryPassword !== null,
            ],
            changedFields: ['user_uuid'],
            reason: 'Выдача доступа к абонентскому порталу из админки.',
        );

        if ($temporaryPassword !== null) {
            $passwordManager->setPassword(
                $user,
                $temporaryPassword,
                $currentUser,
                new DateTimeImmutable(UserPasswordManager::TEMPORARY_PASSWORD_EXPIRES_AT),
            );
        } else {
            $entityManager->flush();
        }

        if ($temporaryPassword !== null) {
            $this->addFlash('warning', sprintf(
                'Доступ выдан. Email: %s. Временный пароль: %s',
                $email,
                $temporaryPassword,
            ));
        } else {
            $this->addFlash('success', sprintf('Доступ к порталу выдан пользователю %s.', $email));
        }

        return $this->redirectToRoute('app_admin_subscriber_show', ['uuid' => $subscriber->getUuid()], Response::HTTP_SEE_OTHER);
    }

    private function createAccessGrantForm(
        Subscriber $subscriber,
        SubscriberAccountAccessRepository $accessRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
    ): FormInterface {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $activeAccesses = $accessRepository->findActiveBySubscriber($workspace, $subscriber);
        $activeAccountUuids = [];

        foreach ($activeAccesses as $access) {
            $account = $access->getAccount();

            if ($account instanceof Account) {
                $activeAccountUuids[$account->getUuid()->toRfc4122()] = true;
            }
        }

        $availableAccounts = array_values(array_filter(
            $accountRepository->findActiveByWorkspace($workspace),
            static fn (Account $account): bool => !isset($activeAccountUuids[$account->getUuid()->toRfc4122()]),
        ));

        return $this->createForm(SubscriberAccountAccessGrantType::class, null, [
            'active_accounts' => $availableAccounts,
            'action' => $this->generateUrl('app_admin_subscriber_access_grant', ['uuid' => $subscriber->getUuid()]),
        ]);
    }

    private function createPortalAccessGrantForm(Subscriber $subscriber): FormInterface
    {
        return $this->createForm(SubscriberPortalAccessGrantType::class, [
            'email' => $subscriber->getContactEmail(),
        ], [
            'action' => $this->generateUrl('app_admin_subscriber_portal_access_grant', ['uuid' => $subscriber->getUuid()]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriberAuditValues(Subscriber $subscriber): array
    {
        return [
            'user_uuid' => $subscriber->getUser()?->getUuid()->toRfc4122(),
            'last_name' => $subscriber->getLastName(),
            'first_name' => $subscriber->getFirstName(),
            'second_name' => $subscriber->getSecondName(),
            'display_name' => $subscriber->getDisplayName(),
            'contact_email' => $subscriber->getContactEmail(),
            'contact_phone' => $subscriber->getContactPhone(),
            'notes' => $subscriber->getNotes(),
            'deleted_at' => $subscriber->getDeletedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function subscriberAccountAccessAuditPk(SubscriberAccountAccess $access): array
    {
        return [
            'workspace_uuid' => $access->getWorkspace()?->getUuid()->toRfc4122(),
            'subscriber_uuid' => $access->getSubscriber()?->getUuid()->toRfc4122(),
            'account_uuid' => $access->getAccount()?->getUuid()->toRfc4122(),
            'granted_at' => $access->getGrantedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriberAccountAccessAuditValues(SubscriberAccountAccess $access): array
    {
        return [
            ...$this->subscriberAccountAccessAuditPk($access),
            'subscriber_name' => $access->getSubscriber()?->getDisplayName(),
            'account_number' => $access->getAccount()?->getNumber(),
            'access_role' => $access->getAccessRole()->value,
            'notes' => $access->getNotes(),
            'revoked_at' => $access->getRevokedAt()?->format(DATE_ATOM),
            'revoked_reason' => $access->getRevokedReason(),
        ];
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
