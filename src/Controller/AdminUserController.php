<?php

namespace App\Controller;

use App\Entity\Subscriber;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\WorkspaceUserRoleCode;
use App\Form\UserBlockType;
use App\Form\UserCreateType;
use App\Form\UserEmailAddType;
use App\Form\UserPasswordSetType;
use App\Form\UserSubscriberLinkType;
use App\Form\WorkspaceAccessGrantType;
use App\Form\WorkspaceUserRoleGrantType;
use App\Form\WorkspaceUserRoleRevokeType;
use App\Repository\SubscriberRepository;
use App\Repository\UserEmailIdentityRepository;
use App\Repository\UserRepository;
use App\Repository\WorkspaceUserRoleAssignmentRepository;
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

#[Route('/admin/users')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminUserController extends AbstractController
{
    private const STATUS_FILTERS = [
        'pending' => 'Ожидает одобрения',
        'active' => 'Активен',
        'blocked' => 'Заблокирован',
        'deleted' => 'Удален',
    ];

    #[Route(name: 'app_admin_user_index', methods: ['GET'])]
    public function index(
        Request $request,
        UserRepository $userRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        return $this->renderIndex($request, $userRepository, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, $paginator);
    }

    #[Route('/new', name: 'app_admin_user_new', methods: ['GET', 'POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        UserEmailIdentityRepository $emailIdentityRepository,
        UserPasswordManager $passwordManager,
        AuditLogger $auditLogger,
    ): Response {
        $form = $this->createForm(UserCreateType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $email = (string) $form->get('email')->getData();
            $emailNormalized = UserEmailIdentity::normalizeEmail($email);

            if ($emailIdentityRepository->findOneActiveByEmailNormalized($emailNormalized) instanceof UserEmailIdentity) {
                $form->get('email')->addError(new FormError('Активный пользователь с таким email уже существует.'));
            }

            if ($form->isValid()) {
                $currentUser = $this->getCurrentUser();
                $user = new User();
                $user->setCreatedBy($currentUser);

                if ($form->get('approved')->getData() === true) {
                    $user->approve($currentUser);
                }

                $emailIdentity = new UserEmailIdentity($user, $email);
                $emailIdentity->markVerified();
                $emailIdentity->setCreatedBy($currentUser);
                $user->addEmailIdentity($emailIdentity);

                $entityManager->persist($user);
                $entityManager->persist($emailIdentity);
                $passwordManager->setPassword($user, (string) $form->get('plainPassword')->getData(), $currentUser);
                $auditLogger->record(
                    action: 'user.created',
                    entityTable: 'users',
                    entityUuid: $user->getUuid(),
                    newValues: $this->userAuditValues($user),
                    changedFields: ['created_at', 'approved_at', 'primary_email', 'password'],
                    reason: 'Пользователь создан из админки.',
                );
                $auditLogger->record(
                    action: 'user_email_identity.created',
                    entityTable: 'user_email_identities',
                    entityPk: $this->userEmailIdentityAuditPk($emailIdentity),
                    newValues: $this->userEmailIdentityAuditValues($emailIdentity),
                    changedFields: ['email', 'verified_at'],
                    reason: 'Email добавлен при создании пользователя из админки.',
                );
                $auditLogger->record(
                    action: 'user.password_set',
                    entityTable: 'user_password_credentials',
                    entityPk: ['user_uuid' => $user->getUuid()->toRfc4122()],
                    newValues: $this->userPasswordCredentialAuditValues($user),
                    changedFields: ['password_hash', 'expires_at'],
                    reason: 'Пароль установлен при создании пользователя из админки.',
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Пользователь %s создан.', $emailIdentity->getEmail()));

                return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_user/new.html.twig', [
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_user/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/workspace-access/grant', name: 'app_admin_user_workspace_access_grant', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function grantWorkspaceAccess(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserEmailIdentityRepository $emailIdentityRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
        UserPasswordManager $passwordManager,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $form = $this->createWorkspaceAccessGrantForm();
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->redirectToRoute('app_admin_user_index', [], Response::HTTP_SEE_OTHER);
        }

        $email = trim((string) $form->get('email')->getData());
        $emailNormalized = UserEmailIdentity::normalizeEmail($email);
        $roleCode = $form->get('roleCode')->getData();
        $identity = $emailNormalized === '' ? null : $emailIdentityRepository->findOneActiveByEmailNormalized($emailNormalized);
        $user = $identity?->getUser();

        if (!$roleCode instanceof WorkspaceUserRoleCode) {
            $form->get('roleCode')->addError(new FormError('Выберите роль.'));
        }

        if ($user instanceof User) {
            if ($user->getDeletedAt() !== null) {
                $form->get('email')->addError(new FormError('Пользователь с этим email удален.'));
            }

            if ($user->getBlockedAt() !== null) {
                $form->get('email')->addError(new FormError('Пользователь с этим email заблокирован.'));
            }

            if (
                $roleCode instanceof WorkspaceUserRoleCode
                && $workspaceRoleAssignmentRepository->findOneActiveByWorkspaceUserAndRole($workspace, $user, $roleCode) instanceof WorkspaceUserRoleAssignment
            ) {
                $form->get('roleCode')->addError(new FormError('У пользователя уже есть такая активная роль в текущем хозяйстве.'));
            }
        }

        if (!$form->isValid()) {
            return $this->renderIndex(
                $request,
                $userRepository,
                $subscriberRepository,
                $workspaceRoleAssignmentRepository,
                $workspaceContext,
                $paginator,
                $form,
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (!$roleCode instanceof WorkspaceUserRoleCode) {
            throw new \LogicException('Workspace role code must be resolved after successful form validation.');
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
            $oldIdentityValues = $this->userEmailIdentityAuditValues($identity);
            $identity?->markVerified();
            $auditLogger->record(
                action: 'user_email_identity.verified',
                entityTable: 'user_email_identities',
                entityPk: $identity instanceof UserEmailIdentity ? $this->userEmailIdentityAuditPk($identity) : null,
                oldValues: $oldIdentityValues,
                newValues: $identity instanceof UserEmailIdentity ? $this->userEmailIdentityAuditValues($identity) : null,
                changedFields: ['verified_at'],
                reason: 'Email подтвержден при выдаче доступа к хозяйству.',
            );
        }

        if ($user->getApprovedAt() === null) {
            $oldUserValues = $this->userAuditValues($user);
            $user->approve($currentUser);
            $auditLogger->record(
                action: 'user.approved',
                entityTable: 'users',
                entityUuid: $user->getUuid(),
                oldValues: $oldUserValues,
                newValues: $this->userAuditValues($user),
                changedFields: ['approved_at', 'approved_by'],
                reason: 'Пользователь одобрен при выдаче доступа к хозяйству.',
            );
        }

        $oldPasswordValues = $this->userPasswordCredentialAuditValues($user);

        if (!$user->getPasswordCredential() instanceof \App\Entity\UserPasswordCredential) {
            $temporaryPassword ??= $passwordManager->generateTemporaryPassword();
        }

        if ($temporaryPassword !== null) {
            $passwordManager->setPassword(
                $user,
                $temporaryPassword,
                $currentUser,
                new DateTimeImmutable(UserPasswordManager::TEMPORARY_PASSWORD_EXPIRES_AT),
            );
        }

        if ($createdUser) {
            $auditLogger->record(
                action: 'user.created',
                entityTable: 'users',
                entityUuid: $user->getUuid(),
                newValues: $this->userAuditValues($user),
                changedFields: ['created_at', 'approved_at', 'primary_email', 'password'],
                reason: 'Пользователь создан при выдаче доступа к хозяйству.',
            );

            if ($identity instanceof UserEmailIdentity) {
                $auditLogger->record(
                    action: 'user_email_identity.created',
                    entityTable: 'user_email_identities',
                    entityPk: $this->userEmailIdentityAuditPk($identity),
                    newValues: $this->userEmailIdentityAuditValues($identity),
                    changedFields: ['email', 'verified_at'],
                    reason: 'Email добавлен при выдаче доступа к хозяйству.',
                );
            }
        }

        if ($temporaryPassword !== null) {
            $auditLogger->record(
                action: 'user.password_set',
                entityTable: 'user_password_credentials',
                entityPk: ['user_uuid' => $user->getUuid()->toRfc4122()],
                oldValues: $oldPasswordValues,
                newValues: $this->userPasswordCredentialAuditValues($user),
                changedFields: ['password_hash', 'expires_at'],
                reason: 'Временный пароль установлен при выдаче доступа к хозяйству.',
            );
        }

        $assignment = new WorkspaceUserRoleAssignment($workspace, $user, $roleCode, $currentUser);
        $user->addWorkspaceRoleAssignment($assignment);
        $entityManager->persist($assignment);
        $this->recordWorkspaceRoleAuditLog($auditLogger, 'workspace_user_role.granted', $assignment);
        $entityManager->flush();

        if ($temporaryPassword !== null) {
            $this->addFlash('warning', sprintf(
                'Доступ выдан. Email: %s. Временный пароль: %s',
                $email,
                $temporaryPassword,
            ));
        } else {
            $this->addFlash('success', sprintf('Доступ к хозяйству выдан пользователю %s.', $email));
        }

        return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}', name: 'app_admin_user_show', methods: ['GET'])]
    public function show(
        string $uuid,
        UserRepository $userRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
    ): Response {
        $user = $this->findUser($uuid, $userRepository);

        return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext);
    }

    #[Route('/{uuid}/emails/add', name: 'app_admin_user_email_add', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function addEmail(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserEmailIdentityRepository $emailIdentityRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $user = $this->findUser($uuid, $userRepository);
        $form = $this->createEmailAddForm($user);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $email = (string) $form->get('email')->getData();
            $emailNormalized = UserEmailIdentity::normalizeEmail($email);

            if ($emailIdentityRepository->findOneActiveByEmailNormalized($emailNormalized) instanceof UserEmailIdentity) {
                $form->get('email')->addError(new FormError('Активный пользователь с таким email уже существует.'));
            }

            if ($form->isValid()) {
                $identity = new UserEmailIdentity($user, $email);
                $identity->markVerified();
                $identity->setCreatedBy($this->getCurrentUser());
                $user->addEmailIdentity($identity);
                $user->touch($this->getCurrentUser());
                $entityManager->persist($identity);
                $auditLogger->record(
                    action: 'user_email_identity.created',
                    entityTable: 'user_email_identities',
                    entityPk: $this->userEmailIdentityAuditPk($identity),
                    newValues: $this->userEmailIdentityAuditValues($identity),
                    changedFields: ['email', 'verified_at'],
                    reason: 'Email добавлен пользователю из админки.',
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Email %s добавлен.', $identity->getEmail()));

                return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, emailAddForm: $form, statusCode: Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{uuid}/emails/{emailNormalized}/delete', name: 'app_admin_user_email_delete', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function deleteEmail(
        string $uuid,
        string $emailNormalized,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserEmailIdentityRepository $emailIdentityRepository,
        AuditLogger $auditLogger,
    ): Response {
        $user = $this->findUser($uuid, $userRepository);
        $identity = $emailIdentityRepository->findOneActiveByUserAndEmailNormalized($user, $emailNormalized);

        if (!$identity instanceof UserEmailIdentity) {
            throw new NotFoundHttpException('User email identity was not found.');
        }

        if (!$this->isCsrfTokenValid('delete_user_email'.$user->getUuid().$identity->getEmailNormalized(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user->getDeletedAt() === null && $user->getPasswordCredential() !== null && $emailIdentityRepository->countActiveByUser($user) <= 1) {
            $this->addFlash('warning', 'Нельзя отвязать последний активный email пользователя с локальным паролем.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $oldValues = $this->userEmailIdentityAuditValues($identity);
        $identity->delete($this->getCurrentUser());
        $user->touch($this->getCurrentUser());
        $auditLogger->record(
            action: 'user_email_identity.deleted',
            entityTable: 'user_email_identities',
            entityPk: $this->userEmailIdentityAuditPk($identity),
            oldValues: $oldValues,
            newValues: $this->userEmailIdentityAuditValues($identity),
            changedFields: ['deleted_at', 'deleted_by'],
            reason: 'Email отвязан от пользователя из админки.',
        );
        $entityManager->flush();
        $this->addFlash('success', sprintf('Email %s отвязан.', $identity->getEmail()));

        return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/approve', name: 'app_admin_user_approve', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function approve(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        AuditLogger $auditLogger,
    ): Response {
        $user = $this->findUser($uuid, $userRepository);

        if (!$this->isCsrfTokenValid('approve_user'.$user->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user->getApprovedAt() !== null) {
            $this->addFlash('warning', 'Пользователь уже одобрен.');
        } else {
            $oldValues = $this->userAuditValues($user);
            $user->approve($this->getCurrentUser());
            $auditLogger->record(
                action: 'user.approved',
                entityTable: 'users',
                entityUuid: $user->getUuid(),
                oldValues: $oldValues,
                newValues: $this->userAuditValues($user),
                changedFields: ['approved_at', 'approved_by'],
                reason: 'Пользователь одобрен из админки.',
            );
            $entityManager->flush();
            $this->addFlash('success', 'Пользователь одобрен.');
        }

        return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/block', name: 'app_admin_user_block', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function block(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $user = $this->findUser($uuid, $userRepository);
        $form = $this->createBlockForm($user);
        $form->handleRequest($request);

        if ($this->isCurrentUser($user)) {
            $this->addFlash('warning', 'Нельзя заблокировать самого себя.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($userRepository->isLastLoginAllowedAdmin($user)) {
            $this->addFlash('warning', 'Нельзя оставить систему без активного администратора.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($user->getBlockedAt() !== null) {
            $this->addFlash('warning', 'Пользователь уже заблокирован.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $oldValues = $this->userAuditValues($user);
            $user->block((string) $form->get('reason')->getData(), $this->getCurrentUser());
            $auditLogger->record(
                action: 'user.blocked',
                entityTable: 'users',
                entityUuid: $user->getUuid(),
                oldValues: $oldValues,
                newValues: $this->userAuditValues($user),
                changedFields: ['blocked_at', 'blocked_reason', 'blocked_by'],
                reason: (string) $form->get('reason')->getData(),
            );
            $entityManager->flush();
            $this->addFlash('success', 'Пользователь заблокирован.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, blockForm: $form, statusCode: Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{uuid}/unblock', name: 'app_admin_user_unblock', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function unblock(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        AuditLogger $auditLogger,
    ): Response {
        $user = $this->findUser($uuid, $userRepository);

        if (!$this->isCsrfTokenValid('unblock_user'.$user->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($user->getBlockedAt() === null) {
            $this->addFlash('warning', 'Пользователь не заблокирован.');
        } else {
            $oldValues = $this->userAuditValues($user);
            $user->unblock($this->getCurrentUser());
            $auditLogger->record(
                action: 'user.unblocked',
                entityTable: 'users',
                entityUuid: $user->getUuid(),
                oldValues: $oldValues,
                newValues: $this->userAuditValues($user),
                changedFields: ['blocked_at', 'blocked_reason', 'blocked_by'],
                reason: 'Пользователь разблокирован из админки.',
            );
            $entityManager->flush();
            $this->addFlash('success', 'Пользователь разблокирован.');
        }

        return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/delete', name: 'app_admin_user_delete', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function delete(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        AuditLogger $auditLogger,
    ): Response {
        $user = $this->findUser($uuid, $userRepository);

        if (!$this->isCsrfTokenValid('delete_user'.$user->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($this->isCurrentUser($user)) {
            $this->addFlash('warning', 'Нельзя удалить самого себя.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($userRepository->isLastLoginAllowedAdmin($user)) {
            $this->addFlash('warning', 'Нельзя оставить систему без активного администратора.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($user->getDeletedAt() !== null) {
            $this->addFlash('warning', 'Пользователь уже удален.');

            return $this->redirectToRoute('app_admin_user_index', [], Response::HTTP_SEE_OTHER);
        }

        $oldUserValues = $this->userAuditValues($user);
        foreach ($user->getEmailIdentities() as $identity) {
            if ($identity->isActive()) {
                $oldIdentityValues = $this->userEmailIdentityAuditValues($identity);
                $identity->delete($this->getCurrentUser());
                $auditLogger->record(
                    action: 'user_email_identity.deleted',
                    entityTable: 'user_email_identities',
                    entityPk: $this->userEmailIdentityAuditPk($identity),
                    oldValues: $oldIdentityValues,
                    newValues: $this->userEmailIdentityAuditValues($identity),
                    changedFields: ['deleted_at', 'deleted_by'],
                    reason: 'Email отвязан при удалении пользователя из админки.',
                );
            }
        }

        $user->delete($this->getCurrentUser());
        $auditLogger->record(
            action: 'user.deleted',
            entityTable: 'users',
            entityUuid: $user->getUuid(),
            oldValues: $oldUserValues,
            newValues: $this->userAuditValues($user),
            changedFields: ['deleted_at', 'deleted_by'],
            reason: 'Пользователь удален из админки.',
        );
        $entityManager->flush();
        $this->addFlash('success', 'Пользователь удален.');

        return $this->redirectToRoute('app_admin_user_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/password/set', name: 'app_admin_user_password_set', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function setPassword(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserPasswordManager $passwordManager,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $user = $this->findUser($uuid, $userRepository);
        $form = $this->createPasswordSetForm($user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $oldValues = $this->userPasswordCredentialAuditValues($user);
            $passwordManager->setPassword(
                $user,
                (string) $form->get('plainPassword')->getData(),
                $this->getCurrentUser(),
                $form->get('expiresAt')->getData(),
            );
            $auditLogger->record(
                action: 'user.password_set',
                entityTable: 'user_password_credentials',
                entityPk: ['user_uuid' => $user->getUuid()->toRfc4122()],
                oldValues: $oldValues,
                newValues: $this->userPasswordCredentialAuditValues($user),
                changedFields: ['password_hash', 'expires_at'],
                reason: 'Пароль пользователя установлен из админки.',
            );
            $entityManager->flush();
            $this->addFlash('success', 'Пароль установлен.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, passwordSetForm: $form, statusCode: Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{uuid}/subscriber/link', name: 'app_admin_user_subscriber_link', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function linkSubscriber(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $user = $this->findUser($uuid, $userRepository);

        if ($user->getDeletedAt() !== null) {
            $this->addFlash('warning', 'Удаленного пользователя нельзя связать с абонентом.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($subscriberRepository->findOneActiveByWorkspaceAndUser($workspace, $user) instanceof Subscriber) {
            $this->addFlash('warning', 'Пользователь уже связан с абонентом текущего хозяйства.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $form = $this->createSubscriberLinkForm($user, $subscriberRepository, $workspaceContext);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $subscriber = $form->get('subscriber')->getData();

            if (!$subscriber instanceof Subscriber) {
                $form->get('subscriber')->addError(new FormError('Выберите абонента.'));

                return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, subscriberLinkForm: $form, statusCode: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($subscriber->getUser() instanceof User) {
                $form->get('subscriber')->addError(new FormError('Абонент уже связан с пользователем.'));

                return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, subscriberLinkForm: $form, statusCode: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($form->isValid()) {
                $oldValues = $this->subscriberUserLinkAuditValues($subscriber);
                $subscriber->setUser($user);
                $subscriber->touch($this->getCurrentUser());
                $auditLogger->record(
                    action: 'subscriber.user_linked',
                    workspace: $workspace,
                    entityTable: 'subscribers',
                    entityUuid: $subscriber->getUuid(),
                    oldValues: $oldValues,
                    newValues: $this->subscriberUserLinkAuditValues($subscriber),
                    changedFields: ['user_uuid'],
                    reason: 'Связь с пользователем создана из карточки пользователя.',
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Абонент %s связан с пользователем.', $subscriber->getDisplayName()));

                return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, subscriberLinkForm: $form, statusCode: Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{uuid}/subscriber/unlink', name: 'app_admin_user_subscriber_unlink', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function unlinkSubscriber(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $user = $this->findUser($uuid, $userRepository);

        if (!$this->isCsrfTokenValid('unlink_user_subscriber'.$user->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $subscriber = $subscriberRepository->findOneActiveByWorkspaceAndUser($workspace, $user);

        if (!$subscriber instanceof Subscriber) {
            $this->addFlash('warning', 'Пользователь не связан с абонентом текущего хозяйства.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $oldValues = $this->subscriberUserLinkAuditValues($subscriber);
        $subscriber->setUser(null);
        $subscriber->touch($this->getCurrentUser());
        $auditLogger->record(
            action: 'subscriber.user_unlinked',
            workspace: $workspace,
            entityTable: 'subscribers',
            entityUuid: $subscriber->getUuid(),
            oldValues: $oldValues,
            newValues: $this->subscriberUserLinkAuditValues($subscriber),
            changedFields: ['user_uuid'],
            reason: 'Связь с абонентом удалена из карточки пользователя.',
        );
        $entityManager->flush();
        $this->addFlash('success', 'Связь с абонентом удалена.');

        return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/workspace-roles/grant', name: 'app_admin_user_workspace_role_grant', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function grantWorkspaceRole(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $user = $this->findUser($uuid, $userRepository);
        $form = $this->createWorkspaceRoleGrantForm($user);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $roleCode = $form->get('roleCode')->getData();

            if (!$roleCode instanceof WorkspaceUserRoleCode) {
                $form->get('roleCode')->addError(new FormError('Выберите роль.'));

                return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, workspaceRoleGrantForm: $form, statusCode: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($workspaceRoleAssignmentRepository->findOneActiveByWorkspaceUserAndRole($workspace, $user, $roleCode) instanceof WorkspaceUserRoleAssignment) {
                $form->get('roleCode')->addError(new FormError('У пользователя уже есть активная роль в текущем хозяйстве.'));

                return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, workspaceRoleGrantForm: $form, statusCode: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($form->isValid()) {
                $assignment = new WorkspaceUserRoleAssignment($workspace, $user, $roleCode, $this->getCurrentUser());
                $user->addWorkspaceRoleAssignment($assignment);
                $entityManager->persist($assignment);
                $this->recordWorkspaceRoleAuditLog($auditLogger, 'workspace_user_role.granted', $assignment);
                $entityManager->flush();
                $this->addFlash('success', sprintf('Роль "%s" выдана в текущем хозяйстве.', $roleCode->label()));

                return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, workspaceRoleGrantForm: $form, statusCode: Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{uuid}/workspace-roles/{assignmentUuid}/revoke', name: 'app_admin_user_workspace_role_revoke', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ADMIN')]
    public function revokeWorkspaceRole(
        string $uuid,
        string $assignmentUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $user = $this->findUser($uuid, $userRepository);

        try {
            $assignment = $workspaceRoleAssignmentRepository->findOneByWorkspaceUserAndUuid($workspace, $user, Uuid::fromString($assignmentUuid));
        } catch (\InvalidArgumentException) {
            $assignment = null;
        }

        if (!$assignment instanceof WorkspaceUserRoleAssignment) {
            throw new NotFoundHttpException('Workspace user role assignment was not found.');
        }

        if (!$assignment->isActive()) {
            $this->addFlash('warning', 'Роль уже отозвана.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $form = $this->createWorkspaceRoleRevokeForm($user, $assignment);
        $form->handleRequest($request);

        if ($assignment->getRoleCodeEnum() === WorkspaceUserRoleCode::Admin && $workspaceRoleAssignmentRepository->countActiveWorkspaceAdmins($workspace) <= 1) {
            $this->addFlash('warning', 'Нельзя оставить хозяйство без активного администратора.');

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $assignment->revoke((string) $form->get('reason')->getData(), $this->getCurrentUser());
            $this->recordWorkspaceRoleAuditLog($auditLogger, 'workspace_user_role.revoked', $assignment);
            $entityManager->flush();
            $this->addFlash('success', sprintf('Роль "%s" отозвана в текущем хозяйстве.', $assignment->getRoleCodeEnum()->label()));

            return $this->redirectToRoute('app_admin_user_show', ['uuid' => $user->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderShow($user, $subscriberRepository, $workspaceRoleAssignmentRepository, $workspaceContext, statusCode: Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function renderShow(
        User $user,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        ?FormInterface $emailAddForm = null,
        ?FormInterface $passwordSetForm = null,
        ?FormInterface $blockForm = null,
        ?FormInterface $subscriberLinkForm = null,
        ?FormInterface $workspaceRoleGrantForm = null,
        int $statusCode = Response::HTTP_OK,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $canManageIdentity = $this->isGranted('WORKSPACE_ADMIN');
        $subscriber = $subscriberRepository->findActiveByWorkspaceAndUsers($workspace, [$user]);
        $activeSubscriber = $subscriber[$user->getUuid()->toRfc4122()] ?? null;
        $subscriberLinkForm = $canManageIdentity && $activeSubscriber === null && $user->getDeletedAt() === null
            ? ($subscriberLinkForm ?? $this->createSubscriberLinkForm($user, $subscriberRepository, $workspaceContext))
            : null;
        $workspaceRoleAssignments = $workspaceRoleAssignmentRepository->findByWorkspaceAndUser($workspace, $user);
        $workspaceRoleRevokeForms = [];

        if ($canManageIdentity) {
            foreach ($workspaceRoleAssignments as $assignment) {
                if ($assignment->isActive()) {
                    $workspaceRoleRevokeForms[$assignment->getUuid()->toRfc4122()] = $this->createWorkspaceRoleRevokeForm($user, $assignment)->createView();
                }
            }
        }

        return $this->render('admin_user/show.html.twig', [
            'user' => $user,
            'can_manage_identity' => $canManageIdentity,
            'subscriber' => $activeSubscriber,
            'subscriber_link_form' => $subscriberLinkForm?->createView(),
            'workspace_role_assignments' => $workspaceRoleAssignments,
            'workspace_role_grant_form' => $canManageIdentity
                ? ($workspaceRoleGrantForm ?? $this->createWorkspaceRoleGrantForm($user))->createView()
                : null,
            'workspace_role_revoke_forms' => $workspaceRoleRevokeForms,
            'email_add_form' => $canManageIdentity ? ($emailAddForm ?? $this->createEmailAddForm($user))->createView() : null,
            'password_set_form' => $canManageIdentity ? ($passwordSetForm ?? $this->createPasswordSetForm($user))->createView() : null,
            'block_form' => $canManageIdentity ? ($blockForm ?? $this->createBlockForm($user))->createView() : null,
        ], new Response(status: $statusCode));
    }

    private function renderIndex(
        Request $request,
        UserRepository $userRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
        ?FormInterface $workspaceAccessGrantForm = null,
        int $statusCode = Response::HTTP_OK,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $email = trim($request->query->getString('email'));
        $admin = $this->adminFilter($request->query->getString('admin'));
        $status = $this->statusFilter($request->query->getString('status'));
        $linked = $this->linkedFilter($request->query->getString('linked'));
        $sort = $userRepository->normalizeSort($request->query->getString('sort', UserRepository::SORT_CREATED_AT));
        $direction = $userRepository->normalizeSortDirection($request->query->getString('dir', UserRepository::SORT_DESC));
        $pagination = $paginator->paginate(
            $userRepository->createForAdminListQuery($workspace, $email, $admin, $status, $linked, $sort, $direction),
            $request->query->getInt('page', 1),
        );
        $users = $pagination->getItems();

        return $this->render('admin_user/index.html.twig', [
            'users' => $users,
            'can_manage_identity' => $this->isGranted('WORKSPACE_ADMIN'),
            'pagination' => $pagination,
            'subscribers_by_user' => $subscriberRepository->findActiveByWorkspaceAndUsers($workspace, $users),
            'workspace_roles_by_user' => $workspaceRoleAssignmentRepository->findActiveByWorkspaceAndUsers($workspace, $users),
            'workspace_access_grant_form' => $this->isGranted('WORKSPACE_ADMIN')
                ? ($workspaceAccessGrantForm ?? $this->createWorkspaceAccessGrantForm())->createView()
                : null,
            'status_filters' => self::STATUS_FILTERS,
            'filters' => [
                'email' => $email,
                'admin' => match ($admin) {
                    true => 'yes',
                    false => 'no',
                    default => '',
                },
                'status' => $status ?? '',
                'linked' => match ($linked) {
                    true => 'yes',
                    false => 'no',
                    default => '',
                },
            ],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ], new Response(status: $statusCode));
    }

    private function createEmailAddForm(User $user): FormInterface
    {
        return $this->createForm(UserEmailAddType::class, null, [
            'action' => $this->generateUrl('app_admin_user_email_add', ['uuid' => $user->getUuid()]),
        ]);
    }

    private function createPasswordSetForm(User $user): FormInterface
    {
        return $this->createForm(UserPasswordSetType::class, null, [
            'action' => $this->generateUrl('app_admin_user_password_set', ['uuid' => $user->getUuid()]),
        ]);
    }

    private function createBlockForm(User $user): FormInterface
    {
        return $this->createForm(UserBlockType::class, null, [
            'action' => $this->generateUrl('app_admin_user_block', ['uuid' => $user->getUuid()]),
        ]);
    }

    private function createSubscriberLinkForm(User $user, SubscriberRepository $subscriberRepository, WorkspaceContext $workspaceContext): FormInterface
    {
        return $this->createForm(UserSubscriberLinkType::class, null, [
            'active_subscribers' => $subscriberRepository->findUnlinkedActiveByWorkspace($workspaceContext->requireCurrentWorkspace()),
            'action' => $this->generateUrl('app_admin_user_subscriber_link', ['uuid' => $user->getUuid()]),
        ]);
    }

    private function createWorkspaceRoleGrantForm(User $user): FormInterface
    {
        return $this->createForm(WorkspaceUserRoleGrantType::class, null, [
            'action' => $this->generateUrl('app_admin_user_workspace_role_grant', ['uuid' => $user->getUuid()]),
        ]);
    }

    private function createWorkspaceAccessGrantForm(): FormInterface
    {
        return $this->createForm(WorkspaceAccessGrantType::class, null, [
            'action' => $this->generateUrl('app_admin_user_workspace_access_grant'),
        ]);
    }

    private function createWorkspaceRoleRevokeForm(User $user, WorkspaceUserRoleAssignment $assignment): FormInterface
    {
        return $this->createForm(WorkspaceUserRoleRevokeType::class, null, [
            'action' => $this->generateUrl('app_admin_user_workspace_role_revoke', [
                'uuid' => $user->getUuid(),
                'assignmentUuid' => $assignment->getUuid(),
            ]),
        ]);
    }

    private function recordWorkspaceRoleAuditLog(
        AuditLogger $auditLogger,
        string $action,
        WorkspaceUserRoleAssignment $assignment,
    ): void
    {
        $auditLogger->record(
            action: $action,
            workspace: $assignment->getWorkspace(),
            entityTable: 'workspace_user_role_assignments',
            entityUuid: $assignment->getUuid(),
            newValues: [
                'workspace_uuid' => $assignment->getWorkspace()?->getUuid()->toRfc4122(),
                'user_uuid' => $assignment->getUser()?->getUuid()->toRfc4122(),
                'role_code' => $assignment->getRoleCode(),
                'revoked_at' => $assignment->getRevokedAt()?->format(DATE_ATOM),
            ],
            changedFields: ['role_code', 'revoked_at'],
            reason: 'Управление ролью пользователя в хозяйстве из админки.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriberUserLinkAuditValues(Subscriber $subscriber): array
    {
        return [
            'subscriber_uuid' => $subscriber->getUuid()->toRfc4122(),
            'subscriber_name' => $subscriber->getDisplayName(),
            'user_uuid' => $subscriber->getUser()?->getUuid()->toRfc4122(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userAuditValues(User $user): array
    {
        return [
            'uuid' => $user->getUuid()->toRfc4122(),
            'primary_email' => $user->getPrimaryEmail(),
            'status' => $user->getStatusCode(),
            'approved_at' => $this->formatAuditDate($user->getApprovedAt()),
            'approved_by' => $user->getApprovedBy()?->getUuid()->toRfc4122(),
            'blocked_at' => $this->formatAuditDate($user->getBlockedAt()),
            'blocked_reason' => $user->getBlockedReason(),
            'blocked_by' => $user->getBlockedBy()?->getUuid()->toRfc4122(),
            'deleted_at' => $this->formatAuditDate($user->getDeletedAt()),
            'deleted_by' => $user->getDeletedBy()?->getUuid()->toRfc4122(),
            'is_admin' => $user->isAdmin(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function userEmailIdentityAuditPk(UserEmailIdentity $identity): array
    {
        return [
            'user_uuid' => $identity->getUser()?->getUuid()->toRfc4122(),
            'email_normalized' => $identity->getEmailNormalized(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userEmailIdentityAuditValues(UserEmailIdentity $identity): array
    {
        return [
            'user_uuid' => $identity->getUser()?->getUuid()->toRfc4122(),
            'email' => $identity->getEmail(),
            'email_normalized' => $identity->getEmailNormalized(),
            'verified_at' => $this->formatAuditDate($identity->getVerifiedAt()),
            'created_at' => $this->formatAuditDate($identity->getCreatedAt()),
            'deleted_at' => $this->formatAuditDate($identity->getDeletedAt()),
            'deleted_by' => $identity->getDeletedBy()?->getUuid()->toRfc4122(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function userPasswordCredentialAuditValues(User $user): array
    {
        $credential = $user->getPasswordCredential();

        return [
            'user_uuid' => $user->getUuid()->toRfc4122(),
            'has_password' => $credential !== null,
            'changed_at' => $credential === null ? null : $this->formatAuditDate($credential->getChangedAt()),
            'expires_at' => $credential === null ? null : $this->formatAuditDate($credential->getExpiresAt()),
        ];
    }

    private function formatAuditDate(?\DateTimeInterface $date): ?string
    {
        return $date?->format(DATE_ATOM);
    }

    private function findUser(string $uuid, UserRepository $userRepository): User
    {
        try {
            $userUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('User was not found.');
        }

        $user = $userRepository->findOneForAdminShow($userUuid);

        if (!$user instanceof User) {
            throw new NotFoundHttpException('User was not found.');
        }

        return $user;
    }

    private function adminFilter(string $admin): ?bool
    {
        return match ($admin) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    private function statusFilter(string $status): ?string
    {
        return array_key_exists($status, self::STATUS_FILTERS) ? $status : null;
    }

    private function linkedFilter(string $linked): ?bool
    {
        return match ($linked) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    private function isCurrentUser(User $user): bool
    {
        return $this->getCurrentUser()?->getUuid()->toRfc4122() === $user->getUuid()->toRfc4122();
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
