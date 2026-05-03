<?php

namespace App\Controller;

use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\User;
use App\Entity\Workspace;
use App\Form\ElectricityTariffPeriodType;
use App\Form\ElectricityTariffRateType;
use App\Repository\ElectricityConsumptionBandRepository;
use App\Repository\ElectricityTariffPeriodRepository;
use App\Repository\ElectricityTariffProfileRepository;
use App\Repository\ElectricityTariffRateRepository;
use App\Repository\ElectricityTariffZoneRepository;
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

#[IsGranted('WORKSPACE_ACCESS')]
final class AdminElectricityTariffPeriodController extends AbstractController
{
    #[Route('/admin/electricity-tariff-profiles/{profileUuid}/periods/new', name: 'app_admin_electricity_tariff_period_new', methods: ['GET', 'POST'])]
    public function new(
        string $profileUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityTariffProfileRepository $tariffProfileRepository,
        ElectricityTariffPeriodRepository $tariffPeriodRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $tariffProfile = $this->findActiveTariffProfile($profileUuid, $tariffProfileRepository, $workspaceContext);
        $tariffPeriod = new ElectricityTariffPeriod($workspace, $tariffProfile);
        $tariffPeriod->setCreatedBy($this->getCurrentUser());

        $form = $this->createForm(ElectricityTariffPeriodType::class, $tariffPeriod);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateTariffPeriod($form, $tariffPeriod, $tariffPeriodRepository, $workspace)) {
                $entityManager->persist($tariffPeriod);
                $auditLogger->record(
                    action: 'electricity_tariff_period.created',
                    workspace: $workspace,
                    entityTable: 'electricity_tariff_periods',
                    entityUuid: $tariffPeriod->getUuid(),
                    newValues: $this->tariffPeriodAuditValues($tariffPeriod),
                    changedFields: ['tariff_profile_uuid', 'valid_from', 'valid_to', 'source_document', 'notes'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Тарифный период для профиля %s создан.', $tariffProfile->getName()));

                return $this->redirectToRoute('app_admin_electricity_tariff_period_show', ['uuid' => $tariffPeriod->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_tariff_period/new.html.twig', [
                'electricity_tariff_period' => $tariffPeriod,
                'electricity_tariff_profile' => $tariffProfile,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_tariff_period/new.html.twig', [
            'electricity_tariff_period' => $tariffPeriod,
            'electricity_tariff_profile' => $tariffProfile,
            'form' => $form,
        ]);
    }

    #[Route('/admin/electricity-tariff-periods/{uuid}', name: 'app_admin_electricity_tariff_period_show', methods: ['GET'])]
    public function show(
        string $uuid,
        ElectricityTariffPeriodRepository $tariffPeriodRepository,
        ElectricityTariffRateRepository $tariffRateRepository,
        ElectricityTariffZoneRepository $tariffZoneRepository,
        ElectricityConsumptionBandRepository $consumptionBandRepository,
        WorkspaceContext $workspaceContext,
    ): Response {
        $tariffPeriod = $this->findActiveTariffPeriod($uuid, $tariffPeriodRepository, $workspaceContext);

        return $this->renderTariffPeriodShow($tariffPeriod, $tariffRateRepository, $tariffZoneRepository, $consumptionBandRepository, $workspaceContext);
    }

    #[Route('/admin/electricity-tariff-periods/{uuid}/edit', name: 'app_admin_electricity_tariff_period_edit', methods: ['GET', 'POST'])]
    public function edit(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityTariffPeriodRepository $tariffPeriodRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $tariffPeriod = $this->findActiveTariffPeriod($uuid, $tariffPeriodRepository, $workspaceContext);
        $oldValues = $this->tariffPeriodAuditValues($tariffPeriod);
        $form = $this->createForm(ElectricityTariffPeriodType::class, $tariffPeriod);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateTariffPeriod($form, $tariffPeriod, $tariffPeriodRepository, $workspace, $tariffPeriod->getUuid())) {
                $tariffPeriod->touch($this->getCurrentUser());
                $auditLogger->record(
                    action: 'electricity_tariff_period.updated',
                    workspace: $tariffPeriod->getWorkspace(),
                    entityTable: 'electricity_tariff_periods',
                    entityUuid: $tariffPeriod->getUuid(),
                    oldValues: $oldValues,
                    newValues: $this->tariffPeriodAuditValues($tariffPeriod),
                    changedFields: ['valid_from', 'valid_to', 'source_document', 'notes'],
                );
                $entityManager->flush();
                $this->addFlash('success', 'Тарифный период сохранен.');

                return $this->redirectToRoute('app_admin_electricity_tariff_period_show', ['uuid' => $tariffPeriod->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_tariff_period/edit.html.twig', [
                'electricity_tariff_period' => $tariffPeriod,
                'electricity_tariff_profile' => $tariffPeriod->getTariffProfile(),
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_tariff_period/edit.html.twig', [
            'electricity_tariff_period' => $tariffPeriod,
            'electricity_tariff_profile' => $tariffPeriod->getTariffProfile(),
            'form' => $form,
        ]);
    }

    #[Route('/admin/electricity-tariff-periods/{uuid}/delete', name: 'app_admin_electricity_tariff_period_delete', methods: ['POST'])]
    public function delete(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityTariffPeriodRepository $tariffPeriodRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $tariffPeriod = $this->findActiveTariffPeriod($uuid, $tariffPeriodRepository, $workspaceContext);
        $tariffProfile = $tariffPeriod->getTariffProfile();
        $oldValues = $this->tariffPeriodAuditValues($tariffPeriod);

        if ($this->isCsrfTokenValid('delete'.$tariffPeriod->getUuid(), $request->getPayload()->getString('_token'))) {
            $tariffPeriod->delete($this->getCurrentUser());
            $auditLogger->record(
                action: 'electricity_tariff_period.deleted',
                workspace: $tariffPeriod->getWorkspace(),
                entityTable: 'electricity_tariff_periods',
                entityUuid: $tariffPeriod->getUuid(),
                oldValues: $oldValues,
                newValues: $this->tariffPeriodAuditValues($tariffPeriod),
                changedFields: ['deleted_at', 'deleted_by'],
            );
            $entityManager->flush();
            $this->addFlash('success', 'Тарифный период удален.');
        }

        return $this->redirectToRoute('app_admin_electricity_tariff_profile_show', ['uuid' => $tariffProfile?->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/electricity-tariff-periods/{uuid}/rates/add', name: 'app_admin_electricity_tariff_rate_add', methods: ['POST'])]
    public function addRate(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityTariffPeriodRepository $tariffPeriodRepository,
        ElectricityTariffRateRepository $tariffRateRepository,
        ElectricityTariffZoneRepository $tariffZoneRepository,
        ElectricityConsumptionBandRepository $consumptionBandRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $tariffPeriod = $this->findActiveTariffPeriod($uuid, $tariffPeriodRepository, $workspaceContext);
        $form = $this->createRateForm($tariffPeriod, $tariffZoneRepository, $consumptionBandRepository, $workspaceContext);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tariffZone = $form->get('tariffZone')->getData();
            $consumptionBand = $form->get('consumptionBand')->getData();
            $rateValue = $this->normalizeRate((string) $form->get('rate')->getData());

            if (!$tariffZone instanceof ElectricityTariffZone) {
                $form->get('tariffZone')->addError(new FormError('Выберите тарифную зону.'));

                return $this->renderTariffPeriodShow($tariffPeriod, $tariffRateRepository, $tariffZoneRepository, $consumptionBandRepository, $workspaceContext, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (!$consumptionBand instanceof ElectricityConsumptionBand) {
                $form->get('consumptionBand')->addError(new FormError('Выберите диапазон потребления.'));

                return $this->renderTariffPeriodShow($tariffPeriod, $tariffRateRepository, $tariffZoneRepository, $consumptionBandRepository, $workspaceContext, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $tariffRate = $tariffRateRepository->findOneByPeriodZoneAndBand($workspace, $tariffPeriod, $tariffZone, $consumptionBand);

            if ($tariffRate instanceof ElectricityTariffRate) {
                $oldValues = $this->tariffRateAuditValues($tariffRate);
                $tariffRate->setRate($rateValue);
                $tariffRate->touch($this->getCurrentUser());
                $message = 'Ставка обновлена.';
                $auditAction = 'electricity_tariff_rate.updated';
            } else {
                $tariffRate = (new ElectricityTariffRate($workspace, $tariffPeriod, $tariffZone, $consumptionBand, $rateValue))
                    ->setCreatedBy($this->getCurrentUser());
                $entityManager->persist($tariffRate);
                $message = 'Ставка добавлена.';
                $oldValues = null;
                $auditAction = 'electricity_tariff_rate.created';
            }

            $auditLogger->record(
                action: $auditAction,
                workspace: $workspace,
                entityTable: 'electricity_tariff_rates',
                entityPk: $this->tariffRateAuditPk($tariffRate),
                oldValues: $oldValues,
                newValues: $this->tariffRateAuditValues($tariffRate),
                changedFields: ['rate'],
            );
            $entityManager->flush();
            $this->addFlash('success', $message);

            return $this->redirectToRoute('app_admin_electricity_tariff_period_show', ['uuid' => $tariffPeriod->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderTariffPeriodShow($tariffPeriod, $tariffRateRepository, $tariffZoneRepository, $consumptionBandRepository, $workspaceContext, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function renderTariffPeriodShow(
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffRateRepository $tariffRateRepository,
        ElectricityTariffZoneRepository $tariffZoneRepository,
        ElectricityConsumptionBandRepository $consumptionBandRepository,
        WorkspaceContext $workspaceContext,
        ?FormInterface $rateForm = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $rateForm ??= $this->createRateForm($tariffPeriod, $tariffZoneRepository, $consumptionBandRepository, $workspaceContext);

        return $this->render('admin_electricity_tariff_period/show.html.twig', [
            'electricity_tariff_period' => $tariffPeriod,
            'electricity_tariff_profile' => $tariffPeriod->getTariffProfile(),
            'tariff_rates' => $tariffRateRepository->findByPeriod($workspace, $tariffPeriod),
            'rate_form' => $rateForm,
        ], new Response(status: $status));
    }

    private function createRateForm(
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffZoneRepository $tariffZoneRepository,
        ElectricityConsumptionBandRepository $consumptionBandRepository,
        WorkspaceContext $workspaceContext,
    ): FormInterface {
        $workspace = $workspaceContext->requireCurrentWorkspace();

        return $this->createForm(ElectricityTariffRateType::class, null, [
            'active_tariff_zones' => $tariffZoneRepository->findActiveByWorkspace($workspace),
            'active_consumption_bands' => $consumptionBandRepository->findActiveByWorkspace($workspace),
            'action' => $this->generateUrl('app_admin_electricity_tariff_rate_add', ['uuid' => $tariffPeriod->getUuid()]),
        ]);
    }

    private function validateTariffPeriod(
        FormInterface $form,
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffPeriodRepository $tariffPeriodRepository,
        Workspace $workspace,
        ?Uuid $excludeUuid = null,
    ): bool {
        $isValid = true;

        if ($tariffPeriod->getValidTo() !== null && $tariffPeriod->getValidTo() <= $tariffPeriod->getValidFrom()) {
            $form->get('validTo')->addError(new FormError('Дата окончания должна быть позже даты начала.'));
            $isValid = false;
        }

        $tariffProfile = $tariffPeriod->getTariffProfile();

        if (!$tariffProfile instanceof ElectricityTariffProfile) {
            throw new \LogicException('Tariff period must be attached to tariff profile.');
        }

        $overlappingPeriod = $tariffPeriodRepository->findOverlappingActivePeriod(
            $workspace,
            $tariffProfile,
            $tariffPeriod->getValidFrom(),
            $tariffPeriod->getValidTo(),
            $excludeUuid,
        );

        if ($overlappingPeriod instanceof ElectricityTariffPeriod) {
            $form->get('validFrom')->addError(new FormError('Период пересекается с уже существующим тарифным периодом профиля.'));
            $isValid = false;
        }

        return $isValid;
    }

    private function findActiveTariffProfile(string $uuid, ElectricityTariffProfileRepository $tariffProfileRepository, WorkspaceContext $workspaceContext): ElectricityTariffProfile
    {
        try {
            $tariffProfileUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Electricity tariff profile was not found.');
        }

        $tariffProfile = $tariffProfileRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $tariffProfileUuid,
        );

        if (!$tariffProfile instanceof ElectricityTariffProfile) {
            throw new NotFoundHttpException('Electricity tariff profile was not found.');
        }

        return $tariffProfile;
    }

    private function findActiveTariffPeriod(string $uuid, ElectricityTariffPeriodRepository $tariffPeriodRepository, WorkspaceContext $workspaceContext): ElectricityTariffPeriod
    {
        try {
            $tariffPeriodUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Electricity tariff period was not found.');
        }

        $tariffPeriod = $tariffPeriodRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $tariffPeriodUuid,
        );

        if (!$tariffPeriod instanceof ElectricityTariffPeriod) {
            throw new NotFoundHttpException('Electricity tariff period was not found.');
        }

        return $tariffPeriod;
    }

    private function normalizeRate(string $rate): string
    {
        return trim(str_replace(',', '.', $rate));
    }

    /**
     * @return array<string, mixed>
     */
    private function tariffPeriodAuditValues(ElectricityTariffPeriod $tariffPeriod): array
    {
        return [
            'tariff_profile_uuid' => $tariffPeriod->getTariffProfile()?->getUuid()->toRfc4122(),
            'valid_from' => $tariffPeriod->getValidFrom()->format('Y-m-d'),
            'valid_to' => $tariffPeriod->getValidTo()?->format('Y-m-d'),
            'source_document' => $tariffPeriod->getSourceDocument(),
            'notes' => $tariffPeriod->getNotes(),
            'deleted_at' => $tariffPeriod->getDeletedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function tariffRateAuditPk(ElectricityTariffRate $tariffRate): array
    {
        return [
            'workspace_uuid' => $tariffRate->getWorkspace()?->getUuid()->toRfc4122(),
            'tariff_period_uuid' => $tariffRate->getTariffPeriod()?->getUuid()->toRfc4122(),
            'tariff_zone_uuid' => $tariffRate->getTariffZone()?->getUuid()->toRfc4122(),
            'consumption_band_uuid' => $tariffRate->getConsumptionBand()?->getUuid()->toRfc4122(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tariffRateAuditValues(ElectricityTariffRate $tariffRate): array
    {
        return [
            ...$this->tariffRateAuditPk($tariffRate),
            'rate' => $tariffRate->getRate(),
        ];
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
