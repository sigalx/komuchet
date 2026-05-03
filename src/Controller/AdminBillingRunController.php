<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Accrual;
use App\Entity\BillingRun;
use App\Entity\BillingRunAccountIssue;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\BillingRunAccountIssueCloseReason;
use App\Enum\BillingRunAccountIssueType;
use App\Enum\BillingRunKind;
use App\Form\BillingRunAccountIssueCloseType;
use App\Form\BillingRunCancelType;
use App\Form\BillingRunType;
use App\Repository\AccountRepository;
use App\Repository\AccountStatementDeliveryRepository;
use App\Repository\AccountStatementSnapshotRepository;
use App\Repository\AccrualRepository;
use App\Repository\BillingRunAccountIssueRepository;
use App\Repository\BillingRunRepository;
use App\Pagination\AdminPaginator;
use App\Service\AuditLogger;
use App\Service\BillingRunStatementGenerator;
use App\Service\BillingRunIssueGenerator;
use App\Service\ElectricityBillingRunAccrualGenerator;
use App\Service\WorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
final class AdminBillingRunController extends AbstractController
{
    public function __construct(
        private readonly AccountStatementSnapshotRepository $statementRepository,
        private readonly AccountStatementDeliveryRepository $statementDeliveryRepository,
    ) {
    }

    #[Route('/admin/billing-runs', name: 'app_admin_billing_run_index', methods: ['GET'])]
    public function index(
        Request $request,
        BillingRunRepository $billingRunRepository,
        BillingRunAccountIssueRepository $issueRepository,
        AccrualRepository $accrualRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $kindFilter = trim($request->query->getString('kind'));
        $kind = $billingRunRepository->normalizeKindFilter($kindFilter);
        $statusFilter = $billingRunRepository->normalizeStatusFilter($request->query->getString('status', BillingRunRepository::STATUS_FILTER_ALL));
        $issueFilter = $billingRunRepository->normalizeIssueFilter($request->query->getString('issues', BillingRunRepository::ISSUE_FILTER_ALL));
        $accrualFilter = $billingRunRepository->normalizeAccrualFilter($request->query->getString('accruals', BillingRunRepository::ACCRUAL_FILTER_ALL));
        $periodStartFrom = trim($request->query->getString('period_start_from'));
        $periodStartTo = trim($request->query->getString('period_start_to'));
        $generatedAtFrom = trim($request->query->getString('generated_at_from'));
        $generatedAtTo = trim($request->query->getString('generated_at_to'));
        $sort = $billingRunRepository->normalizeSort($request->query->getString('sort', BillingRunRepository::SORT_PERIOD_START));
        $direction = $billingRunRepository->normalizeSortDirection($request->query->getString('dir', BillingRunRepository::SORT_DESC));
        $workspaceTimezone = $workspace->getTimezone();
        $pagination = $paginator->paginate(
            $billingRunRepository->createByWorkspaceForAdminListQuery(
                $workspace,
                $kind,
                $statusFilter,
                $issueFilter,
                $accrualFilter,
                $this->parseDate($periodStartFrom),
                $this->parseDate($periodStartTo),
                $this->parseTimestampFilterDate($generatedAtFrom, $workspaceTimezone),
                $this->parseTimestampFilterDate($generatedAtTo, $workspaceTimezone)?->modify('+1 day'),
                $sort,
                $direction,
            ),
            $request->query->getInt('page', 1),
        );
        $billingRuns = $pagination->getItems();
        $openIssueCounts = $issueRepository->countOpenByBillingRuns($workspace, $billingRuns);
        $accrualCounts = $accrualRepository->countByBillingRuns($workspace, $billingRuns);
        $expectedStatementCounts = $accrualRepository->countActivePostedAccountsByBillingRuns($workspace, $billingRuns);
        $activeStatementCounts = $this->statementRepository->countActiveByBillingRuns($workspace, $billingRuns);
        $latestIssueUpdatedAtByRun = $issueRepository->findLatestUpdatedAtByBillingRuns($workspace, $billingRuns);

        return $this->render('admin_billing_run/index.html.twig', [
            'pagination' => $pagination,
            'billing_run_rows' => array_map(function (BillingRun $billingRun) use ($openIssueCounts, $accrualCounts, $expectedStatementCounts, $activeStatementCounts, $latestIssueUpdatedAtByRun): array {
                $uuid = $billingRun->getUuid()->toRfc4122();
                $openIssueCount = $openIssueCounts[$uuid] ?? 0;
                $accrualCount = $accrualCounts[$uuid] ?? 0;
                $statementProgress = $this->buildBillingRunStatementProgress(
                    $expectedStatementCounts[$uuid] ?? 0,
                    $activeStatementCounts[$uuid] ?? 0,
                );
                $accrualGenerationOutdated = $this->isBillingRunAccrualGenerationOutdated(
                    $billingRun,
                    $latestIssueUpdatedAtByRun[$uuid] ?? null,
                    $accrualCount
                );

                return [
                    'billing_run' => $billingRun,
                    'open_issue_count' => $openIssueCount,
                    'accrual_count' => $accrualCount,
                    'statement_progress' => $statementProgress,
                    'next_action' => $this->buildBillingRunNextAction($billingRun, $openIssueCount, $accrualCount, $accrualGenerationOutdated, $statementProgress),
                ];
            }, $billingRuns),
            'billing_run_kinds' => BillingRunKind::cases(),
            'filters' => [
                'kind' => $kind?->value ?? '',
                'status' => $statusFilter,
                'issues' => $issueFilter,
                'accruals' => $accrualFilter,
                'period_start_from' => $periodStartFrom,
                'period_start_to' => $periodStartTo,
                'generated_at_from' => $generatedAtFrom,
                'generated_at_to' => $generatedAtTo,
            ],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/admin/billing-run-issues', name: 'app_admin_billing_run_issue_index', methods: ['GET'])]
    public function issueIndex(
        Request $request,
        BillingRunAccountIssueRepository $issueRepository,
        BillingRunRepository $billingRunRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        return $this->renderIssueIndex(
            $request,
            $issueRepository,
            $billingRunRepository,
            $accountRepository,
            $workspaceContext->requireCurrentWorkspace(),
            $paginator,
        );
    }

    #[Route('/admin/billing-run-issues/{issueUuid}/close', name: 'app_admin_billing_run_issue_index_close', methods: ['POST'])]
    public function closeIssueFromIndex(
        string $issueUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        BillingRunAccountIssueRepository $issueRepository,
        BillingRunRepository $billingRunRepository,
        AccountRepository $accountRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
        AdminPaginator $paginator,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $filters = $this->billingRunIssueFilterQuery($request);
        $issue = $this->findBillingRunIssueByWorkspace($issueUuid, $issueRepository, $workspace);
        $billingRun = $issue->getBillingRun();

        if (!$billingRun instanceof BillingRun || !$billingRun->isDraft()) {
            $this->addFlash('warning', 'Закрывать проблемы можно только в черновике расчета.');

            return $this->redirectToRoute('app_admin_billing_run_issue_index', $filters, Response::HTTP_SEE_OTHER);
        }

        if (!$issue->isOpen()) {
            $this->addFlash('warning', 'Проблема уже закрыта.');

            return $this->redirectToRoute('app_admin_billing_run_issue_index', $filters, Response::HTTP_SEE_OTHER);
        }

        $form = $this->createIssueIndexCloseForm($issue, $filters);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $reason = $form->get('reason')->getData();
            $comment = $form->get('comment')->getData();

            if (!$reason instanceof BillingRunAccountIssueCloseReason) {
                throw new \LogicException('Invalid issue close reason.');
            }

            $issue->close($reason, is_string($comment) ? $comment : null, $this->getCurrentUser());
            $auditLogger->record(
                action: 'billing_run_issue.closed',
                workspace: $workspace,
                entityTable: 'billing_run_account_issues',
                entityUuid: $issue->getUuid(),
                newValues: [
                    'billing_run_uuid' => $billingRun->getUuid()->toRfc4122(),
                    'account_uuid' => $issue->getAccount()?->getUuid()->toRfc4122(),
                    'issue_type' => $issue->getIssueType()->value,
                    'close_reason' => $issue->getCloseReason()?->value,
                    'close_comment' => $issue->getCloseComment(),
                    'closed_at' => $issue->getClosedAt()?->format(DATE_ATOM),
                ],
                changedFields: ['closed_at', 'closed_by', 'close_reason', 'close_comment'],
                reason: $issue->getCloseComment(),
            );
            $entityManager->flush();
            $this->addFlash('success', 'Проблема закрыта.');

            return $this->redirectToRoute('app_admin_billing_run_issue_index', $filters, Response::HTTP_SEE_OTHER);
        }

        return $this->renderIssueIndex(
            $request,
            $issueRepository,
            $billingRunRepository,
            $accountRepository,
            $workspace,
            $paginator,
            $form,
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    #[Route('/admin/billing-runs/new', name: 'app_admin_billing_run_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        BillingRunRepository $billingRunRepository,
        BillingRunIssueGenerator $issueGenerator,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $periodStart = new DateTimeImmutable('first day of previous month');
        $billingRun = new BillingRun(
            $workspace,
            BillingRunKind::Electricity,
            $periodStart,
            $periodStart->modify('+1 month'),
            $this->getCurrentUser(),
        );
        $form = $this->createForm(BillingRunType::class, $billingRun);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            $this->validateBillingRunForm($form, $billingRun, $billingRunRepository);

            if ($form->isValid()) {
                $entityManager->persist($billingRun);
                $issueResult = $issueGenerator->generateForDraft($billingRun, $this->getCurrentUser());
                $auditLogger->record(
                    action: 'billing_run.created',
                    workspace: $workspace,
                    entityTable: 'billing_runs',
                    entityUuid: $billingRun->getUuid(),
                    newValues: [
                        'kind' => $billingRun->getKind()->value,
                        'period_start' => $billingRun->getPeriodStart()->format('Y-m-d'),
                        'period_end' => $billingRun->getPeriodEnd()->format('Y-m-d'),
                        'generated_at' => $billingRun->getGeneratedAt()->format(DATE_ATOM),
                        'created_issues' => $issueResult->created,
                    ],
                    changedFields: ['kind', 'period_start', 'period_end', 'generated_at'],
                );
                $entityManager->flush();
                $this->addFlash('success', sprintf('Черновик расчета создан. Создано проблем: %d.', $issueResult->created));

                return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('admin_billing_run/new.html.twig', [
                'billing_run' => $billingRun,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_billing_run/new.html.twig', [
            'billing_run' => $billingRun,
            'form' => $form,
        ]);
    }

    #[Route('/admin/billing-runs/{uuid}', name: 'app_admin_billing_run_show', methods: ['GET'])]
    public function show(
        string $uuid,
        BillingRunRepository $billingRunRepository,
        BillingRunAccountIssueRepository $issueRepository,
        AccrualRepository $accrualRepository,
        WorkspaceContext $workspaceContext,
    ): Response
    {
        $billingRun = $this->findBillingRun($uuid, $billingRunRepository, $workspaceContext);
        $workspace = $workspaceContext->requireCurrentWorkspace();

        return $this->renderShow($billingRun, $workspace, $issueRepository, $accrualRepository);
    }

    #[Route('/admin/billing-runs/{uuid}/issues/recheck', name: 'app_admin_billing_run_recheck_issues', methods: ['POST'])]
    public function recheckIssues(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        BillingRunRepository $billingRunRepository,
        BillingRunIssueGenerator $issueGenerator,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $billingRun = $this->findBillingRun($uuid, $billingRunRepository, $workspaceContext);

        if (!$billingRun->isDraft()) {
            $this->addFlash('warning', 'Повторно проверить можно только черновик расчета.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if (!$this->isCsrfTokenValid('recheck_billing_run_issues'.$billingRun->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $result = $issueGenerator->generateForDraft($billingRun, $this->getCurrentUser());
        $auditLogger->record(
            action: 'billing_run.issues_rechecked',
            workspace: $billingRun->getWorkspace(),
            entityTable: 'billing_runs',
            entityUuid: $billingRun->getUuid(),
            newValues: [
                'created' => $result->created,
                'updated' => $result->updated,
                'closed' => $result->closed,
                'ignored' => $result->ignored,
            ],
        );
        $entityManager->flush();
        $this->addFlash('success', sprintf(
            'Повторная проверка выполнена. Создано: %d, обновлено: %d, закрыто: %d, проигнорировано ранее: %d.',
            $result->created,
            $result->updated,
            $result->closed,
            $result->ignored
        ));

        return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/billing-runs/{uuid}/generate-accruals', name: 'app_admin_billing_run_generate_accruals', methods: ['POST'])]
    public function generateAccruals(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        BillingRunRepository $billingRunRepository,
        ElectricityBillingRunAccrualGenerator $accrualGenerator,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $billingRun = $this->findBillingRun($uuid, $billingRunRepository, $workspaceContext);

        if (!$billingRun->isDraft()) {
            $this->addFlash('warning', 'Начисления можно генерировать только для черновика расчета.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if (!$this->isCsrfTokenValid('generate_billing_run_accruals'.$billingRun->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $result = $accrualGenerator->generateForDraft($billingRun, $this->getCurrentUser());
        $billingRun->markAccrualsGenerated($this->getCurrentUser());
        $auditLogger->record(
            action: 'billing_run.accruals_generated',
            workspace: $billingRun->getWorkspace(),
            entityTable: 'billing_runs',
            entityUuid: $billingRun->getUuid(),
            newValues: [
                'accruals_generated_at' => $billingRun->getAccrualsGeneratedAt()?->format(DATE_ATOM),
                'created' => $result->created,
                'skipped_open_issues' => $result->skippedOpenIssues,
                'skipped_existing' => $result->skippedExisting,
                'reused_posted' => $result->reusedPosted,
                'failed' => $result->failed,
                'skipped_ignored_calculation_errors' => $result->skippedIgnoredCalculationErrors,
            ],
            changedFields: ['accruals_generated_at', 'accruals_generated_by'],
        );
        $entityManager->flush();
        $this->addFlash('success', sprintf(
            'Генерация начислений выполнена. Создано: %d, пропущено с открытыми проблемами: %d, уже было: %d, использовано проведенных: %d, ошибок расчета: %d, проигнорированных ошибок расчета: %d.',
            $result->created,
            $result->skippedOpenIssues,
            $result->skippedExisting,
            $result->reusedPosted,
            $result->failed,
            $result->skippedIgnoredCalculationErrors
        ));

        return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/billing-runs/{uuid}/post', name: 'app_admin_billing_run_post', methods: ['POST'])]
    public function post(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        BillingRunRepository $billingRunRepository,
        BillingRunAccountIssueRepository $issueRepository,
        AccrualRepository $accrualRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $billingRun = $this->findBillingRun($uuid, $billingRunRepository, $workspaceContext);

        if (!$billingRun->isDraft()) {
            $this->addFlash('warning', 'Провести можно только черновик расчета.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if (!$this->isCsrfTokenValid('post_billing_run'.$billingRun->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $openIssueCount = $issueRepository->countOpenByBillingRun($workspace, $billingRun);

        if (0 < $openIssueCount) {
            $this->addFlash('warning', sprintf('Нельзя провести расчет: открытые проблемы: %d.', $openIssueCount));

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $accruals = $accrualRepository->findByBillingRun($workspace, $billingRun);

        if ($accruals === []) {
            $this->addFlash('warning', 'Нельзя провести расчет без начислений.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $accrualsGeneratedAt = $billingRun->getAccrualsGeneratedAt();

        if ($accrualsGeneratedAt === null) {
            $this->addFlash('warning', 'Нельзя провести расчет: начисления нужно сгенерировать перед проведением.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $latestIssueUpdatedAt = $issueRepository->findLatestUpdatedAtByBillingRun($workspace, $billingRun);

        if ($latestIssueUpdatedAt instanceof DateTimeImmutable && $latestIssueUpdatedAt > $accrualsGeneratedAt) {
            $this->addFlash('warning', 'Нельзя провести расчет: после последней генерации начислений менялись проблемы расчета. Запустите генерацию начислений повторно.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $existingPostedAccrualResolution = $this->adoptMatchingPostedAccruals(
            $workspace,
            $billingRun,
            $accruals,
            $accrualRepository,
            $this->getCurrentUser(),
        );

        if ($existingPostedAccrualResolution['blocked'] !== []) {
            $this->addFlash('warning', sprintf(
                'Нельзя провести расчет: найдены несовместимые уже проведенные начисления: %s.',
                implode('; ', array_slice($existingPostedAccrualResolution['blocked'], 0, 5)),
            ));

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $postedCount = 0;

        foreach ($accruals as $accrual) {
            if (!$accrual->isDraft()) {
                continue;
            }

            $accrual->post($this->getCurrentUser());
            ++$postedCount;
        }

        $activePostedAccrualCount = $this->countActivePostedAccruals([
            ...$accruals,
            ...$existingPostedAccrualResolution['adopted'],
        ]);
        $existingPostedCount = max(0, $activePostedAccrualCount - $postedCount);

        if ($postedCount === 0 && $activePostedAccrualCount === 0) {
            $this->addFlash('warning', 'В расчете нет начислений для проведения.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $billingRun->post($this->getCurrentUser());
        $auditLogger->record(
            action: 'billing_run.posted',
            workspace: $workspace,
            entityTable: 'billing_runs',
            entityUuid: $billingRun->getUuid(),
            newValues: [
                'posted_at' => $billingRun->getPostedAt()?->format(DATE_ATOM),
                'posted_accrual_count' => $postedCount,
                'reused_posted_accrual_count' => count($existingPostedAccrualResolution['adopted']),
                'existing_posted_accrual_count' => $existingPostedCount,
                'cancelled_duplicate_draft_accrual_count' => $existingPostedAccrualResolution['cancelled_drafts'],
            ],
            changedFields: ['posted_at', 'posted_by'],
        );
        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            if (!str_contains($exception->getMessage(), 'ux_accruals_one_posted_per_period')) {
                throw $exception;
            }

            $this->addFlash('warning', 'Нельзя провести расчет: уже есть проведенное начисление за тот же участок, тип и период.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if (0 < $existingPostedCount) {
            $this->addFlash('success', sprintf(
                'Расчет проведен. Проведено новых начислений: %d, использовано существующих: %d.',
                $postedCount,
                $existingPostedCount,
            ));
        } else {
            $this->addFlash('success', sprintf('Расчет проведен. Проведено начислений: %d.', $postedCount));
        }

        return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/billing-runs/{uuid}/statements/generate', name: 'app_admin_billing_run_generate_statements', methods: ['POST'])]
    public function generateStatements(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        BillingRunRepository $billingRunRepository,
        BillingRunStatementGenerator $statementGenerator,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $billingRun = $this->findBillingRun($uuid, $billingRunRepository, $workspaceContext);

        if (!$billingRun->isPosted()) {
            $this->addFlash('warning', 'Квитанции можно формировать только для проведенного расчета.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if (!$this->isCsrfTokenValid('generate_billing_run_statements'.$billingRun->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $result = $statementGenerator->generateForPostedBillingRun($billingRun, $this->getCurrentUser());

        foreach ($result->createdStatements as $statement) {
            $auditLogger->record(
                action: 'account_statement.generated',
                workspace: $workspace,
                entityTable: 'account_statements',
                entityUuid: $statement->getUuid(),
                newValues: $this->statementSnapshotAuditValues($statement),
                changedFields: [
                    'billing_run_uuid',
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
        }

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

        $auditLogger->record(
            action: 'billing_run.statements_generated',
            workspace: $workspace,
            entityTable: 'billing_runs',
            entityUuid: $billingRun->getUuid(),
            newValues: [
                'created_statements' => $result->createdStatementCount(),
                'existing_statements' => $result->existingStatementCount(),
                'repaired_payment_requisite_statements' => $result->repairedPaymentRequisiteStatements,
                'created_deliveries' => $result->createdDeliveryCount(),
                'skipped_without_email' => $result->skippedWithoutEmail,
                'skipped_existing_delivery' => $result->skippedExistingDelivery,
            ],
        );

        $entityManager->flush();

        if ($result->createdStatementCount() === 0 && $result->existingStatementCount() === 0) {
            $this->addFlash('warning', 'В проведенном расчете нет активных начислений для формирования квитанций.');
        } else {
            $this->addFlash('success', sprintf(
                'Квитанции обработаны. Создано: %d, уже было: %d, доставок в очередь: %d, без email: %d, уже были доставки: %d, реквизиты дозаполнены: %d.',
                $result->createdStatementCount(),
                $result->existingStatementCount(),
                $result->createdDeliveryCount(),
                $result->skippedWithoutEmail,
                $result->skippedExistingDelivery,
                $result->repairedPaymentRequisiteStatements
            ));
        }

        return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/billing-runs/{uuid}/issues/{issueUuid}/close', name: 'app_admin_billing_run_issue_close', methods: ['POST'])]
    public function closeIssue(
        string $uuid,
        string $issueUuid,
        Request $request,
        EntityManagerInterface $entityManager,
        BillingRunRepository $billingRunRepository,
        BillingRunAccountIssueRepository $issueRepository,
        AccrualRepository $accrualRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $billingRun = $this->findBillingRun($uuid, $billingRunRepository, $workspaceContext);
        $issue = $this->findBillingRunIssue($issueUuid, $billingRun, $issueRepository, $workspace);
        $form = $this->createIssueCloseForm($billingRun, $issue);
        $form->handleRequest($request);

        if (!$billingRun->isDraft()) {
            $this->addFlash('warning', 'Закрывать проблемы можно только в черновике расчета.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if (!$issue->isOpen()) {
            $this->addFlash('warning', 'Проблема уже закрыта.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $reason = $form->get('reason')->getData();
            $comment = $form->get('comment')->getData();

            if (!$reason instanceof BillingRunAccountIssueCloseReason) {
                throw new \LogicException('Invalid issue close reason.');
            }

            $issue->close($reason, is_string($comment) ? $comment : null, $this->getCurrentUser());
            $auditLogger->record(
                action: 'billing_run_issue.closed',
                workspace: $workspace,
                entityTable: 'billing_run_account_issues',
                entityUuid: $issue->getUuid(),
                newValues: [
                    'billing_run_uuid' => $billingRun->getUuid()->toRfc4122(),
                    'account_uuid' => $issue->getAccount()?->getUuid()->toRfc4122(),
                    'issue_type' => $issue->getIssueType()->value,
                    'close_reason' => $issue->getCloseReason()?->value,
                    'close_comment' => $issue->getCloseComment(),
                    'closed_at' => $issue->getClosedAt()?->format(DATE_ATOM),
                ],
                changedFields: ['closed_at', 'closed_by', 'close_reason', 'close_comment'],
                reason: $issue->getCloseComment(),
            );
            $entityManager->flush();
            $this->addFlash('success', 'Проблема закрыта.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderShow($billingRun, $workspace, $issueRepository, $accrualRepository, $this->createCancelForm($billingRun), Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    #[Route('/admin/billing-runs/{uuid}/issues/ignore-all', name: 'app_admin_billing_run_issues_ignore_all', methods: ['POST'])]
    public function ignoreAllIssues(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        BillingRunRepository $billingRunRepository,
        BillingRunAccountIssueRepository $issueRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $billingRun = $this->findBillingRun($uuid, $billingRunRepository, $workspaceContext);

        if (!$billingRun->isDraft()) {
            $this->addFlash('warning', 'Игнорировать проблемы можно только в черновике расчета.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if (!$this->isCsrfTokenValid('ignore_all_billing_run_issues'.$billingRun->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $closedIssueUuids = [];

        foreach ($issueRepository->findOpenByBillingRun($workspace, $billingRun) as $issue) {
            $issue->close(
                BillingRunAccountIssueCloseReason::Ignored,
                'Массово проигнорировано оператором.',
                $this->getCurrentUser(),
            );
            $closedIssueUuids[] = $issue->getUuid()->toRfc4122();
        }

        if ($closedIssueUuids === []) {
            $this->addFlash('info', 'Открытых проблем для игнорирования нет.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $auditLogger->record(
            action: 'billing_run_issues.ignored_all',
            workspace: $workspace,
            entityTable: 'billing_runs',
            entityUuid: $billingRun->getUuid(),
            newValues: [
                'ignored_issue_count' => count($closedIssueUuids),
                'ignored_issue_uuids' => $closedIssueUuids,
            ],
            changedFields: ['billing_run_account_issues.closed_at', 'billing_run_account_issues.closed_by', 'billing_run_account_issues.close_reason', 'billing_run_account_issues.close_comment'],
            reason: 'Массовое игнорирование проблем расчета.',
        );
        $entityManager->flush();
        $this->addFlash('success', sprintf('Проигнорировано проблем: %d.', count($closedIssueUuids)));

        return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/admin/billing-runs/{uuid}/cancel', name: 'app_admin_billing_run_cancel', methods: ['POST'])]
    public function cancel(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        BillingRunRepository $billingRunRepository,
        BillingRunAccountIssueRepository $issueRepository,
        AccrualRepository $accrualRepository,
        WorkspaceContext $workspaceContext,
        AuditLogger $auditLogger,
    ): Response {
        $billingRun = $this->findBillingRun($uuid, $billingRunRepository, $workspaceContext);
        $form = $this->createCancelForm($billingRun);
        $form->handleRequest($request);

        if (!$billingRun->isDraft()) {
            $this->addFlash('warning', 'Можно отменить только черновик расчета.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $reason = (string) $form->get('reason')->getData();
            $billingRun->cancel($reason, $this->getCurrentUser());
            foreach ($issueRepository->findOpenByBillingRun($workspaceContext->requireCurrentWorkspace(), $billingRun) as $issue) {
                $issue->close(BillingRunAccountIssueCloseReason::CancelledRun, 'Расчет отменен.', $this->getCurrentUser());
            }
            foreach ($accrualRepository->findByBillingRun($workspaceContext->requireCurrentWorkspace(), $billingRun) as $accrual) {
                if ($accrual->getCancelledAt() === null) {
                    $accrual->cancel('Расчет отменен.', $this->getCurrentUser());
                }
            }
            $auditLogger->record(
                action: 'billing_run.cancelled',
                workspace: $billingRun->getWorkspace(),
                entityTable: 'billing_runs',
                entityUuid: $billingRun->getUuid(),
                newValues: [
                    'cancelled_at' => $billingRun->getCancelledAt()?->format(DATE_ATOM),
                    'cancellation_reason' => $billingRun->getCancellationReason(),
                ],
                changedFields: ['cancelled_at', 'cancelled_by', 'cancellation_reason'],
                reason: $reason,
            );
            $entityManager->flush();
            $this->addFlash('success', 'Черновик расчета отменен.');

            return $this->redirectToRoute('app_admin_billing_run_show', ['uuid' => $billingRun->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->renderShow(
            $billingRun,
            $workspaceContext->requireCurrentWorkspace(),
            $issueRepository,
            $accrualRepository,
            $form,
            Response::HTTP_UNPROCESSABLE_ENTITY
        );
    }

    private function validateBillingRunForm(FormInterface $form, BillingRun $billingRun, BillingRunRepository $billingRunRepository): void
    {
        if (!$form->isValid()) {
            return;
        }

        if ($billingRun->getPeriodEnd() <= $billingRun->getPeriodStart()) {
            $form->get('periodEnd')->addError(new FormError('Конец периода должен быть позже начала периода.'));

            return;
        }

        $workspace = $billingRun->getWorkspace();

        if (!$workspace) {
            return;
        }

        $existingRun = $billingRunRepository->findOneActiveByKindAndPeriod(
            $workspace,
            $billingRun->getKind(),
            $billingRun->getPeriodStart(),
            $billingRun->getPeriodEnd(),
        );

        if ($existingRun instanceof BillingRun) {
            $form->get('kind')->addError(new FormError('Активный расчет такого типа за этот период уже существует.'));
        }
    }

    private function createCancelForm(BillingRun $billingRun): FormInterface
    {
        return $this->createForm(BillingRunCancelType::class, null, [
            'action' => $this->generateUrl('app_admin_billing_run_cancel', ['uuid' => $billingRun->getUuid()]),
        ]);
    }

    private function createIssueCloseForm(BillingRun $billingRun, BillingRunAccountIssue $issue): FormInterface
    {
        return $this->createForm(BillingRunAccountIssueCloseType::class, null, [
            'action' => $this->generateUrl('app_admin_billing_run_issue_close', [
                'uuid' => $billingRun->getUuid(),
                'issueUuid' => $issue->getUuid(),
            ]),
        ]);
    }

    /**
     * @param array<string, string> $filters
     */
    private function createIssueIndexCloseForm(BillingRunAccountIssue $issue, array $filters): FormInterface
    {
        return $this->createForm(BillingRunAccountIssueCloseType::class, null, [
            'action' => $this->generateUrl('app_admin_billing_run_issue_index_close', [
                'issueUuid' => $issue->getUuid(),
                ...$filters,
            ]),
        ]);
    }

    private function renderIssueIndex(
        Request $request,
        BillingRunAccountIssueRepository $issueRepository,
        BillingRunRepository $billingRunRepository,
        AccountRepository $accountRepository,
        Workspace $workspace,
        AdminPaginator $paginator,
        ?FormInterface $invalidCloseForm = null,
        int $statusCode = Response::HTTP_OK,
    ): Response {
        $billingRuns = $billingRunRepository->findByWorkspace($workspace);
        $accounts = $accountRepository->findActiveByWorkspace($workspace);
        $billingRunUuid = $request->query->getString('billing_run_uuid');
        $accountUuid = $request->query->getString('account_uuid');
        $issueTypeValue = $request->query->getString('issue_type');
        $billingRun = $this->findBillingRunFilter($billingRunUuid, $workspace, $billingRunRepository);
        $account = $this->findAccountFilter($accountUuid, $workspace, $accountRepository);
        $issueType = trim($issueTypeValue) === '' ? null : BillingRunAccountIssueType::tryFrom($issueTypeValue);
        $sort = $issueRepository->normalizeSort($request->query->getString('sort', BillingRunAccountIssueRepository::SORT_BILLING_RUN_PERIOD));
        $direction = $issueRepository->normalizeSortDirection($request->query->getString('dir', BillingRunAccountIssueRepository::SORT_DESC));
        $filters = [
            'billing_run_uuid' => $billingRun?->getUuid()->toRfc4122() ?? '',
            'account_uuid' => $account?->getUuid()->toRfc4122() ?? '',
            'issue_type' => $issueType?->value ?? '',
        ];
        $pagination = $paginator->paginate(
            $issueRepository->createOpenByWorkspaceQuery($workspace, $billingRun, $account, $issueType, $sort, $direction),
            $request->query->getInt('page', 1),
        );
        $issues = $pagination->getItems();
        $issueCloseForms = [];

        foreach ($issues as $issue) {
            $issueUuid = $issue->getUuid()->toRfc4122();

            if ($invalidCloseForm instanceof FormInterface && $request->attributes->get('issueUuid') === $issueUuid) {
                $issueCloseForms[$issueUuid] = $invalidCloseForm->createView();

                continue;
            }

            $issueCloseForms[$issueUuid] = $this->createIssueIndexCloseForm($issue, $this->nonEmptyFilters($filters))->createView();
        }

        return $this->render('admin_billing_run_issue/index.html.twig', [
            'issues' => $issues,
            'pagination' => $pagination,
            'issue_close_forms' => $issueCloseForms,
            'billing_runs' => $billingRuns,
            'accounts' => $accounts,
            'issue_types' => BillingRunAccountIssueType::cases(),
            'filters' => $filters,
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ], new Response(status: $statusCode));
    }

    /**
     * @return array<string, string>
     */
    private function billingRunIssueFilterQuery(Request $request): array
    {
        return $this->nonEmptyFilters([
            'billing_run_uuid' => $request->query->getString('billing_run_uuid'),
            'account_uuid' => $request->query->getString('account_uuid'),
            'issue_type' => $request->query->getString('issue_type'),
        ]);
    }

    /**
     * @param array<string, string> $filters
     *
     * @return array<string, string>
     */
    private function nonEmptyFilters(array $filters): array
    {
        return array_filter($filters, static fn (string $value): bool => trim($value) !== '');
    }

    private function renderShow(
        BillingRun $billingRun,
        Workspace $workspace,
        BillingRunAccountIssueRepository $issueRepository,
        AccrualRepository $accrualRepository,
        ?FormInterface $cancelForm = null,
        int $statusCode = Response::HTTP_OK,
    ): Response {
        $issues = $issueRepository->findByBillingRun($workspace, $billingRun);
        $issueCloseForms = [];

        if ($billingRun->isDraft()) {
            foreach ($issues as $issue) {
                if (!$issue->isOpen()) {
                    continue;
                }

                $issueCloseForms[$issue->getUuid()->toRfc4122()] = $this->createIssueCloseForm($billingRun, $issue)->createView();
            }
        }

        $openIssueCount = $issueRepository->countOpenByBillingRun($workspace, $billingRun);
        $accruals = $accrualRepository->findByBillingRun($workspace, $billingRun);
        $accrualCount = count($accruals);
        $latestIssueUpdatedAt = $issueRepository->findLatestUpdatedAtByBillingRun($workspace, $billingRun);
        $accrualGenerationOutdated = $this->isBillingRunAccrualGenerationOutdated($billingRun, $latestIssueUpdatedAt, $accrualCount);
        $statements = $this->statementRepository->findByBillingRun($workspace, $billingRun);
        $deliveriesByStatement = $this->statementDeliveryRepository->findByStatements($workspace, $statements);
        $statementProgress = $this->buildBillingRunStatementProgress(
            $accrualRepository->countActivePostedAccountsByBillingRun($workspace, $billingRun),
            $this->statementRepository->countActiveByBillingRun($workspace, $billingRun),
        );
        $deliveryProgress = $this->buildBillingRunDeliveryProgress(
            $statementProgress,
            $this->statementDeliveryRepository->summarizeActiveByBillingRun($workspace, $billingRun),
        );

        return $this->render('admin_billing_run/show.html.twig', [
            'billing_run' => $billingRun,
            'issues' => $issues,
            'open_issue_count' => $openIssueCount,
            'issue_close_forms' => $issueCloseForms,
            'accruals' => $accruals,
            'statement_rows' => array_map(function (AccountStatementSnapshot $statement) use ($deliveriesByStatement): array {
                $deliveries = $deliveriesByStatement[$statement->getUuid()->toRfc4122()] ?? [];

                return [
                    'statement' => $statement,
                    'deliveries' => $deliveries,
                    'delivery_summary' => $this->buildStatementDeliverySummary($deliveries),
                ];
            }, $statements),
            'can_post_billing_run' => $billingRun->isDraft() && $openIssueCount === 0 && $accruals !== [] && !$accrualGenerationOutdated,
            'statement_progress' => $statementProgress,
            'delivery_progress' => $deliveryProgress,
            'next_action' => $this->buildBillingRunNextAction($billingRun, $openIssueCount, $accrualCount, $accrualGenerationOutdated, $statementProgress),
            'workflow_steps' => $this->buildBillingRunWorkflowSteps($billingRun, $openIssueCount, $accrualCount, $accrualGenerationOutdated, $statementProgress, $deliveryProgress),
            'cancel_form' => ($cancelForm ?? $this->createCancelForm($billingRun))->createView(),
        ], new Response(status: $statusCode));
    }

    /**
     * @return array{title: string, text: string, alert_class: string, badge_class: string}
     */
    /**
     * @param array{expected_count: int, active_count: int, missing_count: int, status: string, button_label: string} $statementProgress
     */
    private function buildBillingRunNextAction(
        BillingRun $billingRun,
        int $openIssueCount,
        int $accrualCount,
        bool $accrualGenerationOutdated,
        array $statementProgress,
    ): array
    {
        if ($billingRun->isCancelled()) {
            return [
                'title' => 'Расчет отменен',
                'text' => 'Этот запуск больше не участвует в начислениях. При необходимости создайте новый расчет за тот же период.',
                'alert_class' => 'alert-danger',
                'badge_class' => 'text-bg-danger',
            ];
        }

        if ($billingRun->isPosted()) {
            if ($statementProgress['status'] === 'complete') {
                return [
                    'title' => 'Расчет завершен: квитанции сформированы',
                    'text' => sprintf(
                        'Активные квитанции сформированы по всем участкам с начислениями: %d из %d. Повторная обработка не создаст дубли, но может добавить недостающие доставки.',
                        $statementProgress['active_count'],
                        $statementProgress['expected_count'],
                    ),
                    'alert_class' => 'alert-success',
                    'badge_class' => 'text-bg-success',
                ];
            }

            if ($statementProgress['status'] === 'partial') {
                return [
                    'title' => 'Следующее действие: сформировать недостающие квитанции',
                    'text' => sprintf(
                        'Активные квитанции сформированы не по всем участкам: %d из %d. Повторный запуск добавит недостающие квитанции и доставки без дублей.',
                        $statementProgress['active_count'],
                        $statementProgress['expected_count'],
                    ),
                    'alert_class' => 'alert-warning',
                    'badge_class' => 'text-bg-warning',
                ];
            }

            if ($statementProgress['status'] === 'extra') {
                return [
                    'title' => 'Проверьте квитанции по расчету',
                    'text' => sprintf(
                        'Активных квитанций больше, чем участков с активными проведенными начислениями: %d при ожидаемых %d. Проверьте список квитанций перед рассылкой.',
                        $statementProgress['active_count'],
                        $statementProgress['expected_count'],
                    ),
                    'alert_class' => 'alert-warning',
                    'badge_class' => 'text-bg-warning',
                ];
            }

            if ($statementProgress['status'] === 'none') {
                return [
                    'title' => 'Расчет проведен без начислений для квитанций',
                'text' => 'В проведенном расчете нет активных проведенных начислений, по которым можно сформировать квитанции.',
                    'alert_class' => 'alert-warning',
                    'badge_class' => 'text-bg-warning',
                ];
            }

            return [
                'title' => 'Следующее действие: сформировать квитанции',
                'text' => sprintf(
                    'Начисления зафиксированы. Нужно сформировать квитанции по участкам с начислениями: 0 из %d.',
                    $statementProgress['expected_count'],
                ),
                'alert_class' => 'alert-success',
                'badge_class' => 'text-bg-success',
            ];
        }

        if (0 < $openIssueCount) {
            return [
                'title' => 'Следующее действие: закрыть проблемы',
                'text' => sprintf('Открытые проблемы: %d. Проведение расчета будет заблокировано, пока они не закрыты.', $openIssueCount),
                'alert_class' => 'alert-warning',
                'badge_class' => 'text-bg-warning',
            ];
        }

        if ($accrualGenerationOutdated) {
            return [
                'title' => 'Следующее действие: обновить начисления',
                'text' => 'После последней генерации начислений менялись проблемы расчета. Запустите генерацию начислений повторно.',
                'alert_class' => 'alert-warning',
                'badge_class' => 'text-bg-warning',
            ];
        }

        if ($accrualCount === 0) {
            return [
                'title' => 'Следующее действие: сгенерировать начисления',
                'text' => 'Открытых проблем нет. Черновик готов к генерации начислений.',
                'alert_class' => 'alert-info',
                'badge_class' => 'text-bg-info',
            ];
        }

        return [
            'title' => 'Следующее действие: провести расчет',
            'text' => 'Проверьте начисления. Если после прошлой генерации вы закрывали проблемы, сначала снова нажмите «Сгенерировать начисления».',
            'alert_class' => 'alert-success',
            'badge_class' => 'text-bg-success',
        ];
    }

    /**
     * @return list<array{title: string, text: string, badge: string, badge_class: string}>
     */
    /**
     * @param array{expected_count: int, active_count: int, missing_count: int, status: string, button_label: string} $statementProgress
     */
    private function buildBillingRunWorkflowSteps(
        BillingRun $billingRun,
        int $openIssueCount,
        int $accrualCount,
        bool $accrualGenerationOutdated,
        array $statementProgress,
        array $deliveryProgress,
    ): array
    {
        $issuesStep = 0 < $openIssueCount
            ? [
                'title' => 'Проблемы проверены',
                'text' => sprintf('Открытые проблемы: %d.', $openIssueCount),
                'badge' => 'Есть проблемы',
                'badge_class' => 'text-bg-warning',
            ]
            : [
                'title' => 'Проблемы проверены',
                'text' => 'Открытых проблем нет.',
                'badge' => 'Готово',
                'badge_class' => 'text-bg-success',
            ];

        if (0 < $accrualCount && 0 < $openIssueCount) {
            $accrualStep = [
                'title' => 'Начисления сгенерированы',
                'text' => sprintf('Сгенерировано начислений: %d. Участки с открытыми проблемами пропущены.', $accrualCount),
                'badge' => 'Частично',
                'badge_class' => 'text-bg-warning',
            ];
        } elseif (0 < $accrualCount) {
            if ($accrualGenerationOutdated) {
                $accrualStep = [
                    'title' => 'Начисления сгенерированы',
                    'text' => sprintf('Сгенерировано начислений: %d, но после этого менялись проблемы расчета.', $accrualCount),
                    'badge' => 'Обновить',
                    'badge_class' => 'text-bg-warning',
                ];
            } else {
                $accrualStep = [
                    'title' => 'Начисления сгенерированы',
                    'text' => sprintf('Сгенерировано начислений: %d.', $accrualCount),
                    'badge' => 'Готово',
                    'badge_class' => 'text-bg-success',
                ];
            }
        } elseif (0 < $openIssueCount) {
            $accrualStep = [
                'title' => 'Начисления сгенерированы',
                'text' => 'Сначала нужно закрыть проблемы.',
                'badge' => 'Ожидает',
                'badge_class' => 'text-bg-secondary',
            ];
        } else {
            $accrualStep = [
                'title' => 'Начисления сгенерированы',
                'text' => 'Можно запускать генерацию начислений.',
                'badge' => 'Следующий шаг',
                'badge_class' => 'text-bg-info',
            ];
        }

        if ($billingRun->isCancelled()) {
            $postingStep = [
                'title' => 'Расчет проведен',
                'text' => 'Расчет отменен.',
                'badge' => 'Отменен',
                'badge_class' => 'text-bg-danger',
            ];
        } elseif ($billingRun->isPosted()) {
            $postingStep = [
                'title' => 'Расчет проведен',
                'text' => 'Начисления зафиксированы.',
                'badge' => 'Готово',
                'badge_class' => 'text-bg-success',
            ];
        } elseif (0 < $openIssueCount) {
            $postingStep = [
                'title' => 'Расчет проведен',
                'text' => 'Заблокировано открытыми проблемами.',
                'badge' => 'Заблокировано',
                'badge_class' => 'text-bg-secondary',
            ];
        } elseif ($accrualCount === 0) {
            $postingStep = [
                'title' => 'Расчет проведен',
                'text' => 'Сначала нужно сгенерировать начисления.',
                'badge' => 'Ожидает',
                'badge_class' => 'text-bg-secondary',
            ];
        } elseif ($accrualGenerationOutdated) {
            $postingStep = [
                'title' => 'Расчет проведен',
                'text' => 'Заблокировано до повторной генерации начислений.',
                'badge' => 'Заблокировано',
                'badge_class' => 'text-bg-secondary',
            ];
        } else {
            $postingStep = [
                'title' => 'Расчет проведен',
                'text' => 'Можно провести расчет.',
                'badge' => 'Следующий шаг',
                'badge_class' => 'text-bg-info',
            ];
        }

        $statementStep = match (true) {
            $billingRun->isCancelled() => [
                'title' => 'Квитанции сформированы',
                'text' => 'Расчет отменен.',
                'badge' => 'Отменен',
                'badge_class' => 'text-bg-danger',
            ],
            !$billingRun->isPosted() => [
                'title' => 'Квитанции сформированы',
                'text' => 'Сначала нужно провести расчет.',
                'badge' => 'Ожидает',
                'badge_class' => 'text-bg-secondary',
            ],
            $statementProgress['status'] === 'none' => [
                'title' => 'Квитанции сформированы',
                'text' => 'Нет активных проведенных начислений для формирования квитанций.',
                'badge' => 'Нет данных',
                'badge_class' => 'text-bg-secondary',
            ],
            $statementProgress['status'] === 'pending' => [
                'title' => 'Квитанции сформированы',
                'text' => sprintf('Нужно сформировать квитанции: 0 из %d.', $statementProgress['expected_count']),
                'badge' => 'Следующий шаг',
                'badge_class' => 'text-bg-info',
            ],
            $statementProgress['status'] === 'partial' => [
                'title' => 'Квитанции сформированы',
                'text' => sprintf('Сформировано %d из %d. Нужно сформировать недостающие.', $statementProgress['active_count'], $statementProgress['expected_count']),
                'badge' => 'Частично',
                'badge_class' => 'text-bg-warning',
            ],
            $statementProgress['status'] === 'extra' => [
                'title' => 'Квитанции сформированы',
                'text' => sprintf('Активных квитанций %d при ожидаемых %d. Нужно проверить вручную.', $statementProgress['active_count'], $statementProgress['expected_count']),
                'badge' => 'Проверить',
                'badge_class' => 'text-bg-warning',
            ],
            default => [
                'title' => 'Квитанции сформированы',
                'text' => sprintf('Сформировано %d из %d. Повторная обработка доступна без дублей.', $statementProgress['active_count'], $statementProgress['expected_count']),
                'badge' => 'Готово',
                'badge_class' => 'text-bg-success',
            ],
        };

        $deliveryStep = match (true) {
            $billingRun->isCancelled() => [
                'title' => 'Доставки обработаны',
                'text' => 'Расчет отменен.',
                'badge' => 'Отменен',
                'badge_class' => 'text-bg-danger',
            ],
            !$billingRun->isPosted() => [
                'title' => 'Доставки обработаны',
                'text' => 'Сначала нужно провести расчет и сформировать квитанции.',
                'badge' => 'Ожидает',
                'badge_class' => 'text-bg-secondary',
            ],
            !in_array($statementProgress['status'], ['complete', 'extra'], true) => [
                'title' => 'Доставки обработаны',
                'text' => 'Сначала нужно сформировать все квитанции.',
                'badge' => 'Ожидает',
                'badge_class' => 'text-bg-secondary',
            ],
            $deliveryProgress['status'] === 'none' => [
                'title' => 'Доставки обработаны',
                'text' => 'Активных доставок по квитанциям расчета нет.',
                'badge' => 'Нет данных',
                'badge_class' => 'text-bg-secondary',
            ],
            $deliveryProgress['status'] === 'failed' => [
                'title' => 'Доставки обработаны',
                'text' => sprintf(
                    'Есть ошибки доставки: %d из %d. Проверьте раздел доставки квитанций.',
                    $deliveryProgress['failed'],
                    $deliveryProgress['active_total'],
                ),
                'badge' => 'Ошибки',
                'badge_class' => 'text-bg-danger',
            ],
            $deliveryProgress['status'] === 'sent' => [
                'title' => 'Доставки обработаны',
                'text' => sprintf('Все активные доставки отправлены: %d из %d.', $deliveryProgress['sent'], $deliveryProgress['active_total']),
                'badge' => 'Готово',
                'badge_class' => 'text-bg-success',
            ],
            $deliveryProgress['status'] === 'partial' => [
                'title' => 'Доставки обработаны',
                'text' => sprintf(
                    'Часть доставок отправлена: %d из %d; в очереди: %d, отправляется: %d.',
                    $deliveryProgress['sent'],
                    $deliveryProgress['active_total'],
                    $deliveryProgress['queued'],
                    $deliveryProgress['sending'],
                ),
                'badge' => 'Частично',
                'badge_class' => 'text-bg-warning',
            ],
            default => [
                'title' => 'Доставки обработаны',
                'text' => sprintf(
                    'Доставки ожидают отправки: в очереди %d, отправляется %d.',
                    $deliveryProgress['queued'],
                    $deliveryProgress['sending'],
                ),
                'badge' => 'В работе',
                'badge_class' => 'text-bg-warning',
            ],
        };

        return [
            [
                'title' => 'Черновик создан',
                'text' => 'Расчет создан за выбранный период.',
                'badge' => 'Готово',
                'badge_class' => 'text-bg-success',
            ],
            $issuesStep,
            $accrualStep,
            $postingStep,
            $statementStep,
            $deliveryStep,
        ];
    }

    /**
     * @return array{expected_count: int, active_count: int, missing_count: int, status: string, button_label: string}
     */
    private function buildBillingRunStatementProgress(int $expectedCount, int $activeCount): array
    {
        $missingCount = max(0, $expectedCount - $activeCount);
        $status = match (true) {
            $expectedCount === 0 && $activeCount === 0 => 'none',
            $expectedCount === 0 && 0 < $activeCount => 'extra',
            $activeCount === 0 => 'pending',
            $activeCount < $expectedCount => 'partial',
            $activeCount > $expectedCount => 'extra',
            default => 'complete',
        };
        $buttonLabel = match ($status) {
            'partial' => 'Сформировать недостающие',
            'complete', 'extra' => 'Обработать повторно',
            default => 'Сформировать квитанции',
        };

        return [
            'expected_count' => $expectedCount,
            'active_count' => $activeCount,
            'missing_count' => $missingCount,
            'status' => $status,
            'button_label' => $buttonLabel,
        ];
    }

    /**
     * @param array{expected_count: int, active_count: int, missing_count: int, status: string, button_label: string} $statementProgress
     * @param array{active_total: int, queued: int, sending: int, sent: int, failed: int} $summary
     *
     * @return array{active_total: int, queued: int, sending: int, sent: int, failed: int, pending: int, status: string, badge_class: string, label: string}
     */
    private function buildBillingRunDeliveryProgress(array $statementProgress, array $summary): array
    {
        $activeTotal = $summary['active_total'];
        $queued = $summary['queued'];
        $sending = $summary['sending'];
        $sent = $summary['sent'];
        $failed = $summary['failed'];
        $pending = $queued + $sending;
        $status = match (true) {
            $activeTotal === 0 => 'none',
            0 < $failed => 'failed',
            $sent === $activeTotal => 'sent',
            0 < $sent => 'partial',
            default => 'queued',
        };

        if (!in_array($statementProgress['status'], ['complete', 'extra'], true) && $status === 'none') {
            $label = 'Ожидает квитанции';
        } else {
            $parts = [];

            if (0 < $sent) {
                $parts[] = sprintf('отправлено: %d', $sent);
            }

            if (0 < $queued) {
                $parts[] = sprintf('в очереди: %d', $queued);
            }

            if (0 < $sending) {
                $parts[] = sprintf('отправляется: %d', $sending);
            }

            if (0 < $failed) {
                $parts[] = sprintf('ошибок: %d', $failed);
            }

            $label = $parts === [] ? 'Нет активных доставок' : implode(', ', $parts);
        }

        return [
            'active_total' => $activeTotal,
            'queued' => $queued,
            'sending' => $sending,
            'sent' => $sent,
            'failed' => $failed,
            'pending' => $pending,
            'status' => $status,
            'badge_class' => match ($status) {
                'failed' => 'text-bg-danger',
                'sent' => 'text-bg-success',
                'partial', 'queued' => 'text-bg-warning',
                default => 'text-bg-light border',
            },
            'label' => $label,
        ];
    }

    private function isBillingRunAccrualGenerationOutdated(
        BillingRun $billingRun,
        ?DateTimeImmutable $latestIssueUpdatedAt,
        int $accrualCount,
    ): bool {
        if (!$billingRun->isDraft()) {
            return false;
        }

        $accrualsGeneratedAt = $billingRun->getAccrualsGeneratedAt();

        if (0 < $accrualCount && $accrualsGeneratedAt === null) {
            return true;
        }

        if ($latestIssueUpdatedAt === null || $accrualsGeneratedAt === null) {
            return false;
        }

        return $latestIssueUpdatedAt > $accrualsGeneratedAt;
    }

    /**
     * @param list<AccountStatementDelivery> $deliveries
     *
     * @return array{label: string, badge_class: string}
     */
    private function buildStatementDeliverySummary(array $deliveries): array
    {
        if ($deliveries === []) {
            return [
                'label' => 'Нет доставок',
                'badge_class' => 'text-bg-secondary',
            ];
        }

        $counts = [
            'queued' => 0,
            'sending' => 0,
            'sent' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        foreach ($deliveries as $delivery) {
            if ($delivery->isCancelled()) {
                ++$counts['cancelled'];

                continue;
            }

            $statusCode = $delivery->getLatestAttempt()?->getStatusCode() ?? 'queued';

            if (array_key_exists($statusCode, $counts)) {
                ++$counts[$statusCode];
            }
        }

        $parts = [];

        if ($counts['sent'] > 0) {
            $parts[] = sprintf('отправлено: %d', $counts['sent']);
        }

        if ($counts['queued'] > 0) {
            $parts[] = sprintf('в очереди: %d', $counts['queued']);
        }

        if ($counts['sending'] > 0) {
            $parts[] = sprintf('отправляется: %d', $counts['sending']);
        }

        if ($counts['failed'] > 0) {
            $parts[] = sprintf('ошибок: %d', $counts['failed']);
        }

        if ($counts['cancelled'] > 0) {
            $parts[] = sprintf('отменено: %d', $counts['cancelled']);
        }

        return [
            'label' => implode(', ', $parts),
            'badge_class' => match (true) {
                $counts['failed'] > 0 => 'text-bg-danger',
                $counts['queued'] > 0 => 'text-bg-warning',
                $counts['sending'] > 0 => 'text-bg-primary',
                $counts['sent'] > 0 => 'text-bg-success',
                default => 'text-bg-secondary',
            },
        ];
    }

    private function findBillingRunFilter(
        string $uuid,
        Workspace $workspace,
        BillingRunRepository $billingRunRepository,
    ): ?BillingRun {
        $uuid = $this->uuidOrNull($uuid);

        if (!$uuid instanceof Uuid) {
            return null;
        }

        return $billingRunRepository->findOneByWorkspaceAndUuid($workspace, $uuid);
    }

    private function findAccountFilter(
        string $uuid,
        Workspace $workspace,
        AccountRepository $accountRepository,
    ): ?Account {
        $uuid = $this->uuidOrNull($uuid);

        if (!$uuid instanceof Uuid) {
            return null;
        }

        return $accountRepository->findOneActiveByWorkspaceAndUuid($workspace, $uuid);
    }

    private function uuidOrNull(string $value): ?Uuid
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Uuid::fromString($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function parseDate(string $value, string $timezone = 'Europe/Moscow'): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value, new DateTimeZone($timezone));

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private function parseTimestampFilterDate(string $value, string $timezone): ?DateTimeImmutable
    {
        $date = $this->parseDate($value, $timezone);

        return $date instanceof DateTimeImmutable ? $date->setTimezone(new DateTimeZone('UTC')) : null;
    }

    private function findBillingRun(string $uuid, BillingRunRepository $billingRunRepository, WorkspaceContext $workspaceContext): BillingRun
    {
        try {
            $billingRunUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Billing run was not found.');
        }

        $billingRun = $billingRunRepository->findOneByWorkspaceAndUuid($workspaceContext->requireCurrentWorkspace(), $billingRunUuid);

        if (!$billingRun instanceof BillingRun) {
            throw new NotFoundHttpException('Billing run was not found.');
        }

        return $billingRun;
    }

    private function findBillingRunIssue(
        string $uuid,
        BillingRun $billingRun,
        BillingRunAccountIssueRepository $issueRepository,
        Workspace $workspace,
    ): BillingRunAccountIssue {
        try {
            $issueUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Billing run account issue was not found.');
        }

        $issue = $issueRepository->findOneByBillingRunAndUuid($workspace, $billingRun, $issueUuid);

        if (!$issue instanceof BillingRunAccountIssue) {
            throw new NotFoundHttpException('Billing run account issue was not found.');
        }

        return $issue;
    }

    private function findBillingRunIssueByWorkspace(
        string $uuid,
        BillingRunAccountIssueRepository $issueRepository,
        Workspace $workspace,
    ): BillingRunAccountIssue {
        try {
            $issueUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Billing run account issue was not found.');
        }

        $issue = $issueRepository->findOneByWorkspaceAndUuid($workspace, $issueUuid);

        if (!$issue instanceof BillingRunAccountIssue) {
            throw new NotFoundHttpException('Billing run account issue was not found.');
        }

        return $issue;
    }

    /**
     * @param list<Accrual> $accruals
     *
     * @return array{adopted: list<Accrual>, blocked: list<string>, cancelled_drafts: int}
     */
    private function adoptMatchingPostedAccruals(
        Workspace $workspace,
        BillingRun $billingRun,
        array $accruals,
        AccrualRepository $accrualRepository,
        ?User $user,
    ): array {
        $adopted = [];
        $blocked = [];
        $cancelledDrafts = 0;

        foreach ($accruals as $accrual) {
            if ($accrual->isActivePosted()) {
                continue;
            }

            if (!$accrual->isDraft() && $accrual->getCancelledAt() === null) {
                continue;
            }

            $account = $accrual->getAccount();

            if (!$account instanceof Account) {
                continue;
            }

            $existingAccrual = $accrualRepository->findOneActivePostedByAccountTypeAndPeriod(
                $workspace,
                $account,
                $accrual->getType(),
                $accrual->getPeriodStart(),
                $accrual->getPeriodEnd(),
            );

            if (!$existingAccrual instanceof Accrual || $existingAccrual->getUuid()->equals($accrual->getUuid())) {
                continue;
            }

            $existingBillingRun = $existingAccrual->getBillingRun();

            if ($existingBillingRun instanceof BillingRun && !$existingBillingRun->getUuid()->equals($billingRun->getUuid())) {
                $blocked[] = sprintf(
                    'участок %s, %s, %s - %s уже относится к другому расчету',
                    $account->getNumber(),
                    $accrual->getType()->label(),
                    $accrual->getPeriodStart()->format('d.m.Y'),
                    $accrual->getPeriodEnd()->format('d.m.Y'),
                );

                continue;
            }

            if ($accrual->isDraft() && $this->toCents($existingAccrual->getAmount()) !== $this->toCents($accrual->getAmount())) {
                $blocked[] = sprintf(
                    'участок %s, %s, %s - %s: существующее %.2f руб., в расчете %.2f руб.',
                    $account->getNumber(),
                    $accrual->getType()->label(),
                    $accrual->getPeriodStart()->format('d.m.Y'),
                    $accrual->getPeriodEnd()->format('d.m.Y'),
                    $this->toCents($existingAccrual->getAmount()) / 100,
                    $this->toCents($accrual->getAmount()) / 100,
                );

                continue;
            }

            if (!$existingBillingRun instanceof BillingRun) {
                $existingAccrual
                    ->setBillingRun($billingRun)
                    ->touch($user);
            }

            $adopted[$existingAccrual->getUuid()->toRfc4122()] = $existingAccrual;

            if ($accrual->isDraft()) {
                $accrual->cancel('Дубль отменен: использовано уже проведенное начисление за тот же участок, тип и период.', $user);
                ++$cancelledDrafts;
            }
        }

        return [
            'adopted' => array_values($adopted),
            'blocked' => array_values(array_unique($blocked)),
            'cancelled_drafts' => $cancelledDrafts,
        ];
    }

    /**
     * @param list<Accrual> $accruals
     */
    private function countActivePostedAccruals(array $accruals): int
    {
        $uuids = [];

        foreach ($accruals as $accrual) {
            if (!$accrual->isActivePosted()) {
                continue;
            }

            $uuids[$accrual->getUuid()->toRfc4122()] = true;
        }

        return count($uuids);
    }

    private function toCents(string $amount): int
    {
        $amount = trim(str_replace([' ', ','], ['', '.'], $amount));
        $negative = str_starts_with($amount, '-');
        $amount = ltrim($amount, '+-');
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, 2), 2, '0');
        $cents = ((int) $whole * 100) + (int) $fraction;

        return $negative ? -$cents : $cents;
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function statementSnapshotAuditValues(AccountStatementSnapshot $statement): array
    {
        return [
            'workspace_uuid' => $statement->getWorkspace()?->getUuid()->toRfc4122(),
            'billing_run_uuid' => $statement->getBillingRun()?->getUuid()->toRfc4122(),
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
        ];
    }
}
