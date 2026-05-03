<?php

namespace App\Controller;

use App\Entity\ElectricityTariffZone;
use App\Entity\User;
use App\Form\ElectricityTariffZoneType;
use App\Pagination\AdminPaginator;
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

#[Route('/admin/electricity-tariff-zones')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminElectricityTariffZoneController extends AbstractController
{
    #[Route(name: 'app_admin_electricity_tariff_zone_index', methods: ['GET'])]
    public function index(
        Request $request,
        ElectricityTariffZoneRepository $tariffZoneRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $sort = $tariffZoneRepository->normalizeSort($request->query->getString('sort', ElectricityTariffZoneRepository::SORT_SORT_ORDER));
        $direction = $tariffZoneRepository->normalizeSortDirection($request->query->getString('dir', ElectricityTariffZoneRepository::SORT_ASC));
        $pagination = $paginator->paginate(
            $tariffZoneRepository->createActiveByWorkspaceForAdminListQuery($workspaceContext->requireCurrentWorkspace(), $sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_electricity_tariff_zone/index.html.twig', [
            'electricity_tariff_zones' => $pagination->getItems(),
            'pagination' => $pagination,
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_electricity_tariff_zone_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, ElectricityTariffZoneRepository $tariffZoneRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $tariffZone = new ElectricityTariffZone($workspace);
        $tariffZone->setCreatedBy($this->getCurrentUser());

        $form = $this->createForm(ElectricityTariffZoneType::class, $tariffZone);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateUniqueCode($form, $tariffZone, $tariffZoneRepository, $workspaceContext)) {
                $entityManager->persist($tariffZone);
                $auditLogger->record(
                    action: 'electricity_tariff_zone.created',
                    workspace: $workspace,
                    entityTable: 'electricity_tariff_zones',
                    entityUuid: $tariffZone->getUuid(),
                    newValues: $this->tariffZoneAuditValues($tariffZone),
                    changedFields: ['code', 'name', 'description', 'sort_order'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Тарифная зона %s создана.', $tariffZone->getName()));

                return $this->redirectToRoute('app_admin_electricity_tariff_zone_show', ['uuid' => $tariffZone->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_tariff_zone/new.html.twig', [
                'electricity_tariff_zone' => $tariffZone,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_tariff_zone/new.html.twig', [
            'electricity_tariff_zone' => $tariffZone,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}', name: 'app_admin_electricity_tariff_zone_show', methods: ['GET'])]
    public function show(string $uuid, ElectricityTariffZoneRepository $tariffZoneRepository, WorkspaceContext $workspaceContext): Response
    {
        return $this->render('admin_electricity_tariff_zone/show.html.twig', [
            'electricity_tariff_zone' => $this->findActiveTariffZone($uuid, $tariffZoneRepository, $workspaceContext),
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_admin_electricity_tariff_zone_edit', methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request, EntityManagerInterface $entityManager, ElectricityTariffZoneRepository $tariffZoneRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $tariffZone = $this->findActiveTariffZone($uuid, $tariffZoneRepository, $workspaceContext);
        $oldValues = $this->tariffZoneAuditValues($tariffZone);
        $form = $this->createForm(ElectricityTariffZoneType::class, $tariffZone);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateUniqueCode($form, $tariffZone, $tariffZoneRepository, $workspaceContext)) {
                $tariffZone->touch($this->getCurrentUser());
                $auditLogger->record(
                    action: 'electricity_tariff_zone.updated',
                    workspace: $tariffZone->getWorkspace(),
                    entityTable: 'electricity_tariff_zones',
                    entityUuid: $tariffZone->getUuid(),
                    oldValues: $oldValues,
                    newValues: $this->tariffZoneAuditValues($tariffZone),
                    changedFields: ['code', 'name', 'description', 'sort_order'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Тарифная зона %s сохранена.', $tariffZone->getName()));

                return $this->redirectToRoute('app_admin_electricity_tariff_zone_show', ['uuid' => $tariffZone->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_tariff_zone/edit.html.twig', [
                'electricity_tariff_zone' => $tariffZone,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_tariff_zone/edit.html.twig', [
            'electricity_tariff_zone' => $tariffZone,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}/delete', name: 'app_admin_electricity_tariff_zone_delete', methods: ['POST'])]
    public function delete(string $uuid, Request $request, EntityManagerInterface $entityManager, ElectricityTariffZoneRepository $tariffZoneRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $tariffZone = $this->findActiveTariffZone($uuid, $tariffZoneRepository, $workspaceContext);
        $oldValues = $this->tariffZoneAuditValues($tariffZone);

        if ($this->isCsrfTokenValid('delete'.$tariffZone->getUuid(), $request->getPayload()->getString('_token'))) {
            $tariffZone->delete($this->getCurrentUser());
            $auditLogger->record(
                action: 'electricity_tariff_zone.deleted',
                workspace: $tariffZone->getWorkspace(),
                entityTable: 'electricity_tariff_zones',
                entityUuid: $tariffZone->getUuid(),
                oldValues: $oldValues,
                newValues: $this->tariffZoneAuditValues($tariffZone),
                changedFields: ['deleted_at', 'deleted_by'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Тарифная зона %s удалена.', $tariffZone->getName()));
        }

        return $this->redirectToRoute('app_admin_electricity_tariff_zone_index', [], Response::HTTP_SEE_OTHER);
    }

    private function findActiveTariffZone(string $uuid, ElectricityTariffZoneRepository $tariffZoneRepository, WorkspaceContext $workspaceContext): ElectricityTariffZone
    {
        try {
            $tariffZoneUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Electricity tariff zone was not found.');
        }

        $tariffZone = $tariffZoneRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $tariffZoneUuid,
        );

        if (!$tariffZone instanceof ElectricityTariffZone) {
            throw new NotFoundHttpException('Electricity tariff zone was not found.');
        }

        return $tariffZone;
    }

    private function validateUniqueCode(FormInterface $form, ElectricityTariffZone $tariffZone, ElectricityTariffZoneRepository $tariffZoneRepository, WorkspaceContext $workspaceContext): bool
    {
        $existingTariffZone = $tariffZoneRepository->findOneActiveByWorkspaceAndCode(
            $workspaceContext->requireCurrentWorkspace(),
            $tariffZone->getCode(),
        );

        if ($existingTariffZone instanceof ElectricityTariffZone && $existingTariffZone->getUuid()->toRfc4122() !== $tariffZone->getUuid()->toRfc4122()) {
            $form->get('code')->addError(new FormError('Активная тарифная зона с таким кодом уже существует.'));

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function tariffZoneAuditValues(ElectricityTariffZone $tariffZone): array
    {
        return [
            'code' => $tariffZone->getCode(),
            'name' => $tariffZone->getName(),
            'description' => $tariffZone->getDescription(),
            'sort_order' => $tariffZone->getSortOrder(),
            'deleted_at' => $tariffZone->getDeletedAt()?->format(DATE_ATOM),
        ];
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
