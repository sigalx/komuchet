<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\Workspace;
use App\Form\WorkspaceType;
use App\Pagination\AdminPaginator;
use App\Repository\WorkspaceRepository;
use App\Service\AuditLogger;
use App\Service\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

final class AdminWorkspaceController extends AbstractController
{
    #[Route('/admin/workspaces', name: 'app_admin_workspace_index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(
        Request $request,
        WorkspaceRepository $workspaceRepository,
        AdminPaginator $paginator,
    ): Response {
        $sort = $workspaceRepository->normalizeSort($request->query->getString('sort', WorkspaceRepository::SORT_CODE));
        $direction = $workspaceRepository->normalizeSortDirection($request->query->getString('dir', WorkspaceRepository::SORT_ASC));
        $pagination = $paginator->paginate(
            $workspaceRepository->createForAdminListQuery($sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_workspace/index.html.twig', [
            'workspaces' => $pagination->getItems(),
            'pagination' => $pagination,
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/admin/workspaces/new', name: 'app_admin_workspace_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        WorkspaceRepository $workspaceRepository,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = new Workspace();
        $workspace->setCreatedBy($this->getCurrentUser());
        $workspace->touch($this->getCurrentUser());

        $form = $this->createForm(WorkspaceType::class, $workspace);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateUniqueCode($workspace, $workspaceRepository, $form->get('code'));

            if ($form->isValid()) {
                $entityManager->persist($workspace);
                $auditLogger->record(
                    action: 'workspace.created',
                    workspace: $workspace,
                    entityTable: 'workspaces',
                    entityUuid: $workspace->getUuid(),
                    newValues: $this->workspaceAuditValues($workspace),
                    changedFields: ['code', 'name', 'description', 'timezone'],
                    reason: 'Хозяйство создано из админки.',
                );
                $entityManager->flush();

                $this->addFlash('success', sprintf('Хозяйство %s создано.', $workspace->getName()));

                return $this->redirectToRoute('app_admin_workspace_show', ['uuid' => $workspace->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_workspace/new.html.twig', [
                'workspace' => $workspace,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_workspace/new.html.twig', [
            'workspace' => $workspace,
            'form' => $form,
        ]);
    }

    #[Route('/admin/workspaces/{uuid}', name: 'app_admin_workspace_show', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function show(string $uuid, WorkspaceRepository $workspaceRepository): Response
    {
        return $this->render('admin_workspace/show.html.twig', [
            'workspace' => $this->findWorkspace($uuid, $workspaceRepository),
        ]);
    }

    #[Route('/admin/workspaces/{uuid}/edit', name: 'app_admin_workspace_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function edit(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        WorkspaceRepository $workspaceRepository,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $this->findWorkspace($uuid, $workspaceRepository);
        $oldValues = $this->workspaceAuditValues($workspace);
        $form = $this->createForm(WorkspaceType::class, $workspace);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateUniqueCode($workspace, $workspaceRepository, $form->get('code'));

            if ($form->isValid()) {
                $workspace->touch($this->getCurrentUser());
                $auditLogger->record(
                    action: 'workspace.updated',
                    workspace: $workspace,
                    entityTable: 'workspaces',
                    entityUuid: $workspace->getUuid(),
                    oldValues: $oldValues,
                    newValues: $this->workspaceAuditValues($workspace),
                    changedFields: ['code', 'name', 'description', 'timezone'],
                    reason: 'Хозяйство изменено из админки.',
                );
                $entityManager->flush();

                $this->addFlash('success', sprintf('Хозяйство %s сохранено.', $workspace->getName()));

                return $this->redirectToRoute('app_admin_workspace_show', ['uuid' => $workspace->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_workspace/edit.html.twig', [
                'workspace' => $workspace,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_workspace/edit.html.twig', [
            'workspace' => $workspace,
            'form' => $form,
        ]);
    }

    #[Route('/admin/workspaces/switch', name: 'app_admin_workspace_switch', methods: ['POST'])]
    #[IsGranted('WORKSPACE_ACCESS')]
    public function switch(
        Request $request,
        WorkspaceRepository $workspaceRepository,
        WorkspaceContext $workspaceContext,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid('switch_workspace', $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $workspace = $this->findWorkspace($request->getPayload()->getString('workspace_uuid'), $workspaceRepository);

        if (!$workspaceContext->isWorkspaceAvailable($workspace)) {
            throw $this->createAccessDeniedException('Workspace is not available for current user.');
        }

        $workspaceContext->switchCurrentWorkspace($workspace);
        $this->addFlash('success', sprintf('Текущее хозяйство: %s (%s).', $workspace->getName(), $workspace->getCode()));

        return $this->redirectToRoute('app_admin', [], Response::HTTP_SEE_OTHER);
    }

    private function validateUniqueCode(Workspace $workspace, WorkspaceRepository $workspaceRepository, FormInterface $codeField): void
    {
        $workspaceWithSameCode = $workspaceRepository->findOneBy(['code' => $workspace->getCode()]);

        if (!$workspaceWithSameCode instanceof Workspace || $workspaceWithSameCode->getUuid()->equals($workspace->getUuid())) {
            return;
        }

        $codeField->addError(new FormError('Хозяйство с таким кодом уже существует.'));
    }

    private function findWorkspace(string $workspaceUuid, WorkspaceRepository $workspaceRepository): Workspace
    {
        try {
            $uuid = Uuid::fromString($workspaceUuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Workspace was not found.');
        }

        $workspace = $workspaceRepository->find($uuid);

        if (!$workspace instanceof Workspace) {
            throw new NotFoundHttpException('Workspace was not found.');
        }

        return $workspace;
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceAuditValues(Workspace $workspace): array
    {
        return [
            'uuid' => $workspace->getUuid()->toRfc4122(),
            'code' => $workspace->getCode(),
            'name' => $workspace->getName(),
            'description' => $workspace->getDescription(),
            'timezone' => $workspace->getTimezone(),
        ];
    }
}
