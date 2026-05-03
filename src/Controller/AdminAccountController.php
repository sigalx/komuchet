<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\AccountElectricityTariffProfileAssignment;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementSnapshot;
use App\Entity\ElectricityTariffProfile;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\SubscriberAccountAccessRole;
use App\Form\AccountElectricityTariffProfileAssignType;
use App\Form\AccountStatementCancelType;
use App\Form\AccountSubscriberAccessGrantType;
use App\Form\AccountType;
use App\Repository\AccountElectricityTariffProfileAssignmentRepository;
use App\Repository\AccountRepository;
use App\Repository\AccountStatementAccrualSnapshotRepository;
use App\Repository\AccountStatementDeliveryRepository;
use App\Repository\AccountStatementElectricityLineSnapshotRepository;
use App\Repository\AccountStatementElectricityRegisterSnapshotRepository;
use App\Repository\AccountStatementPaymentSnapshotRepository;
use App\Repository\AccountStatementSnapshotRepository;
use App\Repository\AccrualRepository;
use App\Repository\ElectricityMeterReadingRepository;
use App\Repository\ElectricityMeterRepository;
use App\Repository\ElectricityTariffProfileRepository;
use App\Repository\PaymentRepository;
use App\Repository\SubscriberAccountAccessRepository;
use App\Repository\SubscriberRepository;
use App\Pagination\AdminPaginator;
use App\Service\AccountBalanceCalculator;
use App\Service\AccountStatementDeliveryEnqueuer;
use App\Service\AccountStatementPdfRenderer;
use App\Service\AccountStatementPaymentQrCodeGenerator;
use App\Service\AccountStatementSnapshotGenerator;
use App\Service\AccountStatementProvider;
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
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/accounts')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminAccountController extends AbstractController
{
    #[Route(name: 'app_admin_account_index', methods: ['GET'])]
    public function index(Request $request, AccountRepository $accountRepository, WorkspaceContext $workspaceContext, AdminPaginator $paginator): Response
    {
        $search = trim($request->query->getString('q'));
        $accessFilter = $accountRepository->normalizeAccessFilter($request->query->getString('access', AccountRepository::ACCESS_FILTER_ALL));
        $sort = $accountRepository->normalizeSort($request->query->getString('sort', AccountRepository::SORT_NUMBER));
        $direction = $accountRepository->normalizeSortDirection($request->query->getString('dir', AccountRepository::SORT_ASC));
        $pagination = $paginator->paginate(
            $accountRepository->createActiveByWorkspaceForAdminListQuery($workspaceContext->requireCurrentWorkspace(), $search, $accessFilter, $sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_account/index.html.twig', [
            'accounts' => $pagination->getItems(),
            'pagination' => $pagination,
            'filters' => [
                'q' => $search,
                'access' => $accessFilter,
            ],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_account_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, AccountRepository $accountRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = new Account($workspace);
        $account->setCreatedBy($this->getCurrentUser());

        $form = $this->createForm(AccountType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $existingAccount = $accountRepository->findOneActiveByWorkspaceAndNumber($workspace, $account->getNumber());

            if ($existingAccount instanceof Account) {
                $form->get('number')->addError(new \Symfony\Component\Form\FormError('Активный участок с таким номером уже существует.'));

                return $this->render('admin_account/new.html.twig', [
                    'account' => $account,
                    'form' => $form,
                ]);
            }

            $entityManager->persist($account);
            $auditLogger->record(
                action: 'account.created',
                workspace: $workspace,
                entityTable: 'accounts',
                entityUuid: $account->getUuid(),
                newValues: $this->accountAuditValues($account),
                changedFields: ['number', 'notes'],
            );
            $entityManager->flush();

            $this->addFlash('success', sprintf('Участок %s создан.', $account->getNumber()));

            return $this->redirectToRoute('app_admin_account_show', ['uuid' => $account->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_account/new.html.twig', [
            'account' => $account,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}', name: 'app_admin_account_show', methods: ['GET'])]
    public function show(
        string $uuid,
        AccountRepository $accountRepository,
        SubscriberAccountAccessRepository $accessRepository,
        SubscriberRepository $subscriberRepository,
        AccountElectricityTariffProfileAssignmentRepository $tariffProfileAssignmentRepository,
        ElectricityTariffProfileRepository $tariffProfileRepository,
        AccrualRepository $accrualRepository,
        PaymentRepository $paymentRepository,
        ElectricityMeterRepository $electricityMeterRepository,
        ElectricityMeterReadingRepository $electricityMeterReadingRepository,
        AccountBalanceCalculator $accountBalanceCalculator,
        WorkspaceContext $workspaceContext,
    ): Response {
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);

        return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext);
    }

    #[Route('/{uuid}/statement', name: 'app_admin_account_statement', methods: ['GET'])]
    public function statement(
        string $uuid,
        AccountRepository $accountRepository,
        AccountStatementSnapshotRepository $statementRepository,
        AccountStatementProvider $statementProvider,
        WorkspaceContext $workspaceContext,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);

        return $this->render('admin_account/statement.html.twig', [
            'statement' => $statementProvider->buildCurrent($workspace, $account, $this->todayInWorkspace($workspace)),
            'statement_snapshots' => $statementRepository->findLatestByAccount($workspace, $account),
        ]);
    }

    #[Route('/{uuid}/statements', name: 'app_admin_account_statement_snapshot_create', methods: ['POST'])]
    public function createStatementSnapshot(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
        AccountStatementSnapshotGenerator $snapshotGenerator,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);

        if (!$this->isCsrfTokenValid('create_statement_snapshot'.$account->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $snapshot = $snapshotGenerator->generateCurrent($workspace, $account, $this->todayInWorkspace($workspace), $this->getCurrentUser());
        $auditLogger->record(
            action: 'account_statement.generated',
            workspace: $workspace,
            entityTable: 'account_statements',
            entityUuid: $snapshot->getUuid(),
            newValues: $this->statementSnapshotAuditValues($snapshot),
            changedFields: [
                'account_uuid',
                'number',
                'statement_date',
                'active_accrual_total',
                'active_payment_total',
                'balance_amount',
                'amount_to_pay',
                'overpayment_amount',
            ],
        );
        $entityManager->flush();
        $this->addFlash('success', sprintf('Квитанция № %s зафиксирована.', $snapshot->getNumber()));

        return $this->redirectToRoute('app_admin_account_statement_snapshot_show', [
            'uuid' => $account->getUuid(),
            'statementUuid' => $snapshot->getUuid(),
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/statements/{statementUuid}', name: 'app_admin_account_statement_snapshot_show', methods: ['GET'])]
    public function statementSnapshot(
        string $uuid,
        string $statementUuid,
        AccountRepository $accountRepository,
        AccountStatementSnapshotRepository $statementRepository,
        AccountStatementAccrualSnapshotRepository $statementAccrualRepository,
        AccountStatementElectricityRegisterSnapshotRepository $statementElectricityRegisterRepository,
        AccountStatementElectricityLineSnapshotRepository $statementElectricityLineRepository,
        AccountStatementPaymentSnapshotRepository $statementPaymentRepository,
        AccountStatementDeliveryRepository $statementDeliveryRepository,
        AccountStatementPaymentQrCodeGenerator $paymentQrCodeGenerator,
        WorkspaceContext $workspaceContext,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);
        $snapshot = $this->findStatementSnapshot($statementUuid, $workspace, $account, $statementRepository);

        return $this->renderStatementSnapshot(
            $snapshot,
            $workspace,
            $statementAccrualRepository,
            $statementElectricityRegisterRepository,
            $statementElectricityLineRepository,
            $statementPaymentRepository,
            $statementDeliveryRepository,
            $paymentQrCodeGenerator,
        );
    }

    #[Route('/{uuid}/statements/{statementUuid}/cancel', name: 'app_admin_account_statement_snapshot_cancel', methods: ['POST'])]
    public function cancelStatementSnapshot(
        string $uuid,
        string $statementUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
        AccountStatementSnapshotRepository $statementRepository,
        AccountStatementAccrualSnapshotRepository $statementAccrualRepository,
        AccountStatementElectricityRegisterSnapshotRepository $statementElectricityRegisterRepository,
        AccountStatementElectricityLineSnapshotRepository $statementElectricityLineRepository,
        AccountStatementPaymentSnapshotRepository $statementPaymentRepository,
        AccountStatementDeliveryRepository $statementDeliveryRepository,
        AccountStatementPaymentQrCodeGenerator $paymentQrCodeGenerator,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);
        $snapshot = $this->findStatementSnapshot($statementUuid, $workspace, $account, $statementRepository);
        $form = $this->createStatementCancelForm($snapshot);
        $form->handleRequest($request);

        if ($snapshot->isCancelled()) {
            $this->addFlash('warning', 'Квитанция уже отменена.');

            return $this->redirectToRoute('app_admin_account_statement_snapshot_show', [
                'uuid' => $account->getUuid(),
                'statementUuid' => $snapshot->getUuid(),
            ], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $reason = (string) $form->get('reason')->getData();
            $oldValues = $this->statementSnapshotAuditValues($snapshot);

            $snapshot->cancel($reason, $this->getCurrentUser());
            $auditLogger->record(
                action: 'account_statement.cancelled',
                workspace: $workspace,
                entityTable: 'account_statements',
                entityUuid: $snapshot->getUuid(),
                oldValues: $oldValues,
                newValues: $this->statementSnapshotAuditValues($snapshot),
                changedFields: ['cancelled_at', 'cancelled_by', 'cancellation_reason'],
                reason: $reason,
            );

            $cancelledDeliveryCount = 0;

            foreach ($statementDeliveryRepository->findActiveByStatement($workspace, $snapshot) as $delivery) {
                $deliveryOldValues = $this->statementDeliveryAuditValues($delivery);
                $delivery->cancel($reason, $this->getCurrentUser());
                ++$cancelledDeliveryCount;

                $auditLogger->record(
                    action: 'account_statement_delivery.cancelled',
                    workspace: $workspace,
                    entityTable: 'account_statement_deliveries',
                    entityUuid: $delivery->getUuid(),
                    oldValues: $deliveryOldValues,
                    newValues: $this->statementDeliveryAuditValues($delivery),
                    changedFields: ['cancelled_at', 'cancelled_by', 'cancellation_reason'],
                    reason: $reason,
                );
            }

            $entityManager->flush();
            $this->addFlash('success', sprintf('Квитанция № %s отменена. Активных доставок отменено: %d.', $snapshot->getNumber(), $cancelledDeliveryCount));

            return $this->redirectToRoute('app_admin_account_statement_snapshot_show', [
                'uuid' => $account->getUuid(),
                'statementUuid' => $snapshot->getUuid(),
            ], Response::HTTP_SEE_OTHER);
        }

        return $this->renderStatementSnapshot(
            $snapshot,
            $workspace,
            $statementAccrualRepository,
            $statementElectricityRegisterRepository,
            $statementElectricityLineRepository,
            $statementPaymentRepository,
            $statementDeliveryRepository,
            $paymentQrCodeGenerator,
            $form,
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    #[Route('/{uuid}/statements/{statementUuid}/deliveries/enqueue', name: 'app_admin_account_statement_delivery_enqueue', methods: ['POST'])]
    public function enqueueStatementDelivery(
        string $uuid,
        string $statementUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
        AccountStatementSnapshotRepository $statementRepository,
        AccountStatementDeliveryEnqueuer $deliveryEnqueuer,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);
        $snapshot = $this->findStatementSnapshot($statementUuid, $workspace, $account, $statementRepository);

        if (!$this->isCsrfTokenValid('enqueue_statement_delivery'.$snapshot->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if ($snapshot->isCancelled()) {
            $this->addFlash('warning', 'Отмененную квитанцию нельзя поставить в очередь отправки.');

            return $this->redirectToRoute('app_admin_account_statement_snapshot_show', [
                'uuid' => $account->getUuid(),
                'statementUuid' => $snapshot->getUuid(),
            ], Response::HTTP_SEE_OTHER);
        }

        $result = $deliveryEnqueuer->enqueueForActiveAccountSubscribers($workspace, $snapshot, $this->getCurrentUser());

        foreach ($result->createdDeliveries as $delivery) {
            $auditLogger->record(
                action: 'account_statement_delivery.queued',
                workspace: $workspace,
                entityTable: 'account_statement_deliveries',
                entityUuid: $delivery->getUuid(),
                newValues: $this->statementDeliveryAuditValues($delivery),
                changedFields: [
                    'account_statement_uuid',
                    'recipient_subscriber_uuid',
                    'channel',
                    'recipient_email',
                    'recipient_name',
                    'created_at',
                ],
            );
        }

        $entityManager->flush();

        if ($result->createdCount() > 0) {
            $this->addFlash('success', sprintf('В очередь отправки поставлено: %d.', $result->createdCount()));
        }

        if ($result->skippedWithoutEmailCount() > 0) {
            $this->addFlash('warning', sprintf('Пропущено без email: %d.', $result->skippedWithoutEmailCount()));
        }

        if ($result->skippedExistingCount() > 0) {
            $this->addFlash('info', sprintf('Уже есть активные отправки: %d.', $result->skippedExistingCount()));
        }

        if ($result->createdCount() === 0 && $result->skippedWithoutEmailCount() === 0 && $result->skippedExistingCount() === 0) {
            $this->addFlash('warning', 'У участка нет активных абонентов для отправки квитанции.');
        }

        return $this->redirectToRoute('app_admin_account_statement_snapshot_show', [
            'uuid' => $account->getUuid(),
            'statementUuid' => $snapshot->getUuid(),
        ], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/statements/{statementUuid}/print', name: 'app_admin_account_statement_snapshot_print', methods: ['GET'])]
    public function printStatementSnapshot(
        string $uuid,
        string $statementUuid,
        AccountRepository $accountRepository,
        AccountStatementSnapshotRepository $statementRepository,
        AccountStatementAccrualSnapshotRepository $statementAccrualRepository,
        AccountStatementElectricityRegisterSnapshotRepository $statementElectricityRegisterRepository,
        AccountStatementElectricityLineSnapshotRepository $statementElectricityLineRepository,
        AccountStatementPaymentSnapshotRepository $statementPaymentRepository,
        AccountStatementPaymentQrCodeGenerator $paymentQrCodeGenerator,
        WorkspaceContext $workspaceContext,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);
        $snapshot = $this->findStatementSnapshot($statementUuid, $workspace, $account, $statementRepository);

        return $this->render('account_statement/print.html.twig', [
            'statement' => $snapshot,
            'accruals' => $statementAccrualRepository->findByStatement($workspace, $snapshot),
            'electricity_registers' => $statementElectricityRegisterRepository->findByStatement($workspace, $snapshot),
            'electricity_lines' => $statementElectricityLineRepository->findByStatement($workspace, $snapshot),
            'payments' => $statementPaymentRepository->findByStatement($workspace, $snapshot),
            'payment_qr_code' => $paymentQrCodeGenerator->generate($snapshot),
        ]);
    }

    #[Route('/{uuid}/statements/{statementUuid}/pdf', name: 'app_admin_account_statement_snapshot_pdf', methods: ['GET'])]
    public function pdfStatementSnapshot(
        string $uuid,
        string $statementUuid,
        AccountRepository $accountRepository,
        AccountStatementSnapshotRepository $statementRepository,
        AccountStatementAccrualSnapshotRepository $statementAccrualRepository,
        AccountStatementElectricityRegisterSnapshotRepository $statementElectricityRegisterRepository,
        AccountStatementElectricityLineSnapshotRepository $statementElectricityLineRepository,
        AccountStatementPaymentSnapshotRepository $statementPaymentRepository,
        AccountStatementPaymentQrCodeGenerator $paymentQrCodeGenerator,
        AccountStatementPdfRenderer $pdfRenderer,
        WorkspaceContext $workspaceContext,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);
        $snapshot = $this->findStatementSnapshot($statementUuid, $workspace, $account, $statementRepository);
        $pdf = $pdfRenderer->render([
            'statement' => $snapshot,
            'accruals' => $statementAccrualRepository->findByStatement($workspace, $snapshot),
            'electricity_registers' => $statementElectricityRegisterRepository->findByStatement($workspace, $snapshot),
            'electricity_lines' => $statementElectricityLineRepository->findByStatement($workspace, $snapshot),
            'payments' => $statementPaymentRepository->findByStatement($workspace, $snapshot),
            'payment_qr_code' => $paymentQrCodeGenerator->generate($snapshot),
        ]);
        $filename = sprintf('statement-%s.pdf', preg_replace('/[^A-Za-z0-9._-]+/', '-', $snapshot->getNumber()));
        $disposition = (new ResponseHeaderBag())->makeDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $filename);

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
        ]);
    }

    #[Route('/{uuid}/edit', name: 'app_admin_account_edit', methods: ['GET', 'POST'])]
    public function edit(string $uuid, Request $request, EntityManagerInterface $entityManager, AccountRepository $accountRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);
        $originalNumber = $account->getNumber();
        $oldValues = $this->accountAuditValues($account);
        $form = $this->createForm(AccountType::class, $account);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $existingAccount = $accountRepository->findOneActiveByWorkspaceAndNumber($workspace, $account->getNumber());

            if ($existingAccount instanceof Account && $existingAccount->getUuid()->toRfc4122() !== $account->getUuid()->toRfc4122()) {
                $form->get('number')->addError(new \Symfony\Component\Form\FormError('Активный участок с таким номером уже существует.'));

                return $this->render('admin_account/edit.html.twig', [
                    'account' => $account,
                    'form' => $form,
                ]);
            }

            if ($originalNumber !== $account->getNumber() || $form->get('notes')->isSubmitted()) {
                $account->touch($this->getCurrentUser());
            }

            $auditLogger->record(
                action: 'account.updated',
                workspace: $account->getWorkspace(),
                entityTable: 'accounts',
                entityUuid: $account->getUuid(),
                oldValues: $oldValues,
                newValues: $this->accountAuditValues($account),
                changedFields: ['number', 'notes'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Участок %s сохранен.', $account->getNumber()));

            return $this->redirectToRoute('app_admin_account_show', ['uuid' => $account->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->render('admin_account/edit.html.twig', [
            'account' => $account,
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}/delete', name: 'app_admin_account_delete', methods: ['POST'])]
    public function delete(string $uuid, Request $request, EntityManagerInterface $entityManager, AccountRepository $accountRepository, WorkspaceContext $workspaceContext, AuditLogger $auditLogger): Response
    {
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);
        $oldValues = $this->accountAuditValues($account);

        if ($this->isCsrfTokenValid('delete'.$account->getUuid(), $request->getPayload()->getString('_token'))) {
            $account->delete($this->getCurrentUser());
            $auditLogger->record(
                action: 'account.deleted',
                workspace: $account->getWorkspace(),
                entityTable: 'accounts',
                entityUuid: $account->getUuid(),
                oldValues: $oldValues,
                newValues: $this->accountAuditValues($account),
                changedFields: ['deleted_at', 'deleted_by'],
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Участок %s удален.', $account->getNumber()));
        }

        return $this->redirectToRoute('app_admin_account_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/accesses/grant', name: 'app_admin_account_access_grant', methods: ['POST'])]
    public function grantAccess(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
        SubscriberAccountAccessRepository $accessRepository,
        SubscriberRepository $subscriberRepository,
        AccountElectricityTariffProfileAssignmentRepository $tariffProfileAssignmentRepository,
        ElectricityTariffProfileRepository $tariffProfileRepository,
        AccrualRepository $accrualRepository,
        PaymentRepository $paymentRepository,
        ElectricityMeterRepository $electricityMeterRepository,
        ElectricityMeterReadingRepository $electricityMeterReadingRepository,
        AccountBalanceCalculator $accountBalanceCalculator,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);
        $form = $this->createAccessGrantForm($account, $accessRepository, $subscriberRepository, $workspaceContext);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $subscriber = $form->get('subscriber')->getData();
            $accessRole = $form->get('accessRole')->getData();

            if (!$subscriber instanceof Subscriber) {
                $form->get('subscriber')->addError(new FormError('Выберите абонента.'));

                return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext, $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (!$accessRole instanceof SubscriberAccountAccessRole) {
                $form->get('accessRole')->addError(new FormError('Выберите роль доступа.'));

                return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext, $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if ($accessRepository->findOneActiveBySubscriberAndAccount($workspace, $subscriber, $account) instanceof SubscriberAccountAccess) {
                $form->get('subscriber')->addError(new FormError('У абонента уже есть активный доступ к этому участку.'));

                return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext, $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $access = (new SubscriberAccountAccess($workspace, $subscriber, $account, $accessRole, $this->getCurrentUser()))
                ->setNotes($form->get('notes')->getData());

            $entityManager->persist($access);
            $auditLogger->record(
                action: 'subscriber_account_access.granted',
                workspace: $workspace,
                entityTable: 'subscriber_account_accesses',
                entityPk: $this->subscriberAccountAccessAuditPk($access),
                newValues: $this->subscriberAccountAccessAuditValues($access),
                changedFields: ['subscriber_uuid', 'account_uuid', 'access_role', 'granted_at', 'notes'],
                reason: 'Доступ выдан через карточку участка.',
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Абонент %s добавлен к участку.', $subscriber->getDisplayName()));

            return $this->redirectToRoute('app_admin_account_show', ['uuid' => $account->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext, $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/{uuid}/accesses/{subscriberUuid}/revoke', name: 'app_admin_account_access_revoke', methods: ['POST'])]
    public function revokeAccess(
        string $uuid,
        string $subscriberUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
        SubscriberAccountAccessRepository $accessRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);
        $subscriber = $this->findActiveSubscriber($subscriberUuid, $subscriberRepository, $workspaceContext);
        $access = $accessRepository->findOneActiveBySubscriberAndAccount($workspace, $subscriber, $account);

        if (!$access instanceof SubscriberAccountAccess) {
            throw new NotFoundHttpException('Subscriber account access was not found.');
        }

        if ($this->isCsrfTokenValid('revoke_account_access'.$account->getUuid().$subscriber->getUuid(), $request->getPayload()->getString('_token'))) {
            $oldValues = $this->subscriberAccountAccessAuditValues($access);
            $access->revoke('Доступ отозван администратором через карточку участка.', $this->getCurrentUser());
            $auditLogger->record(
                action: 'subscriber_account_access.revoked',
                workspace: $workspace,
                entityTable: 'subscriber_account_accesses',
                entityPk: $this->subscriberAccountAccessAuditPk($access),
                oldValues: $oldValues,
                newValues: $this->subscriberAccountAccessAuditValues($access),
                changedFields: ['revoked_at', 'revoked_by', 'revoked_reason'],
                reason: $access->getRevokedReason(),
            );
            $entityManager->flush();
            $this->addFlash('success', sprintf('Доступ абонента %s отозван.', $subscriber->getDisplayName()));
        }

        return $this->redirectToRoute('app_admin_account_show', ['uuid' => $account->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{uuid}/tariff-profile-assignments/assign', name: 'app_admin_account_tariff_profile_assign', methods: ['POST'])]
    public function assignTariffProfile(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        AccountRepository $accountRepository,
        SubscriberAccountAccessRepository $accessRepository,
        SubscriberRepository $subscriberRepository,
        AccountElectricityTariffProfileAssignmentRepository $tariffProfileAssignmentRepository,
        ElectricityTariffProfileRepository $tariffProfileRepository,
        AccrualRepository $accrualRepository,
        PaymentRepository $paymentRepository,
        ElectricityMeterRepository $electricityMeterRepository,
        ElectricityMeterReadingRepository $electricityMeterReadingRepository,
        AccountBalanceCalculator $accountBalanceCalculator,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $account = $this->findActiveAccount($uuid, $accountRepository, $workspaceContext);
        $form = $this->createTariffProfileAssignForm($account, $tariffProfileRepository, $workspaceContext);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $tariffProfile = $form->get('tariffProfile')->getData();
            $validFrom = $form->get('validFrom')->getData();

            if (!$tariffProfile instanceof ElectricityTariffProfile) {
                $form->get('tariffProfile')->addError(new FormError('Выберите тарифный профиль.'));

                return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext, tariffProfileAssignForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            if (!$validFrom instanceof DateTimeImmutable) {
                $form->get('validFrom')->addError(new FormError('Укажите дату начала.'));

                return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext, tariffProfileAssignForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $openAssignment = $tariffProfileAssignmentRepository->findOneOpenEndedByAccount($workspace, $account);
            $openAssignmentOldValues = null;

            if ($openAssignment instanceof AccountElectricityTariffProfileAssignment) {
                if ($openAssignment->getTariffProfile()?->getUuid()->toRfc4122() === $tariffProfile->getUuid()->toRfc4122()) {
                    $form->get('tariffProfile')->addError(new FormError('Этот тарифный профиль уже назначен участку.'));

                    return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext, tariffProfileAssignForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                if ($validFrom <= $openAssignment->getValidFrom()) {
                    $form->get('validFrom')->addError(new FormError('Дата начала нового профиля должна быть позже даты начала текущего назначения.'));

                    return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext, tariffProfileAssignForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
                }

                $openAssignmentOldValues = $this->tariffProfileAssignmentAuditValues($openAssignment);
                $openAssignment->setValidTo($validFrom);
            } else {
                $latestAssignment = $tariffProfileAssignmentRepository->findLatestByAccount($workspace, $account);

                if (
                    $latestAssignment instanceof AccountElectricityTariffProfileAssignment
                    && $latestAssignment->getValidTo() !== null
                    && $validFrom < $latestAssignment->getValidTo()
                ) {
                    $form->get('validFrom')->addError(new FormError('Дата начала нового профиля пересекается с существующим назначением.'));

                    return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext, tariffProfileAssignForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
                }
            }

            $assignment = (new AccountElectricityTariffProfileAssignment($workspace, $account, $tariffProfile, $validFrom, $this->getCurrentUser()))
                ->setNotes($form->get('notes')->getData());

            if ($openAssignment instanceof AccountElectricityTariffProfileAssignment) {
                $auditLogger->record(
                    action: 'account_tariff_profile_assignment.closed',
                    workspace: $workspace,
                    entityTable: 'account_electricity_tariff_profile_assignments',
                    entityPk: $this->tariffProfileAssignmentAuditPk($openAssignment),
                    oldValues: $openAssignmentOldValues,
                    newValues: $this->tariffProfileAssignmentAuditValues($openAssignment),
                    changedFields: ['valid_to'],
                    reason: 'Назначен новый тарифный профиль участку.',
                );
            }

            $auditLogger->record(
                action: 'account_tariff_profile_assignment.created',
                workspace: $workspace,
                entityTable: 'account_electricity_tariff_profile_assignments',
                entityPk: $this->tariffProfileAssignmentAuditPk($assignment),
                newValues: $this->tariffProfileAssignmentAuditValues($assignment),
                changedFields: ['account_uuid', 'tariff_profile_uuid', 'valid_from', 'valid_to', 'notes'],
                reason: 'Тарифный профиль назначен через карточку участка.',
            );

            $entityManager->wrapInTransaction(static function (EntityManagerInterface $entityManager) use ($openAssignment, $assignment): void {
                if ($openAssignment instanceof AccountElectricityTariffProfileAssignment) {
                    $entityManager->flush();
                }

                $entityManager->persist($assignment);
                $entityManager->flush();
            });
            $this->addFlash('success', sprintf('Тарифный профиль %s назначен участку %s.', $tariffProfile->getName(), $account->getNumber()));

            return $this->redirectToRoute('app_admin_account_show', ['uuid' => $account->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderAccountShow($account, $accessRepository, $subscriberRepository, $tariffProfileAssignmentRepository, $tariffProfileRepository, $accrualRepository, $paymentRepository, $electricityMeterRepository, $electricityMeterReadingRepository, $accountBalanceCalculator, $workspaceContext, tariffProfileAssignForm: $form, status: Response::HTTP_UNPROCESSABLE_ENTITY);
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

    private function findActiveSubscriber(string $uuid, SubscriberRepository $subscriberRepository, WorkspaceContext $workspaceContext): Subscriber
    {
        try {
            $subscriberUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Subscriber was not found.');
        }

        $subscriber = $subscriberRepository->findOneActiveByWorkspaceAndUuid(
            $workspaceContext->requireCurrentWorkspace(),
            $subscriberUuid,
        );

        if (!$subscriber instanceof Subscriber) {
            throw new NotFoundHttpException('Subscriber was not found.');
        }

        return $subscriber;
    }

    private function findStatementSnapshot(
        string $uuid,
        Workspace $workspace,
        Account $account,
        AccountStatementSnapshotRepository $statementRepository,
    ): AccountStatementSnapshot {
        try {
            $statementUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Account statement was not found.');
        }

        $statement = $statementRepository->findOneByWorkspaceAndUuid($workspace, $statementUuid);

        if (
            !$statement instanceof AccountStatementSnapshot
            || $statement->getAccount()?->getUuid()->toRfc4122() !== $account->getUuid()->toRfc4122()
        ) {
            throw new NotFoundHttpException('Account statement was not found.');
        }

        return $statement;
    }

    private function renderAccountShow(
        Account $account,
        SubscriberAccountAccessRepository $accessRepository,
        SubscriberRepository $subscriberRepository,
        AccountElectricityTariffProfileAssignmentRepository $tariffProfileAssignmentRepository,
        ElectricityTariffProfileRepository $tariffProfileRepository,
        AccrualRepository $accrualRepository,
        PaymentRepository $paymentRepository,
        ElectricityMeterRepository $electricityMeterRepository,
        ElectricityMeterReadingRepository $electricityMeterReadingRepository,
        AccountBalanceCalculator $accountBalanceCalculator,
        WorkspaceContext $workspaceContext,
        ?FormInterface $accessGrantForm = null,
        ?FormInterface $tariffProfileAssignForm = null,
        int $status = Response::HTTP_OK,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $activeAccesses = $accessRepository->findActiveByAccount($workspace, $account);
        $tariffProfileAssignments = $tariffProfileAssignmentRepository->findByAccount($workspace, $account);
        $accruals = $accrualRepository->findByAccount($workspace, $account);
        $payments = $paymentRepository->findByAccount($workspace, $account);
        $electricityMeters = $electricityMeterRepository->findNonDeletedByWorkspaceAndAccount($workspace, $account);
        $latestReadingsByMeter = [];

        foreach ($electricityMeters as $electricityMeter) {
            $latestReadingsByMeter[$electricityMeter->getUuid()->toRfc4122()] = $electricityMeterReadingRepository->findLatestActiveIndexedByTariffZone($workspace, $electricityMeter);
        }

        $balanceSummary = $accountBalanceCalculator->calculate($workspace, $account);
        $accessGrantForm ??= $this->createAccessGrantForm($account, $accessRepository, $subscriberRepository, $workspaceContext);
        $tariffProfileAssignForm ??= $this->createTariffProfileAssignForm($account, $tariffProfileRepository, $workspaceContext);

        return $this->render('admin_account/show.html.twig', [
            'account' => $account,
            'active_accesses' => $activeAccesses,
            'access_grant_form' => $accessGrantForm,
            'tariff_profile_assignments' => $tariffProfileAssignments,
            'tariff_profile_assign_form' => $tariffProfileAssignForm,
            'accruals' => $accruals,
            'payments' => $payments,
            'electricity_meters' => $electricityMeters,
            'latest_electricity_meter_readings_by_meter' => $latestReadingsByMeter,
            'balance_summary' => $balanceSummary,
        ], new Response(status: $status));
    }

    private function renderStatementSnapshot(
        AccountStatementSnapshot $snapshot,
        Workspace $workspace,
        AccountStatementAccrualSnapshotRepository $statementAccrualRepository,
        AccountStatementElectricityRegisterSnapshotRepository $statementElectricityRegisterRepository,
        AccountStatementElectricityLineSnapshotRepository $statementElectricityLineRepository,
        AccountStatementPaymentSnapshotRepository $statementPaymentRepository,
        AccountStatementDeliveryRepository $statementDeliveryRepository,
        AccountStatementPaymentQrCodeGenerator $paymentQrCodeGenerator,
        ?FormInterface $cancelForm = null,
        int $status = Response::HTTP_OK,
    ): Response {
        return $this->render('admin_account/statement_snapshot.html.twig', [
            'statement' => $snapshot,
            'accruals' => $statementAccrualRepository->findByStatement($workspace, $snapshot),
            'electricity_registers' => $statementElectricityRegisterRepository->findByStatement($workspace, $snapshot),
            'electricity_lines' => $statementElectricityLineRepository->findByStatement($workspace, $snapshot),
            'payments' => $statementPaymentRepository->findByStatement($workspace, $snapshot),
            'deliveries' => $statementDeliveryRepository->findByStatement($workspace, $snapshot),
            'payment_qr_code' => $paymentQrCodeGenerator->generate($snapshot),
            'cancel_form' => ($cancelForm ?? $this->createStatementCancelForm($snapshot))->createView(),
        ], new Response(status: $status));
    }

    private function createAccessGrantForm(
        Account $account,
        SubscriberAccountAccessRepository $accessRepository,
        SubscriberRepository $subscriberRepository,
        WorkspaceContext $workspaceContext,
    ): FormInterface {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $activeAccesses = $accessRepository->findActiveByAccount($workspace, $account);
        $activeSubscriberUuids = [];

        foreach ($activeAccesses as $access) {
            $subscriber = $access->getSubscriber();

            if ($subscriber instanceof Subscriber) {
                $activeSubscriberUuids[$subscriber->getUuid()->toRfc4122()] = true;
            }
        }

        $availableSubscribers = array_values(array_filter(
            $subscriberRepository->findActiveByWorkspace($workspace),
            static fn (Subscriber $subscriber): bool => !isset($activeSubscriberUuids[$subscriber->getUuid()->toRfc4122()]),
        ));

        return $this->createForm(AccountSubscriberAccessGrantType::class, null, [
            'active_subscribers' => $availableSubscribers,
            'action' => $this->generateUrl('app_admin_account_access_grant', ['uuid' => $account->getUuid()]),
        ]);
    }

    private function createTariffProfileAssignForm(
        Account $account,
        ElectricityTariffProfileRepository $tariffProfileRepository,
        WorkspaceContext $workspaceContext,
    ): FormInterface {
        $workspace = $workspaceContext->requireCurrentWorkspace();

        return $this->createForm(AccountElectricityTariffProfileAssignType::class, null, [
            'active_tariff_profiles' => $tariffProfileRepository->findActiveByWorkspace($workspace),
            'action' => $this->generateUrl('app_admin_account_tariff_profile_assign', ['uuid' => $account->getUuid()]),
        ]);
    }

    private function createStatementCancelForm(AccountStatementSnapshot $statement): FormInterface
    {
        $account = $statement->getAccount();

        if (!$account instanceof Account) {
            throw new \LogicException('Statement has no account.');
        }

        return $this->createForm(AccountStatementCancelType::class, null, [
            'action' => $this->generateUrl('app_admin_account_statement_snapshot_cancel', [
                'uuid' => $account->getUuid(),
                'statementUuid' => $statement->getUuid(),
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function accountAuditValues(Account $account): array
    {
        return [
            'number' => $account->getNumber(),
            'notes' => $account->getNotes(),
            'deleted_at' => $account->getDeletedAt()?->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function subscriberAccountAccessAuditPk(SubscriberAccountAccess $access): array
    {
        return [
            'workspace_uuid' => $access->getWorkspace()?->getUuid()->toRfc4122(),
            'subscriber_uuid' => $access->getSubscriber()?->getUuid()->toRfc4122(),
            'account_uuid' => $access->getAccount()?->getUuid()->toRfc4122(),
            'granted_at' => $access->getGrantedAt()->format(DATE_ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subscriberAccountAccessAuditValues(SubscriberAccountAccess $access): array
    {
        return [
            ...$this->subscriberAccountAccessAuditPk($access),
            'account_number' => $access->getAccount()?->getNumber(),
            'subscriber_name' => $access->getSubscriber()?->getDisplayName(),
            'access_role' => $access->getAccessRole()->value,
            'notes' => $access->getNotes(),
            'revoked_at' => $access->getRevokedAt()?->format(DATE_ATOM),
            'revoked_reason' => $access->getRevokedReason(),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function tariffProfileAssignmentAuditPk(AccountElectricityTariffProfileAssignment $assignment): array
    {
        return [
            'workspace_uuid' => $assignment->getWorkspace()?->getUuid()->toRfc4122(),
            'account_uuid' => $assignment->getAccount()?->getUuid()->toRfc4122(),
            'valid_from' => $assignment->getValidFrom()->format('Y-m-d'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tariffProfileAssignmentAuditValues(AccountElectricityTariffProfileAssignment $assignment): array
    {
        return [
            ...$this->tariffProfileAssignmentAuditPk($assignment),
            'account_number' => $assignment->getAccount()?->getNumber(),
            'tariff_profile_uuid' => $assignment->getTariffProfile()?->getUuid()->toRfc4122(),
            'tariff_profile_code' => $assignment->getTariffProfile()?->getCode(),
            'valid_to' => $assignment->getValidTo()?->format('Y-m-d'),
            'assigned_at' => $assignment->getAssignedAt()->format(DATE_ATOM),
            'notes' => $assignment->getNotes(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statementSnapshotAuditValues(AccountStatementSnapshot $statement): array
    {
        return [
            'workspace_uuid' => $statement->getWorkspace()?->getUuid()->toRfc4122(),
            'account_uuid' => $statement->getAccount()?->getUuid()->toRfc4122(),
            'account_number' => $statement->getAccountNumber(),
            'number' => $statement->getNumber(),
            'statement_date' => $statement->getStatementDate()->format('Y-m-d'),
            'generated_at' => $statement->getGeneratedAt()->format(DATE_ATOM),
            'active_accrual_total' => $statement->getActiveAccrualTotal(),
            'active_payment_total' => $statement->getActivePaymentTotal(),
            'balance_amount' => $statement->getBalanceAmount(),
            'amount_to_pay' => $statement->getAmountToPay(),
            'overpayment_amount' => $statement->getOverpaymentAmount(),
            'cancelled_at' => $statement->getCancelledAt()?->format(DATE_ATOM),
            'cancelled_by' => $statement->getCancelledBy()?->getUuid()->toRfc4122(),
            'cancellation_reason' => $statement->getCancellationReason(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function statementDeliveryAuditValues(AccountStatementDelivery $delivery): array
    {
        return [
            'workspace_uuid' => $delivery->getWorkspace()?->getUuid()->toRfc4122(),
            'account_statement_uuid' => $delivery->getAccountStatement()?->getUuid()->toRfc4122(),
            'account_statement_number' => $delivery->getAccountStatement()?->getNumber(),
            'recipient_subscriber_uuid' => $delivery->getRecipientSubscriber()?->getUuid()->toRfc4122(),
            'recipient_subscriber_name' => $delivery->getRecipientSubscriber()?->getDisplayName(),
            'channel' => $delivery->getChannel()->value,
            'recipient_email' => $delivery->getRecipientEmail(),
            'recipient_name' => $delivery->getRecipientName(),
            'created_at' => $delivery->getCreatedAt()->format(DATE_ATOM),
            'cancelled_at' => $delivery->getCancelledAt()?->format(DATE_ATOM),
            'cancelled_by' => $delivery->getCancelledBy()?->getUuid()->toRfc4122(),
            'cancellation_reason' => $delivery->getCancellationReason(),
        ];
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    private function todayInWorkspace(Workspace $workspace): DateTimeImmutable
    {
        try {
            $timezone = new DateTimeZone($workspace->getTimezone());
        } catch (\Throwable) {
            $timezone = new DateTimeZone('Europe/Moscow');
        }

        return new DateTimeImmutable('today', $timezone);
    }
}
