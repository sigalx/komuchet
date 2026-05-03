<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffZone;
use App\Entity\User;
use App\Form\ElectricityMeterType;
use App\Repository\AccountRepository;
use App\Repository\ElectricityMeterReadingRepository;
use App\Repository\ElectricityMeterRegisterRepository;
use App\Repository\ElectricityMeterRepository;
use App\Repository\ElectricityTariffZoneRepository;
use App\Pagination\AdminPaginator;
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

#[Route('/admin/electricity-meters')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminElectricityMeterController extends AbstractController
{
    #[Route(name: 'app_admin_electricity_meter_index', methods: ['GET'])]
    public function index(
        Request $request,
        ElectricityMeterRepository $electricityMeterRepository,
        ElectricityMeterRegisterRepository $registerRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $search = trim($request->query->getString('q'));
        $statusFilter = $electricityMeterRepository->normalizeStatusFilter($request->query->getString('status', ElectricityMeterRepository::STATUS_FILTER_ALL));
        $sort = $electricityMeterRepository->normalizeSort($request->query->getString('sort', ElectricityMeterRepository::SORT_ACCOUNT_NUMBER));
        $direction = $electricityMeterRepository->normalizeSortDirection($request->query->getString('dir', ElectricityMeterRepository::SORT_ASC));
        $pagination = $paginator->paginate(
            $electricityMeterRepository->createNonDeletedByWorkspaceForAdminListQuery($workspace, $search, $statusFilter, $sort, $direction),
            $request->query->getInt('page', 1),
        );
        $electricityMeters = $pagination->getItems();
        $registersByMeter = [];

        foreach ($electricityMeters as $electricityMeter) {
            $registersByMeter[$electricityMeter->getUuid()->toRfc4122()] = $registerRepository->findByMeter($workspace, $electricityMeter);
        }

        return $this->render('admin_electricity_meter/index.html.twig', [
            'electricity_meters' => $electricityMeters,
            'pagination' => $pagination,
            'registers_by_meter' => $registersByMeter,
            'filters' => [
                'q' => $search,
                'status' => $statusFilter,
            ],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_electricity_meter_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityMeterRepository $electricityMeterRepository,
        AccountRepository $accountRepository,
        ElectricityTariffZoneRepository $tariffZoneRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $electricityMeter = new ElectricityMeter($workspace);
        $electricityMeter->setCreatedBy($this->getCurrentUser());

        $form = $this->createElectricityMeterForm(
            $electricityMeter,
            $accountRepository,
            $tariffZoneRepository,
            $workspaceContext,
        );
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateMeterDates($form, $electricityMeter)) {
                $account = $electricityMeter->getAccount();

                if (!$account instanceof Account) {
                    $form->get('account')->addError(new FormError('Выберите участок.'));

                    return $this->renderNew($electricityMeter, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $activeMeter = $electricityMeterRepository->findOneActiveByWorkspaceAndAccount($workspace, $account);

                if ($electricityMeter->getRemovedOn() === null && $activeMeter instanceof ElectricityMeter) {
                    $form->get('account')->addError(new FormError('У этого участка уже есть активный электросчетчик.'));

                    return $this->renderNew($electricityMeter, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $tariffZones = $this->extractTariffZones($form);

                if ($tariffZones === []) {
                    $form->get('tariffZones')->addError(new FormError('Выберите хотя бы одну тарифную зону.'));

                    return $this->renderNew($electricityMeter, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $entityManager->persist($electricityMeter);

                foreach ($tariffZones as $tariffZone) {
                    $entityManager->persist(new ElectricityMeterRegister($workspace, $electricityMeter, $tariffZone));
                }

                $auditLogger->record(
                    action: 'electricity_meter.created',
                    workspace: $workspace,
                    entityTable: 'electricity_meters',
                    entityUuid: $electricityMeter->getUuid(),
                    newValues: $this->electricityMeterAuditValues($electricityMeter, $tariffZones),
                    changedFields: ['account_uuid', 'serial_number', 'model', 'installed_on', 'removed_on', 'verified_on', 'verification_valid_until', 'notes', 'tariff_zones'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Электросчетчик для участка %s создан.', $account->getNumber()));

                return $this->redirectToRoute('app_admin_electricity_meter_show', ['uuid' => $electricityMeter->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->renderNew($electricityMeter, $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->renderNew($electricityMeter, $form);
    }

    #[Route('/{uuid}', name: 'app_admin_electricity_meter_show', methods: ['GET'])]
    public function show(
        string $uuid,
        ElectricityMeterRepository $electricityMeterRepository,
        ElectricityMeterRegisterRepository $registerRepository,
        ElectricityMeterReadingRepository $readingRepository,
        WorkspaceContext $workspaceContext,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $electricityMeter = $this->findNonDeletedElectricityMeter($uuid, $electricityMeterRepository, $workspaceContext);

        return $this->render('admin_electricity_meter/show.html.twig', [
            'electricity_meter' => $electricityMeter,
            'registers' => $registerRepository->findByMeter($workspace, $electricityMeter),
            'readings' => $readingRepository->findByMeter($workspace, $electricityMeter),
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_admin_electricity_meter_edit', methods: ['GET', 'POST'])]
    public function edit(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityMeterRepository $electricityMeterRepository,
        AccountRepository $accountRepository,
        ElectricityTariffZoneRepository $tariffZoneRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $electricityMeter = $this->findNonDeletedElectricityMeter($uuid, $electricityMeterRepository, $workspaceContext);
        $oldValues = $this->electricityMeterAuditValues($electricityMeter);
        $form = $this->createElectricityMeterForm(
            $electricityMeter,
            $accountRepository,
            $tariffZoneRepository,
            $workspaceContext,
            includeAccount: false,
            includeRegisters: false,
        );
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateMeterDates($form, $electricityMeter)) {
                $electricityMeter->touch($this->getCurrentUser());
                $auditLogger->record(
                    action: 'electricity_meter.updated',
                    workspace: $electricityMeter->getWorkspace(),
                    entityTable: 'electricity_meters',
                    entityUuid: $electricityMeter->getUuid(),
                    oldValues: $oldValues,
                    newValues: $this->electricityMeterAuditValues($electricityMeter),
                    changedFields: ['serial_number', 'model', 'installed_on', 'removed_on', 'verified_on', 'verification_valid_until', 'notes'],
                );
                $entityManager->flush();
                $this->addFlash('success', 'Электросчетчик сохранен.');

                return $this->redirectToRoute('app_admin_electricity_meter_show', ['uuid' => $electricityMeter->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_meter/edit.html.twig', [
                'electricity_meter' => $electricityMeter,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_meter/edit.html.twig', [
            'electricity_meter' => $electricityMeter,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}/delete', name: 'app_admin_electricity_meter_delete', methods: ['POST'])]
    public function delete(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityMeterRepository $electricityMeterRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $electricityMeter = $this->findNonDeletedElectricityMeter($uuid, $electricityMeterRepository, $workspaceContext);
        $oldValues = $this->electricityMeterAuditValues($electricityMeter);

        if ($this->isCsrfTokenValid('delete'.$electricityMeter->getUuid(), $request->getPayload()->getString('_token'))) {
            $electricityMeter->delete($this->getCurrentUser());
            $auditLogger->record(
                action: 'electricity_meter.deleted',
                workspace: $electricityMeter->getWorkspace(),
                entityTable: 'electricity_meters',
                entityUuid: $electricityMeter->getUuid(),
                oldValues: $oldValues,
                newValues: $this->electricityMeterAuditValues($electricityMeter),
                changedFields: ['deleted_at', 'deleted_by'],
            );
            $entityManager->flush();
            $this->addFlash('success', 'Электросчетчик удален.');
        }

        return $this->redirectToRoute('app_admin_electricity_meter_index', [], Response::HTTP_SEE_OTHER);
    }

    private function findNonDeletedElectricityMeter(string $uuid, ElectricityMeterRepository $electricityMeterRepository, WorkspaceContext $workspaceContext): ElectricityMeter
    {
        try {
            $electricityMeterUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Electricity meter was not found.');
        }

        $electricityMeter = $electricityMeterRepository->findOneNonDeletedByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $electricityMeterUuid,
        );

        if (!$electricityMeter instanceof ElectricityMeter) {
            throw new NotFoundHttpException('Electricity meter was not found.');
        }

        return $electricityMeter;
    }

    private function createElectricityMeterForm(
        ElectricityMeter $electricityMeter,
        AccountRepository $accountRepository,
        ElectricityTariffZoneRepository $tariffZoneRepository,
        WorkspaceContext $workspaceContext,
        bool $includeAccount = true,
        bool $includeRegisters = true,
    ): FormInterface {
        $workspace = $workspaceContext->requireCurrentWorkspace();

        return $this->createForm(ElectricityMeterType::class, $electricityMeter, [
            'active_accounts' => $accountRepository->findActiveByWorkspace($workspace),
            'active_tariff_zones' => $tariffZoneRepository->findActiveByWorkspace($workspace),
            'include_account' => $includeAccount,
            'include_registers' => $includeRegisters,
        ]);
    }

    private function renderNew(ElectricityMeter $electricityMeter, FormInterface $form, int $status = Response::HTTP_OK): Response
    {
        return $this->render('admin_electricity_meter/new.html.twig', [
            'electricity_meter' => $electricityMeter,
            'form' => $form,
        ], new Response(status: $status));
    }

    private function validateMeterDates(FormInterface $form, ElectricityMeter $electricityMeter): bool
    {
        $isValid = true;

        if ($electricityMeter->getRemovedOn() !== null && $electricityMeter->getRemovedOn() < $electricityMeter->getInstalledOn()) {
            $form->get('removedOn')->addError(new FormError('Дата снятия не может быть раньше даты установки.'));
            $isValid = false;
        }

        if (
            $electricityMeter->getVerifiedOn() !== null
            && $electricityMeter->getVerificationValidUntil() !== null
            && $electricityMeter->getVerificationValidUntil() < $electricityMeter->getVerifiedOn()
        ) {
            $form->get('verificationValidUntil')->addError(new FormError('Дата окончания поверки не может быть раньше даты поверки.'));
            $isValid = false;
        }

        return $isValid;
    }

    /**
     * @return list<ElectricityTariffZone>
     */
    private function extractTariffZones(FormInterface $form): array
    {
        $tariffZones = $form->get('tariffZones')->getData();

        if (!is_iterable($tariffZones)) {
            return [];
        }

        $result = [];

        foreach ($tariffZones as $tariffZone) {
            if ($tariffZone instanceof ElectricityTariffZone) {
                $result[] = $tariffZone;
            }
        }

        return $result;
    }

    /**
     * @param list<ElectricityTariffZone> $tariffZones
     *
     * @return array<string, mixed>
     */
    private function electricityMeterAuditValues(ElectricityMeter $electricityMeter, array $tariffZones = []): array
    {
        return [
            'account_uuid' => $electricityMeter->getAccount()?->getUuid()->toRfc4122(),
            'account_number' => $electricityMeter->getAccount()?->getNumber(),
            'serial_number' => $electricityMeter->getSerialNumber(),
            'model' => $electricityMeter->getModel(),
            'installed_on' => $electricityMeter->getInstalledOn()->format('Y-m-d'),
            'removed_on' => $electricityMeter->getRemovedOn()?->format('Y-m-d'),
            'verified_on' => $electricityMeter->getVerifiedOn()?->format('Y-m-d'),
            'verification_valid_until' => $electricityMeter->getVerificationValidUntil()?->format('Y-m-d'),
            'notes' => $electricityMeter->getNotes(),
            'tariff_zones' => array_map(
                static fn (ElectricityTariffZone $tariffZone): array => [
                    'uuid' => $tariffZone->getUuid()->toRfc4122(),
                    'code' => $tariffZone->getCode(),
                ],
                $tariffZones
            ),
            'deleted_at' => $electricityMeter->getDeletedAt()?->format(DATE_ATOM),
        ];
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
