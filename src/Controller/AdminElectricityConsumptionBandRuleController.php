<?php

namespace App\Controller;

use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleAllScope;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\ElectricityTariffProfile;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\ElectricityConsumptionBandRuleScopeMode;
use App\Form\ElectricityConsumptionBandRuleRangeType;
use App\Form\ElectricityConsumptionBandRuleType;
use App\Pagination\AdminPaginator;
use App\Repository\ElectricityConsumptionBandRepository;
use App\Repository\ElectricityConsumptionBandRuleAllScopeRepository;
use App\Repository\ElectricityConsumptionBandRuleRangeRepository;
use App\Repository\ElectricityConsumptionBandRuleRepository;
use App\Repository\ElectricityTariffProfileRepository;
use App\Service\AuditLogger;
use App\Service\WorkspaceContext;
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

#[Route('/admin/electricity-consumption-band-rules')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminElectricityConsumptionBandRuleController extends AbstractController
{
    #[Route(name: 'app_admin_electricity_consumption_band_rule_index', methods: ['GET'])]
    public function index(
        Request $request,
        ElectricityConsumptionBandRuleRepository $ruleRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $sort = $ruleRepository->normalizeSort($request->query->getString('sort', ElectricityConsumptionBandRuleRepository::SORT_TARIFF_PROFILE));
        $direction = $ruleRepository->normalizeSortDirection($request->query->getString('dir', ElectricityConsumptionBandRuleRepository::SORT_ASC));
        $pagination = $paginator->paginate(
            $ruleRepository->createActiveByWorkspaceForAdminListQuery($workspaceContext->requireCurrentWorkspace(), $sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_electricity_consumption_band_rule/index.html.twig', [
            'electricity_consumption_band_rules' => $pagination->getItems(),
            'pagination' => $pagination,
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_electricity_consumption_band_rule_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityConsumptionBandRuleRepository $ruleRepository,
        ElectricityTariffProfileRepository $tariffProfileRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $rule = new ElectricityConsumptionBandRule($workspace);
        $rule->setCreatedBy($this->getCurrentUser());

        $form = $this->createRuleForm($rule, $tariffProfileRepository, $workspace);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateRuleForm($form, $rule, $ruleRepository, $workspace)) {
                $entityManager->persist($rule);
                $entityManager->persist(new ElectricityConsumptionBandRuleAllScope($workspace, $rule, ElectricityConsumptionBandRuleScopeMode::Include));
                $auditLogger->record(
                    action: 'electricity_consumption_band_rule.created',
                    workspace: $workspace,
                    entityTable: 'electricity_consumption_band_rules',
                    entityUuid: $rule->getUuid(),
                    newValues: $this->ruleAuditValues($rule),
                    changedFields: ['tariff_profile_uuid', 'valid_from', 'valid_to', 'month', 'allocation_method', 'priority', 'source_document', 'notes'],
                );
                $entityManager->flush();
                $this->addFlash('success', 'Правило диапазонов потребления создано.');

                return $this->redirectToRoute('app_admin_electricity_consumption_band_rule_show', ['uuid' => $rule->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_consumption_band_rule/new.html.twig', [
                'electricity_consumption_band_rule' => $rule,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_consumption_band_rule/new.html.twig', [
            'electricity_consumption_band_rule' => $rule,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}', name: 'app_admin_electricity_consumption_band_rule_show', methods: ['GET'])]
    public function show(
        string $uuid,
        ElectricityConsumptionBandRuleRepository $ruleRepository,
        ElectricityConsumptionBandRuleRangeRepository $rangeRepository,
        ElectricityConsumptionBandRuleAllScopeRepository $allScopeRepository,
        ElectricityConsumptionBandRepository $consumptionBandRepository,
        WorkspaceContext $workspaceContext,
    ): Response {
        $rule = $this->findActiveRule($uuid, $ruleRepository, $workspaceContext);

        return $this->renderRuleShow($rule, $rangeRepository, $allScopeRepository, $consumptionBandRepository, $workspaceContext);
    }

    #[Route('/{uuid}/edit', name: 'app_admin_electricity_consumption_band_rule_edit', methods: ['GET', 'POST'])]
    public function edit(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityConsumptionBandRuleRepository $ruleRepository,
        ElectricityTariffProfileRepository $tariffProfileRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $rule = $this->findActiveRule($uuid, $ruleRepository, $workspaceContext);
        $oldValues = $this->ruleAuditValues($rule);
        $form = $this->createRuleForm($rule, $tariffProfileRepository, $workspace);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateRuleForm($form, $rule, $ruleRepository, $workspace, $rule->getUuid())) {
                $rule->touch($this->getCurrentUser());
                $auditLogger->record(
                    action: 'electricity_consumption_band_rule.updated',
                    workspace: $rule->getWorkspace(),
                    entityTable: 'electricity_consumption_band_rules',
                    entityUuid: $rule->getUuid(),
                    oldValues: $oldValues,
                    newValues: $this->ruleAuditValues($rule),
                    changedFields: ['tariff_profile_uuid', 'valid_from', 'valid_to', 'month', 'allocation_method', 'priority', 'source_document', 'notes'],
                );
                $entityManager->flush();
                $this->addFlash('success', 'Правило диапазонов потребления сохранено.');

                return $this->redirectToRoute('app_admin_electricity_consumption_band_rule_show', ['uuid' => $rule->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_consumption_band_rule/edit.html.twig', [
                'electricity_consumption_band_rule' => $rule,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_consumption_band_rule/edit.html.twig', [
            'electricity_consumption_band_rule' => $rule,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}/delete', name: 'app_admin_electricity_consumption_band_rule_delete', methods: ['POST'])]
    public function delete(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityConsumptionBandRuleRepository $ruleRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $rule = $this->findActiveRule($uuid, $ruleRepository, $workspaceContext);
        $oldValues = $this->ruleAuditValues($rule);

        if ($this->isCsrfTokenValid('delete'.$rule->getUuid(), $request->getPayload()->getString('_token'))) {
            $rule->delete($this->getCurrentUser());
            $auditLogger->record(
                action: 'electricity_consumption_band_rule.deleted',
                workspace: $rule->getWorkspace(),
                entityTable: 'electricity_consumption_band_rules',
                entityUuid: $rule->getUuid(),
                oldValues: $oldValues,
                newValues: $this->ruleAuditValues($rule),
                changedFields: ['deleted_at', 'deleted_by'],
            );
            $entityManager->flush();
            $this->addFlash('success', 'Правило диапазонов потребления удалено.');
        }

        return $this->redirectToRoute('app_admin_electricity_consumption_band_rule_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/ranges/add', name: 'app_admin_electricity_consumption_band_rule_range_add', methods: ['POST'])]
    public function addRange(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityConsumptionBandRuleRepository $ruleRepository,
        ElectricityConsumptionBandRuleRangeRepository $rangeRepository,
        ElectricityConsumptionBandRuleAllScopeRepository $allScopeRepository,
        ElectricityConsumptionBandRepository $consumptionBandRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $rule = $this->findActiveRule($uuid, $ruleRepository, $workspaceContext);
        $form = $this->createRangeForm($rule, $consumptionBandRepository, $workspace);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $this->validateRangeForm($form, $rule, $rangeRepository, $workspace)) {
            $consumptionBand = $form->get('consumptionBand')->getData();

            if (!$consumptionBand instanceof ElectricityConsumptionBand) {
                $form->get('consumptionBand')->addError(new FormError('Выберите диапазон потребления.'));

                return $this->renderRuleShow($rule, $rangeRepository, $allScopeRepository, $consumptionBandRepository, $workspaceContext, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $lowerBoundKwh = $this->normalizeDecimal((string) $form->get('lowerBoundKwh')->getData());
            $upperBoundKwh = $this->normalizeNullableDecimal($form->get('upperBoundKwh')->getData());
            $range = $rangeRepository->findOneByRuleAndBand($workspace, $rule, $consumptionBand);

            if ($range instanceof ElectricityConsumptionBandRuleRange) {
                $oldValues = $this->rangeAuditValues($range);
                $range
                    ->setLowerBoundKwh($lowerBoundKwh)
                    ->setUpperBoundKwh($upperBoundKwh);
                $message = 'Диапазон правила обновлен.';
                $auditAction = 'electricity_consumption_band_rule_range.updated';
            } else {
                $range = new ElectricityConsumptionBandRuleRange($workspace, $rule, $consumptionBand, $lowerBoundKwh, $upperBoundKwh);
                $entityManager->persist($range);
                $message = 'Диапазон правила добавлен.';
                $oldValues = null;
                $auditAction = 'electricity_consumption_band_rule_range.created';
            }

            $rule->touch($this->getCurrentUser());
            $auditLogger->record(
                action: $auditAction,
                workspace: $workspace,
                entityTable: 'electricity_consumption_band_rule_ranges',
                entityPk: $this->rangeAuditPk($range),
                oldValues: $oldValues,
                newValues: $this->rangeAuditValues($range),
                changedFields: ['lower_bound_kwh', 'upper_bound_kwh'],
            );
            $entityManager->flush();
            $this->addFlash('success', $message);

            return $this->redirectToRoute('app_admin_electricity_consumption_band_rule_show', ['uuid' => $rule->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderRuleShow($rule, $rangeRepository, $allScopeRepository, $consumptionBandRepository, $workspaceContext, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{uuid}/ranges/{bandUuid}/delete', name: 'app_admin_electricity_consumption_band_rule_range_delete', methods: ['POST'])]
    public function deleteRange(
        string $uuid,
        string $bandUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityConsumptionBandRuleRepository $ruleRepository,
        ElectricityConsumptionBandRuleRangeRepository $rangeRepository,
        ElectricityConsumptionBandRepository $consumptionBandRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $rule = $this->findActiveRule($uuid, $ruleRepository, $workspaceContext);
        $consumptionBand = $this->findActiveConsumptionBand($bandUuid, $consumptionBandRepository, $workspaceContext);
        $range = $rangeRepository->findOneByRuleAndBand($workspace, $rule, $consumptionBand);

        if ($range instanceof ElectricityConsumptionBandRuleRange && $this->isCsrfTokenValid('delete-range'.$rule->getUuid().$consumptionBand->getUuid(), $request->getPayload()->getString('_token'))) {
            $oldValues = $this->rangeAuditValues($range);
            $entityPk = $this->rangeAuditPk($range);
            $entityManager->remove($range);
            $rule->touch($this->getCurrentUser());
            $auditLogger->record(
                action: 'electricity_consumption_band_rule_range.deleted',
                workspace: $workspace,
                entityTable: 'electricity_consumption_band_rule_ranges',
                entityPk: $entityPk,
                oldValues: $oldValues,
                changedFields: ['row_deleted'],
            );
            $entityManager->flush();
            $this->addFlash('success', 'Диапазон правила удален.');
        }

        return $this->redirectToRoute('app_admin_electricity_consumption_band_rule_show', ['uuid' => $rule->getUuid()], Response::HTTP_SEE_OTHER);
    }

    private function createRuleForm(ElectricityConsumptionBandRule $rule, ElectricityTariffProfileRepository $tariffProfileRepository, Workspace $workspace): FormInterface
    {
        return $this->createForm(ElectricityConsumptionBandRuleType::class, $rule, [
            'active_tariff_profiles' => $tariffProfileRepository->findActiveByWorkspace($workspace),
        ]);
    }

    private function createRangeForm(ElectricityConsumptionBandRule $rule, ElectricityConsumptionBandRepository $consumptionBandRepository, Workspace $workspace): FormInterface
    {
        return $this->createForm(ElectricityConsumptionBandRuleRangeType::class, null, [
            'active_consumption_bands' => $consumptionBandRepository->findActiveByWorkspace($workspace),
            'action' => $this->generateUrl('app_admin_electricity_consumption_band_rule_range_add', ['uuid' => $rule->getUuid()]),
        ]);
    }

    private function renderRuleShow(
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBandRuleRangeRepository $rangeRepository,
        ElectricityConsumptionBandRuleAllScopeRepository $allScopeRepository,
        ElectricityConsumptionBandRepository $consumptionBandRepository,
        WorkspaceContext $workspaceContext,
        ?FormInterface $rangeForm = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $rangeForm ??= $this->createRangeForm($rule, $consumptionBandRepository, $workspace);

        return $this->render('admin_electricity_consumption_band_rule/show.html.twig', [
            'electricity_consumption_band_rule' => $rule,
            'rule_ranges' => $rangeRepository->findByRule($workspace, $rule),
            'all_scope' => $allScopeRepository->findOneByRule($workspace, $rule),
            'range_form' => $rangeForm,
        ], new Response(status: $status));
    }

    private function validateRuleForm(
        FormInterface $form,
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBandRuleRepository $ruleRepository,
        Workspace $workspace,
        ?Uuid $excludeUuid = null,
    ): bool {
        $isValid = true;

        if ($rule->getMonth() < 1 || $rule->getMonth() > 12) {
            $form->get('month')->addError(new FormError('Месяц должен быть от 1 до 12.'));
            $isValid = false;
        }

        if ($rule->getValidTo() !== null && $rule->getValidTo() <= $rule->getValidFrom()) {
            $form->get('validTo')->addError(new FormError('Дата окончания должна быть позже даты начала.'));
            $isValid = false;
        }

        $tariffProfile = $rule->getTariffProfile();

        if (!$tariffProfile instanceof ElectricityTariffProfile) {
            throw new \LogicException('Consumption band rule must be attached to tariff profile.');
        }

        $overlappingRule = $ruleRepository->findOverlappingActiveRuleWithSamePriority(
            $workspace,
            $tariffProfile,
            $rule->getMonth(),
            $rule->getPriority(),
            $rule->getValidFrom(),
            $rule->getValidTo(),
            $excludeUuid,
        );

        if ($overlappingRule instanceof ElectricityConsumptionBandRule) {
            $form->get('priority')->addError(new FormError('Для этого профиля и месяца уже есть пересекающееся правило с таким приоритетом.'));
            $isValid = false;
        }

        return $isValid;
    }

    private function validateRangeForm(
        FormInterface $form,
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBandRuleRangeRepository $rangeRepository,
        Workspace $workspace,
    ): bool {
        $isValid = true;
        $consumptionBand = $form->get('consumptionBand')->getData();
        $lowerBoundKwh = $this->normalizeDecimal((string) $form->get('lowerBoundKwh')->getData());
        $upperBoundKwh = $this->normalizeNullableDecimal($form->get('upperBoundKwh')->getData());

        if ($upperBoundKwh !== null && (float) $upperBoundKwh <= (float) $lowerBoundKwh) {
            $form->get('upperBoundKwh')->addError(new FormError('Верхняя граница должна быть больше нижней.'));
            $isValid = false;
        }

        if ($consumptionBand instanceof ElectricityConsumptionBand) {
            $overlappingRange = $rangeRepository->findOverlappingRange(
                $workspace,
                $rule,
                $lowerBoundKwh,
                $upperBoundKwh,
                $consumptionBand,
            );

            if ($overlappingRange instanceof ElectricityConsumptionBandRuleRange) {
                $form->get('lowerBoundKwh')->addError(new FormError('Диапазон пересекается с уже заданным диапазоном этого правила.'));
                $isValid = false;
            }
        }

        return $isValid;
    }

    private function findActiveRule(string $uuid, ElectricityConsumptionBandRuleRepository $ruleRepository, WorkspaceContext $workspaceContext): ElectricityConsumptionBandRule
    {
        try {
            $ruleUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Electricity consumption band rule was not found.');
        }

        $rule = $ruleRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $ruleUuid,
        );

        if (!$rule instanceof ElectricityConsumptionBandRule) {
            throw new NotFoundHttpException('Electricity consumption band rule was not found.');
        }

        return $rule;
    }

    private function findActiveConsumptionBand(string $uuid, ElectricityConsumptionBandRepository $consumptionBandRepository, WorkspaceContext $workspaceContext): ElectricityConsumptionBand
    {
        try {
            $consumptionBandUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Electricity consumption band was not found.');
        }

        $consumptionBand = $consumptionBandRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $consumptionBandUuid,
        );

        if (!$consumptionBand instanceof ElectricityConsumptionBand) {
            throw new NotFoundHttpException('Electricity consumption band was not found.');
        }

        return $consumptionBand;
    }

    private function normalizeDecimal(string $value): string
    {
        return trim(str_replace(',', '.', $value));
    }

    private function normalizeNullableDecimal(mixed $value): ?string
    {
        $value = $value === null ? null : $this->normalizeDecimal((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleAuditValues(ElectricityConsumptionBandRule $rule): array
    {
        return [
            'tariff_profile_uuid' => $rule->getTariffProfile()?->getUuid()->toRfc4122(),
            'valid_from' => $rule->getValidFrom()->format('Y-m-d'),
            'valid_to' => $rule->getValidTo()?->format('Y-m-d'),
            'month' => $rule->getMonth(),
            'allocation_method' => $rule->getAllocationMethod()->value,
            'priority' => $rule->getPriority(),
            'source_document' => $rule->getSourceDocument(),
            'notes' => $rule->getNotes(),
            'deleted_at' => $rule->getDeletedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function rangeAuditPk(ElectricityConsumptionBandRuleRange $range): array
    {
        return [
            'workspace_uuid' => $range->getWorkspace()?->getUuid()->toRfc4122(),
            'rule_uuid' => $range->getRule()?->getUuid()->toRfc4122(),
            'consumption_band_uuid' => $range->getConsumptionBand()?->getUuid()->toRfc4122(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rangeAuditValues(ElectricityConsumptionBandRuleRange $range): array
    {
        return [
            ...$this->rangeAuditPk($range),
            'lower_bound_kwh' => $range->getLowerBoundKwh(),
            'upper_bound_kwh' => $range->getUpperBoundKwh(),
        ];
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
