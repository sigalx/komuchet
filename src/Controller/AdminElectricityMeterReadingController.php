<?php

namespace App\Controller;

use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityTariffZone;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\ElectricityMeterReadingSource;
use App\Form\ElectricityMeterReadingCancelType;
use App\Form\ElectricityMeterReadingType;
use App\Repository\ElectricityMeterReadingRepository;
use App\Repository\ElectricityMeterRegisterRepository;
use App\Repository\ElectricityMeterRepository;
use App\Repository\ElectricityTariffZoneRepository;
use App\Pagination\AdminPaginator;
use App\Service\AuditLogger;
use App\Service\ElectricityMeterReadingValidationViolation;
use App\Service\ElectricityMeterReadingValidator;
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

#[IsGranted('WORKSPACE_ACCESS')]
final class AdminElectricityMeterReadingController extends AbstractController
{
    #[Route('/admin/electricity-meter-readings', name: 'app_admin_electricity_meter_reading_index', methods: ['GET'])]
    public function index(
        Request $request,
        ElectricityMeterReadingRepository $readingRepository,
        ElectricityTariffZoneRepository $tariffZoneRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $search = trim($request->query->getString('q'));
        $statusFilter = $readingRepository->normalizeStatusFilter($request->query->getString('status', ElectricityMeterReadingRepository::STATUS_FILTER_ALL));
        $tariffZoneUuid = trim($request->query->getString('tariff_zone_uuid'));
        $takenOnFrom = trim($request->query->getString('taken_on_from'));
        $takenOnTo = trim($request->query->getString('taken_on_to'));
        $sort = $readingRepository->normalizeSort($request->query->getString('sort', ElectricityMeterReadingRepository::SORT_TAKEN_ON));
        $direction = $readingRepository->normalizeSortDirection($request->query->getString('dir', ElectricityMeterReadingRepository::SORT_DESC));
        $tariffZone = null;

        if (Uuid::isValid($tariffZoneUuid)) {
            $tariffZone = $tariffZoneRepository->findOneActiveByWorkspaceAndUuid($workspace, Uuid::fromString($tariffZoneUuid));
        }
        $pagination = $paginator->paginate(
            $readingRepository->createByWorkspaceForAdminListQuery(
                $workspace,
                $search,
                $tariffZone,
                $statusFilter,
                $this->parseDate($takenOnFrom),
                $this->parseDate($takenOnTo),
                $sort,
                $direction,
            ),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_electricity_meter_reading/index.html.twig', [
            'electricity_meter_readings' => $pagination->getItems(),
            'pagination' => $pagination,
            'tariff_zones' => $tariffZoneRepository->findActiveByWorkspace($workspace),
            'filters' => [
                'q' => $search,
                'status' => $statusFilter,
                'tariff_zone_uuid' => $tariffZone instanceof ElectricityTariffZone ? $tariffZone->getUuid()->toRfc4122() : '',
                'taken_on_from' => $takenOnFrom,
                'taken_on_to' => $takenOnTo,
            ],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/admin/electricity-meters/{meterUuid}/readings/new', name: 'app_admin_electricity_meter_reading_new', methods: ['GET', 'POST'])]
    public function new(
        string $meterUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityMeterRepository $meterRepository,
        ElectricityMeterRegisterRepository $registerRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
        ElectricityMeterReadingValidator $readingValidator,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $electricityMeter = $this->findNonDeletedElectricityMeter($meterUuid, $meterRepository, $workspaceContext);
        $reading = new ElectricityMeterReading(
            $workspace,
            $electricityMeter,
            null,
            '0',
            null,
            ElectricityMeterReadingSource::Admin,
            $this->getCurrentUser(),
        );
        $reading->setCreatedBy($this->getCurrentUser());
        $form = $this->createReadingForm($reading, $electricityMeter, $registerRepository, $workspace);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateReadingForm($form, $reading, $electricityMeter, $workspace, $readingValidator)) {
                $entityManager->persist($reading);
                $auditLogger->record(
                    action: 'electricity_meter_reading.created',
                    workspace: $workspace,
                    entityTable: 'electricity_meter_readings',
                    entityUuid: $reading->getUuid(),
                    newValues: [
                        'electricity_meter_uuid' => $electricityMeter->getUuid()->toRfc4122(),
                        'tariff_zone_uuid' => $reading->getTariffZone()?->getUuid()->toRfc4122(),
                        'reading_value' => $reading->getReadingValue(),
                        'taken_on' => $reading->getTakenOn()->format('Y-m-d'),
                        'source' => $reading->getSource()->value,
                    ],
                    changedFields: ['tariff_zone_uuid', 'reading_value', 'taken_on', 'source'],
                );
                $entityManager->flush();
                $this->addFlash('success', 'Показание электросчетчика добавлено.');

                return $this->redirectToRoute('app_admin_electricity_meter_reading_show', ['uuid' => $reading->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_meter_reading/new.html.twig', [
                'electricity_meter_reading' => $reading,
                'electricity_meter' => $electricityMeter,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_meter_reading/new.html.twig', [
            'electricity_meter_reading' => $reading,
            'electricity_meter' => $electricityMeter,
            'form' => $form,
        ]);
    }

    #[Route('/admin/electricity-meter-readings/{uuid}', name: 'app_admin_electricity_meter_reading_show', methods: ['GET'])]
    public function show(string $uuid, ElectricityMeterReadingRepository $readingRepository, WorkspaceContext $workspaceContext): Response
    {
        $reading = $this->findReading($uuid, $readingRepository, $workspaceContext);

        return $this->render('admin_electricity_meter_reading/show.html.twig', [
            'electricity_meter_reading' => $reading,
            'cancel_form' => $this->createCancelForm($reading),
        ]);
    }

    #[Route('/admin/electricity-meter-readings/{uuid}/cancel', name: 'app_admin_electricity_meter_reading_cancel', methods: ['POST'])]
    public function cancel(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        ElectricityMeterReadingRepository $readingRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $reading = $this->findReading($uuid, $readingRepository, $workspaceContext);
        $form = $this->createCancelForm($reading);
        $form->handleRequest($request);

        if (!$reading->isActive()) {
            $this->addFlash('warning', 'Это показание уже не является активным.');

            return $this->redirectToRoute('app_admin_electricity_meter_reading_show', ['uuid' => $reading->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $reason = (string) $form->get('reason')->getData();
            $oldValues = [
                'reading_value' => $reading->getReadingValue(),
                'taken_on' => $reading->getTakenOn()->format('Y-m-d'),
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ];
            $reading->cancel($reason, $this->getCurrentUser());
            $auditLogger->record(
                action: 'electricity_meter_reading.cancelled',
                workspace: $reading->getWorkspace(),
                entityTable: 'electricity_meter_readings',
                entityUuid: $reading->getUuid(),
                oldValues: $oldValues,
                newValues: [
                    'cancelled_at' => $reading->getCancelledAt()?->format(DATE_ATOM),
                    'cancellation_reason' => $reading->getCancellationReason(),
                ],
                changedFields: ['cancelled_at', 'cancelled_by', 'cancellation_reason'],
                reason: $reason,
            );
            $entityManager->flush();
            $this->addFlash('success', 'Показание отменено.');

            return $this->redirectToRoute('app_admin_electricity_meter_reading_show', ['uuid' => $reading->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_electricity_meter_reading/show.html.twig', [
            'electricity_meter_reading' => $reading,
            'cancel_form' => $form,
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    private function createReadingForm(
        ElectricityMeterReading $reading,
        ElectricityMeter $electricityMeter,
        ElectricityMeterRegisterRepository $registerRepository,
        Workspace $workspace,
    ): FormInterface {
        $tariffZones = [];

        foreach ($registerRepository->findByMeter($workspace, $electricityMeter) as $register) {
            $tariffZone = $register->getTariffZone();

            if ($tariffZone instanceof ElectricityTariffZone) {
                $tariffZones[] = $tariffZone;
            }
        }

        return $this->createForm(ElectricityMeterReadingType::class, $reading, [
            'meter_tariff_zones' => $tariffZones,
        ]);
    }

    private function createCancelForm(ElectricityMeterReading $reading): FormInterface
    {
        return $this->createForm(ElectricityMeterReadingCancelType::class, null, [
            'action' => $this->generateUrl('app_admin_electricity_meter_reading_cancel', ['uuid' => $reading->getUuid()]),
        ]);
    }

    private function validateReadingForm(
        FormInterface $form,
        ElectricityMeterReading $reading,
        ElectricityMeter $electricityMeter,
        Workspace $workspace,
        ElectricityMeterReadingValidator $readingValidator,
    ): bool {
        $violations = $readingValidator->validate(
            $workspace,
            $electricityMeter,
            $reading->getTariffZone(),
            $reading->getTakenOn(),
            $reading->getReadingValue(),
        );

        foreach ($violations as $violation) {
            $form->get($this->adminReadingViolationField($violation))->addError(new FormError($violation->getMessage()));
        }

        return $violations === [];
    }

    private function adminReadingViolationField(ElectricityMeterReadingValidationViolation $violation): string
    {
        return match ($violation->getCode()) {
            ElectricityMeterReadingValidator::CODE_TARIFF_ZONE_REQUIRED,
            ElectricityMeterReadingValidator::CODE_METER_REGISTER_MISSING => 'tariffZone',
            ElectricityMeterReadingValidator::CODE_TAKEN_ON_IN_FUTURE,
            ElectricityMeterReadingValidator::CODE_TAKEN_ON_BEFORE_INSTALLATION,
            ElectricityMeterReadingValidator::CODE_TAKEN_ON_AFTER_REMOVAL => 'takenOn',
            default => 'readingValue',
        };
    }

    private function findReading(string $uuid, ElectricityMeterReadingRepository $readingRepository, WorkspaceContext $workspaceContext): ElectricityMeterReading
    {
        try {
            $readingUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Electricity meter reading was not found.');
        }

        $reading = $readingRepository->findOneByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $readingUuid,
        );

        if (!$reading instanceof ElectricityMeterReading) {
            throw new NotFoundHttpException('Electricity meter reading was not found.');
        }

        return $reading;
    }

    private function findNonDeletedElectricityMeter(string $uuid, ElectricityMeterRepository $meterRepository, WorkspaceContext $workspaceContext): ElectricityMeter
    {
        try {
            $meterUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Electricity meter was not found.');
        }

        $electricityMeter = $meterRepository->findOneNonDeletedByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $meterUuid,
        );

        if (!$electricityMeter instanceof ElectricityMeter) {
            throw new NotFoundHttpException('Electricity meter was not found.');
        }

        return $electricityMeter;
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value, new DateTimeZone('Europe/Moscow'));

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
