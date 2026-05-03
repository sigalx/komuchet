<?php

namespace App\Controller;

use App\Entity\ElectricityConsumptionBand;
use App\Entity\User;
use App\Form\ElectricityConsumptionBandType;
use App\Pagination\AdminPaginator;
use App\Repository\ElectricityConsumptionBandRepository;
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

#[Route('/admin/electricity-consumption-bands')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminElectricityConsumptionBandController extends AbstractController
{
    #[Route(name: 'app_admin_electricity_consumption_band_index', methods: ['GET'])]
    public function index(
        Request $request,
        ElectricityConsumptionBandRepository $consumptionBandRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $sort = $consumptionBandRepository->normalizeSort($request->query->getString('sort', ElectricityConsumptionBandRepository::SORT_SORT_ORDER));
        $direction = $consumptionBandRepository->normalizeSortDirection($request->query->getString('dir', ElectricityConsumptionBandRepository::SORT_ASC));
        $pagination = $paginator->paginate(
            $consumptionBandRepository->createActiveByWorkspaceForAdminListQuery($workspaceContext->requireCurrentWorkspace(), $sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_electricity_consumption_band/index.html.twig', [
            'electricity_consumption_bands' => $pagination->getItems(),
            'pagination' => $pagination,
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_electricity_consumption_band_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, ElectricityConsumptionBandRepository $consumptionBandRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $consumptionBand = new ElectricityConsumptionBand($workspace);
        $consumptionBand->setCreatedBy($this->getCurrentUser());

        $form = $this->createForm(ElectricityConsumptionBandType::class, $consumptionBand);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateUniqueCode($form, $consumptionBand, $consumptionBandRepository, $workspaceContext)) {
                $entityManager->persist($consumptionBand);
                $auditLogger->record(
                    action: 'electricity_consumption_band.created',
                    workspace: $workspace,
                    entityTable: 'electricity_consumption_bands',
                    entityUuid: $consumptionBand->getUuid(),
                    newValues: $this->consumptionBandAuditValues($consumptionBand),
                    changedFields: ['code', 'name', 'description', 'sort_order'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Диапазон потребления %s создан.', $consumptionBand->getName()));

                return $this->redirectToRoute('app_admin_electricity_consumption_band_show', ['uuid' => $consumptionBand->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_consumption_band/new.html.twig', [
                'electricity_consumption_band' => $consumptionBand,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_consumption_band/new.html.twig', [
            'electricity_consumption_band' => $consumptionBand,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}', name: 'app_admin_electricity_consumption_band_show', methods: ['GET'])]
    public function show(string $uuid, ElectricityConsumptionBandRepository $consumptionBandRepository, WorkspaceContext $workspaceContext): Response
    {
        return $this->render('admin_electricity_consumption_band/show.html.twig', [
            'electricity_consumption_band' => $this->findActiveConsumptionBand($uuid, $consumptionBandRepository, $workspaceContext),
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_admin_electricity_consumption_band_edit', methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request, EntityManagerInterface $entityManager, ElectricityConsumptionBandRepository $consumptionBandRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $consumptionBand = $this->findActiveConsumptionBand($uuid, $consumptionBandRepository, $workspaceContext);
        $oldValues = $this->consumptionBandAuditValues($consumptionBand);
        $form = $this->createForm(ElectricityConsumptionBandType::class, $consumptionBand);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateUniqueCode($form, $consumptionBand, $consumptionBandRepository, $workspaceContext)) {
                $consumptionBand->touch($this->getCurrentUser());
                $auditLogger->record(
                    action: 'electricity_consumption_band.updated',
                    workspace: $consumptionBand->getWorkspace(),
                    entityTable: 'electricity_consumption_bands',
                    entityUuid: $consumptionBand->getUuid(),
                    oldValues: $oldValues,
                    newValues: $this->consumptionBandAuditValues($consumptionBand),
                    changedFields: ['code', 'name', 'description', 'sort_order'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Диапазон потребления %s сохранен.', $consumptionBand->getName()));

                return $this->redirectToRoute('app_admin_electricity_consumption_band_show', ['uuid' => $consumptionBand->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_consumption_band/edit.html.twig', [
                'electricity_consumption_band' => $consumptionBand,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_consumption_band/edit.html.twig', [
            'electricity_consumption_band' => $consumptionBand,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}/delete', name: 'app_admin_electricity_consumption_band_delete', methods: ['POST'])]
    public function delete(string $uuid, Request $request, EntityManagerInterface $entityManager, ElectricityConsumptionBandRepository $consumptionBandRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $consumptionBand = $this->findActiveConsumptionBand($uuid, $consumptionBandRepository, $workspaceContext);
        $oldValues = $this->consumptionBandAuditValues($consumptionBand);

        if ($this->isCsrfTokenValid('delete'.$consumptionBand->getUuid(), $request->getPayload()->getString('_token'))) {
            $consumptionBand->delete($this->getCurrentUser());
            $auditLogger->record(
                action: 'electricity_consumption_band.deleted',
                workspace: $consumptionBand->getWorkspace(),
                entityTable: 'electricity_consumption_bands',
                entityUuid: $consumptionBand->getUuid(),
                oldValues: $oldValues,
                newValues: $this->consumptionBandAuditValues($consumptionBand),
                changedFields: ['deleted_at', 'deleted_by'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Диапазон потребления %s удален.', $consumptionBand->getName()));
        }

        return $this->redirectToRoute('app_admin_electricity_consumption_band_index', [], Response::HTTP_SEE_OTHER);
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

    private function validateUniqueCode(FormInterface $form, ElectricityConsumptionBand $consumptionBand, ElectricityConsumptionBandRepository $consumptionBandRepository, WorkspaceContext $workspaceContext): bool
    {
        $existingConsumptionBand = $consumptionBandRepository->findOneActiveByWorkspaceAndCode(
            $workspaceContext->requireCurrentWorkspace(),
            $consumptionBand->getCode(),
        );

        if ($existingConsumptionBand instanceof ElectricityConsumptionBand && $existingConsumptionBand->getUuid()->toRfc4122() !== $consumptionBand->getUuid()->toRfc4122()) {
            $form->get('code')->addError(new FormError('Активный диапазон потребления с таким кодом уже существует.'));

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function consumptionBandAuditValues(ElectricityConsumptionBand $consumptionBand): array
    {
        return [
            'code' => $consumptionBand->getCode(),
            'name' => $consumptionBand->getName(),
            'description' => $consumptionBand->getDescription(),
            'sort_order' => $consumptionBand->getSortOrder(),
            'deleted_at' => $consumptionBand->getDeletedAt()?->format(DATE_ATOM),
        ];
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
