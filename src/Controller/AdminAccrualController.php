<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\Accrual;
use App\Entity\User;
use App\Enum\AccrualType as AccrualTypeEnum;
use App\Form\AccrualCancelType;
use App\Form\AccrualType;
use App\Repository\AccountRepository;
use App\Repository\AccrualRepository;
use App\Repository\ElectricityAccrualContextRepository;
use App\Repository\ElectricityAccrualLineRepository;
use App\Repository\ElectricityAccrualRegisterRepository;
use App\Pagination\AdminPaginator;
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

#[IsGranted('WORKSPACE_ACCESS')]
final class AdminAccrualController extends AbstractController
{
    #[Route('/admin/accruals', name: 'app_admin_accrual_index', methods: ['GET'])]
    public function index(Request $request, AccrualRepository $accrualRepository, WorkspaceContext $workspaceContext, AdminPaginator $paginator): Response
    {
        $search = trim($request->query->getString('q'));
        $typeFilter = trim($request->query->getString('type'));
        $type = $accrualRepository->normalizeTypeFilter($typeFilter);
        $statusFilter = $accrualRepository->normalizeStatusFilter($request->query->getString('status', AccrualRepository::STATUS_FILTER_ALL));
        $periodStartFrom = trim($request->query->getString('period_start_from'));
        $periodStartTo = trim($request->query->getString('period_start_to'));
        $sort = $accrualRepository->normalizeSort($request->query->getString('sort', AccrualRepository::SORT_PERIOD_START));
        $direction = $accrualRepository->normalizeSortDirection($request->query->getString('dir', AccrualRepository::SORT_DESC));
        $pagination = $paginator->paginate(
            $accrualRepository->createByWorkspaceForAdminListQuery(
                $workspaceContext->requireCurrentWorkspace(),
                $search,
                $type,
                $statusFilter,
                $this->parseDate($periodStartFrom),
                $this->parseDate($periodStartTo),
                $sort,
                $direction,
            ),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_accrual/index.html.twig', [
            'accruals' => $pagination->getItems(),
            'pagination' => $pagination,
            'accrual_types' => AccrualTypeEnum::cases(),
            'filters' => [
                'q' => $search,
                'type' => $type?->value ?? '',
                'status' => $statusFilter,
                'period_start_from' => $periodStartFrom,
                'period_start_to' => $periodStartTo,
            ],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/admin/accounts/{accountUuid}/accruals/new', name: 'app_admin_accrual_new', methods: ['GET', 'POST'])]
    public function new(
        string $accountUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
        AccrualRepository $accrualRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($accountUuid, $accountRepository, $workspaceContext);
        $accrual = new Accrual($workspace, $account, AccrualTypeEnum::Other, null, null, '0', $this->getCurrentUser());
        $form = $this->createForm(AccrualType::class, $accrual);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateManualAccrualForm($form, $accrual, $accrualRepository);

            if ($form->isValid()) {
                $accrual->post($this->getCurrentUser());
                $entityManager->persist($accrual);
                $auditLogger->record(
                    action: 'accrual.created',
                    workspace: $workspace,
                    entityTable: 'accruals',
                    entityUuid: $accrual->getUuid(),
                    newValues: [
                        'account_uuid' => $account->getUuid()->toRfc4122(),
                        'account_number' => $account->getNumber(),
                        'type' => $accrual->getType()->value,
                        'amount' => $accrual->getAmount(),
                        'period_start' => $accrual->getPeriodStart()->format('Y-m-d'),
                        'period_end' => $accrual->getPeriodEnd()->format('Y-m-d'),
                        'posted_at' => $accrual->getPostedAt()?->format(DATE_ATOM),
                        'notes' => $accrual->getNotes(),
                    ],
                    changedFields: ['type', 'amount', 'period_start', 'period_end', 'posted_at', 'notes'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Начисление %.2f руб. внесено по участку %s.', (float) $accrual->getAmount(), $account->getNumber()));

                return $this->redirectToRoute('app_admin_accrual_show', ['uuid' => $accrual->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_accrual/new.html.twig', [
                'accrual' => $accrual,
                'account' => $account,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_accrual/new.html.twig', [
            'accrual' => $accrual,
            'account' => $account,
            'form' => $form,
        ]);
    }

    #[Route('/admin/accruals/{uuid}', name: 'app_admin_accrual_show', methods: ['GET'])]
    public function show(
        string $uuid,
        AccrualRepository $accrualRepository,
        ElectricityAccrualContextRepository $electricityContextRepository,
        ElectricityAccrualRegisterRepository $electricityRegisterRepository,
        ElectricityAccrualLineRepository $electricityLineRepository,
        WorkspaceContext $workspaceContext,
    ): Response {
        $accrual = $this->findAccrual($uuid, $accrualRepository, $workspaceContext);

        return $this->renderShow($accrual, $workspaceContext, $electricityContextRepository, $electricityRegisterRepository, $electricityLineRepository);
    }

    #[Route('/admin/accruals/{uuid}/cancel', name: 'app_admin_accrual_cancel', methods: ['POST'])]
    public function cancel(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccrualRepository $accrualRepository,
        ElectricityAccrualContextRepository $electricityContextRepository,
        ElectricityAccrualRegisterRepository $electricityRegisterRepository,
        ElectricityAccrualLineRepository $electricityLineRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $accrual = $this->findAccrual($uuid, $accrualRepository, $workspaceContext);
        $form = $this->createCancelForm($accrual);
        $form->handleRequest($request);

        if (!$accrual->isActivePosted()) {
            $this->addFlash('warning', 'Это начисление уже не является активным posted-начислением.');

            return $this->redirectToRoute('app_admin_accrual_show', ['uuid' => $accrual->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $reason = (string) $form->get('reason')->getData();
            $oldValues = [
                'amount' => $accrual->getAmount(),
                'posted_at' => $accrual->getPostedAt()?->format(DATE_ATOM),
                'cancelled_at' => null,
                'cancellation_reason' => null,
            ];
            $accrual->cancel($reason, $this->getCurrentUser());
            $auditLogger->record(
                action: 'accrual.cancelled',
                workspace: $accrual->getWorkspace(),
                entityTable: 'accruals',
                entityUuid: $accrual->getUuid(),
                oldValues: $oldValues,
                newValues: [
                    'cancelled_at' => $accrual->getCancelledAt()?->format(DATE_ATOM),
                    'cancellation_reason' => $accrual->getCancellationReason(),
                ],
                changedFields: ['cancelled_at', 'cancelled_by', 'cancellation_reason'],
                reason: $reason,
            );
            $entityManager->flush();
            $this->addFlash('success', 'Начисление отменено.');

            return $this->redirectToRoute('app_admin_accrual_show', ['uuid' => $accrual->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderShow(
            $accrual,
            $workspaceContext,
            $electricityContextRepository,
            $electricityRegisterRepository,
            $electricityLineRepository,
            $form,
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    private function validateManualAccrualForm(FormInterface $form, Accrual $accrual, AccrualRepository $accrualRepository): void
    {
        if (!$form->isValid()) {
            return;
        }

        if ($accrual->getPeriodEnd() <= $accrual->getPeriodStart()) {
            $form->get('periodEnd')->addError(new FormError('Конец периода должен быть позже начала периода.'));

            return;
        }

        $workspace = $accrual->getWorkspace();
        $account = $accrual->getAccount();

        if (!$workspace || !$account) {
            return;
        }

        $existingAccrual = $accrualRepository->findOneActivePostedByAccountTypeAndPeriod(
            $workspace,
            $account,
            $accrual->getType(),
            $accrual->getPeriodStart(),
            $accrual->getPeriodEnd(),
        );

        if ($existingAccrual instanceof Accrual) {
            $form->get('type')->addError(new FormError('Активное posted-начисление такого типа за этот период уже существует.'));
        }
    }

    private function createCancelForm(Accrual $accrual): FormInterface
    {
        return $this->createForm(AccrualCancelType::class, null, [
            'action' => $this->generateUrl('app_admin_accrual_cancel', ['uuid' => $accrual->getUuid()]),
        ]);
    }

    private function renderShow(
        Accrual $accrual,
        WorkspaceContext $workspaceContext,
        ElectricityAccrualContextRepository $electricityContextRepository,
        ElectricityAccrualRegisterRepository $electricityRegisterRepository,
        ElectricityAccrualLineRepository $electricityLineRepository,
        ?FormInterface $cancelForm = null,
        int $statusCode = Response::HTTP_OK,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();

        return $this->render('admin_accrual/show.html.twig', [
            'accrual' => $accrual,
            'electricity_context' => $electricityContextRepository->findOneByAccrual($workspace, $accrual),
            'electricity_registers' => $electricityRegisterRepository->findByAccrual($workspace, $accrual),
            'electricity_lines' => $electricityLineRepository->findByAccrual($workspace, $accrual),
            'cancel_form' => ($cancelForm ?? $this->createCancelForm($accrual))->createView(),
        ], new Response(status: $statusCode));
    }

    private function findAccrual(string $uuid, AccrualRepository $accrualRepository, WorkspaceContext $workspaceContext): Accrual
    {
        try {
            $accrualUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Accrual was not found.');
        }

        $accrual = $accrualRepository->findOneByWorkspaceAndUuid($workspaceContext->requireCurrentWorkspace(), $accrualUuid);

        if (!$accrual instanceof Accrual) {
            throw new NotFoundHttpException('Accrual was not found.');
        }

        return $accrual;
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
