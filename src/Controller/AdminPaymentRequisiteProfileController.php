<?php

namespace App\Controller;

use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use App\Form\PaymentRequisiteProfileType;
use App\Pagination\AdminPaginator;
use App\Repository\PaymentRequisiteAssignmentRepository;
use App\Repository\PaymentRequisiteProfileRepository;
use App\Service\AuditLogger;
use App\Service\WorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
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

#[Route('/admin/payment-requisite-profiles')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminPaymentRequisiteProfileController extends AbstractController
{
    #[Route(name: 'app_admin_payment_requisite_profile_index', methods: ['GET'])]
    public function index(
        Request $request,
        PaymentRequisiteProfileRepository $profileRepository,
        PaymentRequisiteAssignmentRepository $assignmentRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $sort = $profileRepository->normalizeSort($request->query->getString('sort', PaymentRequisiteProfileRepository::SORT_NAME));
        $direction = $profileRepository->normalizeSortDirection($request->query->getString('dir', PaymentRequisiteProfileRepository::SORT_ASC));
        $pagination = $paginator->paginate(
            $profileRepository->createActiveByWorkspaceForAdminListQuery($workspace, $sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_payment_requisite_profile/index.html.twig', [
            'payment_requisite_profiles' => $pagination->getItems(),
            'open_assignments' => $assignmentRepository->findOpenByWorkspace($workspace),
            'pagination' => $pagination,
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_payment_requisite_profile_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        PaymentRequisiteProfileRepository $profileRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $profile = new PaymentRequisiteProfile($workspace, $this->workspaceToday($workspace->getTimezone()));
        $profile->setCreatedBy($this->getCurrentUser());

        $form = $this->createForm(PaymentRequisiteProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()
                && $this->validateProfile($form, $profile, $profileRepository, $workspaceContext)
            ) {
                $entityManager->persist($profile);
                $auditLogger->record(
                    action: 'payment_requisite_profile.created',
                    workspace: $workspace,
                    entityTable: 'payment_requisite_profiles',
                    entityUuid: $profile->getUuid(),
                    newValues: $this->profileAuditValues($profile),
                    changedFields: array_keys($this->profileAuditValues($profile)),
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Профиль реквизитов %s создан.', $profile->getName()));

                return $this->redirectToRoute('app_admin_payment_requisite_profile_show', ['uuid' => $profile->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_payment_requisite_profile/new.html.twig', [
                'payment_requisite_profile' => $profile,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_payment_requisite_profile/new.html.twig', [
            'payment_requisite_profile' => $profile,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}', name: 'app_admin_payment_requisite_profile_show', methods: ['GET'])]
    public function show(
        string $uuid,
        PaymentRequisiteProfileRepository $profileRepository,
        PaymentRequisiteAssignmentRepository $assignmentRepository,
        WorkspaceContext $workspaceContext,
    ): Response {
        $profile = $this->findActiveProfile($uuid, $profileRepository, $workspaceContext);

        return $this->render('admin_payment_requisite_profile/show.html.twig', [
            'payment_requisite_profile' => $profile,
            'open_assignments' => $assignmentRepository->findOpenByProfile($profile),
            'assignment_scope_choices' => $this->assignmentScopeChoices(),
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_admin_payment_requisite_profile_edit', methods: ['GET', 'POST'])]
    public function edit(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        PaymentRequisiteProfileRepository $profileRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $profile = $this->findActiveProfile($uuid, $profileRepository, $workspaceContext);
        $oldValues = $this->profileAuditValues($profile);
        $form = $this->createForm(PaymentRequisiteProfileType::class, $profile);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()
                && $this->validateProfile($form, $profile, $profileRepository, $workspaceContext)
            ) {
                $profile->touch($this->getCurrentUser());
                $auditLogger->record(
                    action: 'payment_requisite_profile.updated',
                    workspace: $profile->getWorkspace(),
                    entityTable: 'payment_requisite_profiles',
                    entityUuid: $profile->getUuid(),
                    oldValues: $oldValues,
                    newValues: $this->profileAuditValues($profile),
                    changedFields: array_keys($this->profileAuditValues($profile)),
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Профиль реквизитов %s сохранен.', $profile->getName()));

                return $this->redirectToRoute('app_admin_payment_requisite_profile_show', ['uuid' => $profile->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_payment_requisite_profile/edit.html.twig', [
                'payment_requisite_profile' => $profile,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_payment_requisite_profile/edit.html.twig', [
            'payment_requisite_profile' => $profile,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}/assign-scope', name: 'app_admin_payment_requisite_profile_assign_scope', methods: ['POST'])]
    public function assignScope(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        PaymentRequisiteProfileRepository $profileRepository,
        PaymentRequisiteAssignmentRepository $assignmentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $profile = $this->findActiveProfile($uuid, $profileRepository, $workspaceContext);

        if (!$this->isCsrfTokenValid('assign_scope'.$profile->getUuid(), $request->getPayload()->getString('_token'))) {
            return $this->redirectToRoute('app_admin_payment_requisite_profile_show', ['uuid' => $profile->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $accrualTypeValue = trim($request->getPayload()->getString('accrual_type'));
        $accrualType = $accrualTypeValue === '' ? null : AccrualType::tryFrom($accrualTypeValue);

        if ($accrualTypeValue !== '' && !$accrualType instanceof AccrualType) {
            $this->addFlash('danger', 'Неизвестная область назначения реквизитов.');

            return $this->redirectToRoute('app_admin_payment_requisite_profile_show', ['uuid' => $profile->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->assignProfileForScope(
            workspace: $workspace,
            profile: $profile,
            accrualType: $accrualType,
            entityManager: $entityManager,
            assignmentRepository: $assignmentRepository,
            auditLogger: $auditLogger,
        );
    }

    #[Route('/assignments/{assignmentUuid}/close', name: 'app_admin_payment_requisite_assignment_close', methods: ['POST'])]
    public function closeAssignment(
        string $assignmentUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        PaymentRequisiteAssignmentRepository $assignmentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();

        try {
            $uuid = Uuid::fromString($assignmentUuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Payment requisite assignment was not found.');
        }

        $assignment = $assignmentRepository->findOneOpenByWorkspaceAndUuid($workspace, $uuid);

        if (!$assignment instanceof PaymentRequisiteAssignment) {
            throw new NotFoundHttpException('Payment requisite assignment was not found.');
        }

        $profile = $assignment->getPaymentRequisiteProfile();

        if ($this->isCsrfTokenValid('close_assignment'.$assignment->getUuid(), $request->getPayload()->getString('_token'))) {
            $oldValues = $this->assignmentAuditValues($assignment);
            $assignment->close('manual_close', $this->getCurrentUser());
            $auditLogger->record(
                action: 'payment_requisite_assignment.closed',
                workspace: $workspace,
                entityTable: 'payment_requisite_assignments',
                entityUuid: $assignment->getUuid(),
                oldValues: $oldValues,
                newValues: $this->assignmentAuditValues($assignment),
                changedFields: ['closed_at', 'closed_by', 'close_reason'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Назначение реквизитов для области "%s" снято.', $assignment->getScopeLabel()));
        }

        if ($profile instanceof PaymentRequisiteProfile && $profile->isActive()) {
            return $this->redirectToRoute('app_admin_payment_requisite_profile_show', ['uuid' => $profile->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_admin_payment_requisite_profile_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/delete', name: 'app_admin_payment_requisite_profile_delete', methods: ['POST'])]
    public function delete(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        PaymentRequisiteProfileRepository $profileRepository,
        PaymentRequisiteAssignmentRepository $assignmentRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $profile = $this->findActiveProfile($uuid, $profileRepository, $workspaceContext);
        $oldValues = $this->profileAuditValues($profile);

        if ($this->isCsrfTokenValid('delete'.$profile->getUuid(), $request->getPayload()->getString('_token'))) {
            foreach ($assignmentRepository->findOpenByProfile($profile) as $assignment) {
                $assignmentOldValues = $this->assignmentAuditValues($assignment);
                $assignment->close('profile_deleted', $this->getCurrentUser());
                $auditLogger->record(
                    action: 'payment_requisite_assignment.closed',
                    workspace: $profile->getWorkspace(),
                    entityTable: 'payment_requisite_assignments',
                    entityUuid: $assignment->getUuid(),
                    oldValues: $assignmentOldValues,
                    newValues: $this->assignmentAuditValues($assignment),
                    changedFields: ['closed_at', 'closed_by', 'close_reason'],
                );
            }

            $profile->delete($this->getCurrentUser());
            $auditLogger->record(
                action: 'payment_requisite_profile.deleted',
                workspace: $profile->getWorkspace(),
                entityTable: 'payment_requisite_profiles',
                entityUuid: $profile->getUuid(),
                oldValues: $oldValues,
                newValues: $this->profileAuditValues($profile),
                changedFields: ['deleted_at', 'deleted_by'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Профиль реквизитов %s удален.', $profile->getName()));
        }

        return $this->redirectToRoute('app_admin_payment_requisite_profile_index', [], Response::HTTP_SEE_OTHER);
    }

    private function findActiveProfile(string $uuid, PaymentRequisiteProfileRepository $profileRepository, WorkspaceContext $workspaceContext): PaymentRequisiteProfile
    {
        try {
            $profileUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Payment requisite profile was not found.');
        }

        $profile = $profileRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $profileUuid,
        );

        if (!$profile instanceof PaymentRequisiteProfile) {
            throw new NotFoundHttpException('Payment requisite profile was not found.');
        }

        return $profile;
    }

    private function validateProfile(FormInterface $form, PaymentRequisiteProfile $profile, PaymentRequisiteProfileRepository $profileRepository, WorkspaceContext $workspaceContext): bool
    {
        $isValid = true;

        $existingProfile = $profileRepository->findOneActiveByWorkspaceAndCode(
            $workspaceContext->requireCurrentWorkspace(),
            $profile->getCode(),
        );

        if ($existingProfile instanceof PaymentRequisiteProfile && $existingProfile->getUuid()->toRfc4122() !== $profile->getUuid()->toRfc4122()) {
            $form->get('code')->addError(new FormError('Активный профиль реквизитов с таким кодом уже существует.'));
            $isValid = false;
        }

        if ($profile->getValidTo() instanceof DateTimeImmutable && $profile->getValidTo() <= $profile->getValidFrom()) {
            $form->get('validTo')->addError(new FormError('Дата окончания должна быть позже даты начала.'));
            $isValid = false;
        }

        return $isValid;
    }

    private function assignProfileForScope(
        Workspace $workspace,
        PaymentRequisiteProfile $profile,
        ?AccrualType $accrualType,
        EntityManagerInterface $entityManager,
        PaymentRequisiteAssignmentRepository $assignmentRepository,
        AuditLogger $auditLogger,
    ): Response {
        $today = $this->workspaceToday($workspace->getTimezone());

        if (!$profile->isValidOn($today)) {
            $this->addFlash('danger', sprintf('Нельзя назначить реквизиты для области "%s": профиль не действует на текущую дату.', $this->scopeLabel($accrualType)));

            return $this->redirectToRoute('app_admin_payment_requisite_profile_show', ['uuid' => $profile->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $currentAssignment = $assignmentRepository->findCurrentByScope($workspace, $accrualType, $today);
        if ($currentAssignment instanceof PaymentRequisiteAssignment
            && $currentAssignment->getPaymentRequisiteProfile()?->getUuid()->toRfc4122() === $profile->getUuid()->toRfc4122()
        ) {
            $this->addFlash('info', sprintf('Профиль %s уже назначен для области "%s".', $profile->getName(), $this->scopeLabel($accrualType)));

            return $this->redirectToRoute('app_admin_payment_requisite_profile_show', ['uuid' => $profile->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $closedAssignments = false;

        foreach ($assignmentRepository->findOpenByWorkspace($workspace) as $assignment) {
            if ($assignment->getAccrualType() !== $accrualType) {
                continue;
            }

            $closedAssignments = true;
            $oldValues = $this->assignmentAuditValues($assignment);
            $assignment->close('replaced', $this->getCurrentUser());
            $auditLogger->record(
                action: 'payment_requisite_assignment.closed',
                workspace: $workspace,
                entityTable: 'payment_requisite_assignments',
                entityUuid: $assignment->getUuid(),
                oldValues: $oldValues,
                newValues: $this->assignmentAuditValues($assignment),
                changedFields: ['closed_at', 'closed_by', 'close_reason'],
            );
        }

        if ($closedAssignments) {
            $entityManager->flush();
        }

        $assignment = new PaymentRequisiteAssignment($workspace, $profile, $accrualType, $today, $this->getCurrentUser());
        $entityManager->persist($assignment);
        $auditLogger->record(
            action: 'payment_requisite_assignment.created',
            workspace: $workspace,
            entityTable: 'payment_requisite_assignments',
            entityUuid: $assignment->getUuid(),
            newValues: $this->assignmentAuditValues($assignment),
            changedFields: array_keys($this->assignmentAuditValues($assignment)),
        );

        $entityManager->flush();
        $this->addFlash('success', sprintf('Профиль %s назначен для области "%s".', $profile->getName(), $this->scopeLabel($accrualType)));

        return $this->redirectToRoute('app_admin_payment_requisite_profile_show', ['uuid' => $profile->getUuid()], Response::HTTP_SEE_OTHER);
    }

    /**
     * @return array<string, string>
     */
    private function assignmentScopeChoices(): array
    {
        $choices = [
            '' => 'Все начисления',
        ];

        foreach (AccrualType::cases() as $type) {
            $choices[$type->value] = $type->label();
        }

        return $choices;
    }

    private function scopeLabel(?AccrualType $accrualType): string
    {
        return $accrualType?->label() ?? 'Все начисления';
    }

    /**
     * @return array<string, mixed>
     */
    private function profileAuditValues(PaymentRequisiteProfile $profile): array
    {
        return [
            'code' => $profile->getCode(),
            'name' => $profile->getName(),
            'recipient_name' => $profile->getRecipientName(),
            'recipient_inn' => $profile->getRecipientInn(),
            'recipient_kpp' => $profile->getRecipientKpp(),
            'bank_name' => $profile->getBankName(),
            'bank_bik' => $profile->getBankBik(),
            'bank_correspondent_account' => $profile->getBankCorrespondentAccount(),
            'bank_account' => $profile->getBankAccount(),
            'payment_purpose_template' => $profile->getPaymentPurposeTemplate(),
            'valid_from' => $profile->getValidFrom()->format('Y-m-d'),
            'valid_to' => $profile->getValidTo()?->format('Y-m-d'),
            'deleted_at' => $profile->getDeletedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignmentAuditValues(PaymentRequisiteAssignment $assignment): array
    {
        return [
            'payment_requisite_profile_uuid' => $assignment->getPaymentRequisiteProfile()?->getUuid()->toRfc4122(),
            'accrual_type' => $assignment->getAccrualType()?->value,
            'valid_from' => $assignment->getValidFrom()->format('Y-m-d'),
            'valid_to' => $assignment->getValidTo()?->format('Y-m-d'),
            'assigned_at' => $assignment->getAssignedAt()->format(DATE_ATOM),
            'closed_at' => $assignment->getClosedAt()?->format(DATE_ATOM),
            'close_reason' => $assignment->getCloseReason(),
        ];
    }

    private function workspaceToday(string $timezoneName): DateTimeImmutable
    {
        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            $timezone = new DateTimeZone('Europe/Moscow');
        }

        return new DateTimeImmutable('today', $timezone);
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
