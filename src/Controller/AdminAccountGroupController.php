<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\AccountGroup;
use App\Entity\AccountGroupMember;
use App\Entity\User;
use App\Form\AccountGroupMemberAddType;
use App\Form\AccountGroupType;
use App\Pagination\AdminPaginator;
use App\Repository\AccountGroupMemberRepository;
use App\Repository\AccountGroupRepository;
use App\Repository\AccountRepository;
use App\Service\AuditLogger;
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

#[Route('/admin/account-groups')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminAccountGroupController extends AbstractController
{
    #[Route(name: 'app_admin_account_group_index', methods: ['GET'])]
    public function index(
        Request $request,
        AccountGroupRepository $accountGroupRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response
    {
        $sort = $accountGroupRepository->normalizeSort($request->query->getString('sort', AccountGroupRepository::SORT_NAME));
        $direction = $accountGroupRepository->normalizeSortDirection($request->query->getString('dir', AccountGroupRepository::SORT_ASC));
        $pagination = $paginator->paginate(
            $accountGroupRepository->createActiveByWorkspaceForAdminListQuery($workspaceContext->requireCurrentWorkspace(), $sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_account_group/index.html.twig', [
            'account_groups' => $pagination->getItems(),
            'pagination' => $pagination,
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_account_group_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, AccountGroupRepository $accountGroupRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $accountGroup = new AccountGroup($workspace);
        $accountGroup->setCreatedBy($this->getCurrentUser());

        $form = $this->createForm(AccountGroupType::class, $accountGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $existingGroup = $accountGroupRepository->findOneActiveByWorkspaceAndCode($workspace, $accountGroup->getCode());

            if ($existingGroup instanceof AccountGroup) {
                $form->get('code')->addError(new FormError('Активная группа с таким кодом уже существует.'));

                return $this->render('admin_account_group/new.html.twig', [
                    'account_group' => $accountGroup,
                    'form' => $form,
                ]);
            }

            $entityManager->persist($accountGroup);
            $auditLogger->record(
                action: 'account_group.created',
                workspace: $workspace,
                entityTable: 'account_groups',
                entityUuid: $accountGroup->getUuid(),
                newValues: $this->accountGroupAuditValues($accountGroup),
                changedFields: ['code', 'name', 'description'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Группа %s создана.', $accountGroup->getName()));

            return $this->redirectToRoute('app_admin_account_group_show', ['uuid' => $accountGroup->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_account_group/new.html.twig', [
            'account_group' => $accountGroup,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}', name: 'app_admin_account_group_show', methods: ['GET'])]
    public function show(
        string $uuid,
        AccountGroupRepository $accountGroupRepository,
        AccountGroupMemberRepository $memberRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
    ): Response {
        $accountGroup = $this->findActiveAccountGroup($uuid, $accountGroupRepository, $workspaceContext);

        return $this->renderAccountGroupShow($accountGroup, $memberRepository, $accountRepository, $workspaceContext);
    }

    #[Route('/{uuid}/edit', name: 'app_admin_account_group_edit', methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request, EntityManagerInterface $entityManager, AccountGroupRepository $accountGroupRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $accountGroup = $this->findActiveAccountGroup($uuid, $accountGroupRepository, $workspaceContext);
        $oldValues = $this->accountGroupAuditValues($accountGroup);
        $form = $this->createForm(AccountGroupType::class, $accountGroup);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $existingGroup = $accountGroupRepository->findOneActiveByWorkspaceAndCode($workspace, $accountGroup->getCode());

            if ($existingGroup instanceof AccountGroup && $existingGroup->getUuid()->toRfc4122() !== $accountGroup->getUuid()->toRfc4122()) {
                $form->get('code')->addError(new FormError('Активная группа с таким кодом уже существует.'));

                return $this->render('admin_account_group/edit.html.twig', [
                    'account_group' => $accountGroup,
                    'form' => $form,
                ]);
            }

            $accountGroup->touch($this->getCurrentUser());
            $auditLogger->record(
                action: 'account_group.updated',
                workspace: $accountGroup->getWorkspace(),
                entityTable: 'account_groups',
                entityUuid: $accountGroup->getUuid(),
                oldValues: $oldValues,
                newValues: $this->accountGroupAuditValues($accountGroup),
                changedFields: ['code', 'name', 'description'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Группа %s сохранена.', $accountGroup->getName()));

            return $this->redirectToRoute('app_admin_account_group_show', ['uuid' => $accountGroup->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_account_group/edit.html.twig', [
            'account_group' => $accountGroup,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}/delete', name: 'app_admin_account_group_delete', methods: ['POST'])]
    public function delete(string $uuid, Request $request, EntityManagerInterface $entityManager, AccountGroupRepository $accountGroupRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $accountGroup = $this->findActiveAccountGroup($uuid, $accountGroupRepository, $workspaceContext);
        $oldValues = $this->accountGroupAuditValues($accountGroup);

        if ($this->isCsrfTokenValid('delete'.$accountGroup->getUuid(), $request->getPayload()->getString('_token'))) {
            $accountGroup->delete($this->getCurrentUser());
            $auditLogger->record(
                action: 'account_group.deleted',
                workspace: $accountGroup->getWorkspace(),
                entityTable: 'account_groups',
                entityUuid: $accountGroup->getUuid(),
                oldValues: $oldValues,
                newValues: $this->accountGroupAuditValues($accountGroup),
                changedFields: ['deleted_at', 'deleted_by'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Группа %s удалена.', $accountGroup->getName()));
        }

        return $this->redirectToRoute('app_admin_account_group_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/members/add', name: 'app_admin_account_group_member_add', methods: ['POST'])]
    public function addMember(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccountGroupRepository $accountGroupRepository,
        AccountGroupMemberRepository $memberRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $accountGroup = $this->findActiveAccountGroup($uuid, $accountGroupRepository, $workspaceContext);
        $form = $this->createMemberAddForm($accountGroup, $memberRepository, $accountRepository, $workspaceContext);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $account = $form->get('account')->getData();
            $validFrom = $form->get('validFrom')->getData();

            if (!$account instanceof Account) {
                $form->get('account')->addError(new FormError('Выберите участок.'));

                return $this->renderAccountGroupShow($accountGroup, $memberRepository, $accountRepository, $workspaceContext, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (!$validFrom instanceof DateTimeImmutable) {
                $form->get('validFrom')->addError(new FormError('Укажите дату начала.'));

                return $this->renderAccountGroupShow($accountGroup, $memberRepository, $accountRepository, $workspaceContext, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($memberRepository->findOneActiveByGroupAndAccount($workspace, $accountGroup, $account) instanceof AccountGroupMember) {
                $form->get('account')->addError(new FormError('Участок уже входит в эту группу.'));

                return $this->renderAccountGroupShow($accountGroup, $memberRepository, $accountRepository, $workspaceContext, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $member = new AccountGroupMember($workspace, $accountGroup, $account, $validFrom, $this->getCurrentUser());

            $entityManager->persist($member);
            $auditLogger->record(
                action: 'account_group_member.added',
                workspace: $workspace,
                entityTable: 'account_group_members',
                entityPk: $this->accountGroupMemberAuditPk($member),
                newValues: $this->accountGroupMemberAuditValues($member),
                changedFields: ['account_group_uuid', 'account_uuid', 'valid_from'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Участок %s добавлен в группу.', $account->getNumber()));

            return $this->redirectToRoute('app_admin_account_group_show', ['uuid' => $accountGroup->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderAccountGroupShow($accountGroup, $memberRepository, $accountRepository, $workspaceContext, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{uuid}/members/{accountUuid}/close', name: 'app_admin_account_group_member_close', methods: ['POST'])]
    public function closeMember(
        string $uuid,
        string $accountUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccountGroupRepository $accountGroupRepository,
        AccountGroupMemberRepository $memberRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $accountGroup = $this->findActiveAccountGroup($uuid, $accountGroupRepository, $workspaceContext);
        $account = $this->findActiveAccount($accountUuid, $accountRepository, $workspaceContext);
        $member = $memberRepository->findOneActiveByGroupAndAccount($workspace, $accountGroup, $account);

        if (!$member instanceof AccountGroupMember) {
            throw new NotFoundHttpException('Account group member was not found.');
        }

        if ($this->isCsrfTokenValid('close_member'.$accountGroup->getUuid().$account->getUuid(), $request->getPayload()->getString('_token'))) {
            $oldValues = $this->accountGroupMemberAuditValues($member);
            $validTo = $this->defaultValidTo($member);
            $member->setValidTo($validTo);
            $auditLogger->record(
                action: 'account_group_member.closed',
                workspace: $workspace,
                entityTable: 'account_group_members',
                entityPk: $this->accountGroupMemberAuditPk($member),
                oldValues: $oldValues,
                newValues: $this->accountGroupMemberAuditValues($member),
                changedFields: ['valid_to'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Участок %s исключен из группы.', $account->getNumber()));
        }

        return $this->redirectToRoute('app_admin_account_group_show', ['uuid' => $accountGroup->getUuid()], Response::HTTP_SEE_OTHER);
    }

    private function findActiveAccountGroup(string $uuid, AccountGroupRepository $accountGroupRepository, WorkspaceContext $workspaceContext): AccountGroup
    {
        try {
            $accountGroupUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Account group was not found.');
        }

        $accountGroup = $accountGroupRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $accountGroupUuid,
        );

        if (!$accountGroup instanceof AccountGroup) {
            throw new NotFoundHttpException('Account group was not found.');
        }

        return $accountGroup;
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

    private function renderAccountGroupShow(
        AccountGroup $accountGroup,
        AccountGroupMemberRepository $memberRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
        ?FormInterface $memberAddForm = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $activeMembers = $memberRepository->findActiveByGroup($workspace, $accountGroup);
        $memberAddForm ??= $this->createMemberAddForm($accountGroup, $memberRepository, $accountRepository, $workspaceContext);

        return $this->render('admin_account_group/show.html.twig', [
            'account_group' => $accountGroup,
            'active_members' => $activeMembers,
            'member_add_form' => $memberAddForm,
        ], new Response(status: $status));
    }

    private function createMemberAddForm(
        AccountGroup $accountGroup,
        AccountGroupMemberRepository $memberRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
    ): FormInterface {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $activeMembers = $memberRepository->findActiveByGroup($workspace, $accountGroup);
        $activeAccountUuids = [];

        foreach ($activeMembers as $member) {
            $account = $member->getAccount();

            if ($account instanceof Account) {
                $activeAccountUuids[$account->getUuid()->toRfc4122()] = true;
            }
        }

        $availableAccounts = array_values(array_filter(
            $accountRepository->findActiveByWorkspace($workspace),
            static fn (Account $account): bool => !isset($activeAccountUuids[$account->getUuid()->toRfc4122()]),
        ));

        return $this->createForm(AccountGroupMemberAddType::class, null, [
            'active_accounts' => $availableAccounts,
            'action' => $this->generateUrl('app_admin_account_group_member_add', ['uuid' => $accountGroup->getUuid()]),
        ]);
    }

    private function defaultValidTo(AccountGroupMember $member): DateTimeImmutable
    {
        $tomorrow = new DateTimeImmutable('tomorrow');

        if ($tomorrow <= $member->getValidFrom()) {
            return $member->getValidFrom()->modify('+1 day');
        }

        return $tomorrow;
    }

    /**
     * @return array<string, mixed>
     */
    private function accountGroupAuditValues(AccountGroup $accountGroup): array
    {
        return [
            'code' => $accountGroup->getCode(),
            'name' => $accountGroup->getName(),
            'description' => $accountGroup->getDescription(),
            'deleted_at' => $accountGroup->getDeletedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function accountGroupMemberAuditPk(AccountGroupMember $member): array
    {
        return [
            'workspace_uuid' => $member->getWorkspace()?->getUuid()->toRfc4122(),
            'account_group_uuid' => $member->getAccountGroup()?->getUuid()->toRfc4122(),
            'account_uuid' => $member->getAccount()?->getUuid()->toRfc4122(),
            'valid_from' => $member->getValidFrom()->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountGroupMemberAuditValues(AccountGroupMember $member): array
    {
        return [
            ...$this->accountGroupMemberAuditPk($member),
            'account_group_code' => $member->getAccountGroup()?->getCode(),
            'account_number' => $member->getAccount()?->getNumber(),
            'valid_to' => $member->getValidTo()?->format('Y-m-d'),
        ];
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
