<?php

namespace App\Controller;

use App\Entity\ElectricityTariffProfile;
use App\Entity\User;
use App\Form\ElectricityTariffProfileType;
use App\Pagination\AdminPaginator;
use App\Repository\ElectricityTariffPeriodRepository;
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

#[Route('/admin/electricity-tariff-profiles')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminElectricityTariffProfileController extends AbstractController
{
    #[Route(name: 'app_admin_electricity_tariff_profile_index', methods: ['GET'])]
    public function index(
        Request $request,
        ElectricityTariffProfileRepository $tariffProfileRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $sort = $tariffProfileRepository->normalizeSort($request->query->getString('sort', ElectricityTariffProfileRepository::SORT_NAME));
        $direction = $tariffProfileRepository->normalizeSortDirection($request->query->getString('dir', ElectricityTariffProfileRepository::SORT_ASC));
        $pagination = $paginator->paginate(
            $tariffProfileRepository->createActiveByWorkspaceForAdminListQuery($workspaceContext->requireCurrentWorkspace(), $sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_electricity_tariff_profile/index.html.twig', [
            'electricity_tariff_profiles' => $pagination->getItems(),
            'pagination' => $pagination,
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_electricity_tariff_profile_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, ElectricityTariffProfileRepository $tariffProfileRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $tariffProfile = new ElectricityTariffProfile($workspace);
        $tariffProfile->setCreatedBy($this->getCurrentUser());

        $form = $this->createForm(ElectricityTariffProfileType::class, $tariffProfile);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateUniqueCode($form, $tariffProfile, $tariffProfileRepository, $workspaceContext)) {
                $entityManager->persist($tariffProfile);
                $auditLogger->record(
                    action: 'electricity_tariff_profile.created',
                    workspace: $workspace,
                    entityTable: 'electricity_tariff_profiles',
                    entityUuid: $tariffProfile->getUuid(),
                    newValues: $this->tariffProfileAuditValues($tariffProfile),
                    changedFields: ['code', 'name', 'description'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Тарифный профиль %s создан.', $tariffProfile->getName()));

                return $this->redirectToRoute('app_admin_electricity_tariff_profile_show', ['uuid' => $tariffProfile->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_tariff_profile/new.html.twig', [
                'electricity_tariff_profile' => $tariffProfile,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_tariff_profile/new.html.twig', [
            'electricity_tariff_profile' => $tariffProfile,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}', name: 'app_admin_electricity_tariff_profile_show', methods: ['GET'])]
    public function show(
        string $uuid,
        ElectricityTariffProfileRepository $tariffProfileRepository,
        ElectricityTariffPeriodRepository $tariffPeriodRepository,
        WorkspaceContext $workspaceContext,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $tariffProfile = $this->findActiveTariffProfile($uuid, $tariffProfileRepository, $workspaceContext);

        return $this->render('admin_electricity_tariff_profile/show.html.twig', [
            'electricity_tariff_profile' => $tariffProfile,
            'tariff_periods' => $tariffPeriodRepository->findActiveByProfile($workspace, $tariffProfile),
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_admin_electricity_tariff_profile_edit', methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request, EntityManagerInterface $entityManager, ElectricityTariffProfileRepository $tariffProfileRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $tariffProfile = $this->findActiveTariffProfile($uuid, $tariffProfileRepository, $workspaceContext);
        $oldValues = $this->tariffProfileAuditValues($tariffProfile);
        $form = $this->createForm(ElectricityTariffProfileType::class, $tariffProfile);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateUniqueCode($form, $tariffProfile, $tariffProfileRepository, $workspaceContext)) {
                $tariffProfile->touch($this->getCurrentUser());
                $auditLogger->record(
                    action: 'electricity_tariff_profile.updated',
                    workspace: $tariffProfile->getWorkspace(),
                    entityTable: 'electricity_tariff_profiles',
                    entityUuid: $tariffProfile->getUuid(),
                    oldValues: $oldValues,
                    newValues: $this->tariffProfileAuditValues($tariffProfile),
                    changedFields: ['code', 'name', 'description'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Тарифный профиль %s сохранен.', $tariffProfile->getName()));

                return $this->redirectToRoute('app_admin_electricity_tariff_profile_show', ['uuid' => $tariffProfile->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_electricity_tariff_profile/edit.html.twig', [
                'electricity_tariff_profile' => $tariffProfile,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_electricity_tariff_profile/edit.html.twig', [
            'electricity_tariff_profile' => $tariffProfile,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}/delete', name: 'app_admin_electricity_tariff_profile_delete', methods: ['POST'])]
    public function delete(string $uuid, Request $request, EntityManagerInterface $entityManager, ElectricityTariffProfileRepository $tariffProfileRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $tariffProfile = $this->findActiveTariffProfile($uuid, $tariffProfileRepository, $workspaceContext);
        $oldValues = $this->tariffProfileAuditValues($tariffProfile);

        if ($this->isCsrfTokenValid('delete'.$tariffProfile->getUuid(), $request->getPayload()->getString('_token'))) {
            $tariffProfile->delete($this->getCurrentUser());
            $auditLogger->record(
                action: 'electricity_tariff_profile.deleted',
                workspace: $tariffProfile->getWorkspace(),
                entityTable: 'electricity_tariff_profiles',
                entityUuid: $tariffProfile->getUuid(),
                oldValues: $oldValues,
                newValues: $this->tariffProfileAuditValues($tariffProfile),
                changedFields: ['deleted_at', 'deleted_by'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Тарифный профиль %s удален.', $tariffProfile->getName()));
        }

        return $this->redirectToRoute('app_admin_electricity_tariff_profile_index', [], Response::HTTP_SEE_OTHER);
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

    private function validateUniqueCode(FormInterface $form, ElectricityTariffProfile $tariffProfile, ElectricityTariffProfileRepository $tariffProfileRepository, WorkspaceContext $workspaceContext): bool
    {
        $existingTariffProfile = $tariffProfileRepository->findOneActiveByWorkspaceAndCode(
            $workspaceContext->requireCurrentWorkspace(),
            $tariffProfile->getCode(),
        );

        if ($existingTariffProfile instanceof ElectricityTariffProfile && $existingTariffProfile->getUuid()->toRfc4122() !== $tariffProfile->getUuid()->toRfc4122()) {
            $form->get('code')->addError(new FormError('Активный тарифный профиль с таким кодом уже существует.'));

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function tariffProfileAuditValues(ElectricityTariffProfile $tariffProfile): array
    {
        return [
            'code' => $tariffProfile->getCode(),
            'name' => $tariffProfile->getName(),
            'description' => $tariffProfile->getDescription(),
            'deleted_at' => $tariffProfile->getDeletedAt()?->format(DATE_ATOM),
        ];
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
