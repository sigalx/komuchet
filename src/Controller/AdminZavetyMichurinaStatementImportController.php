<?php

namespace App\Controller;

use App\Custom\ZavetyMichurina\ElectricityStatementImport\ZavetyMichurinaStatementImportStager;
use App\Custom\ZavetyMichurina\ElectricityStatementImport\ZavetyMichurinaStatementImportPreviewBuilder;
use App\Custom\ZavetyMichurina\ElectricityStatementImport\ZavetyMichurinaStatementImportApplier;
use App\Entity\User;
use App\Entity\ZavetyMichurinaStatementImportBatch;
use App\Entity\ZavetyMichurinaStatementImportFile;
use App\Enum\ZavetyMichurinaStatementImportFileStatus;
use App\Form\ZavetyMichurinaStatementImportUploadType;
use App\Pagination\AdminPaginator;
use App\Pagination\PaginatedResult;
use App\Repository\AuditLogRepository;
use App\Repository\ZavetyMichurinaStatementImportBatchRepository;
use App\Repository\ZavetyMichurinaStatementImportFileRepository;
use App\Service\AuditLogger;
use App\Service\WorkspaceContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/admin/zavety-michurina/statement-imports')]
#[IsGranted('WORKSPACE_ACCESS')]
final class AdminZavetyMichurinaStatementImportController extends AbstractController
{
    #[Route(name: 'app_admin_zavety_michurina_statement_import_index', methods: ['GET'])]
    public function index(
        Request $request,
        WorkspaceContext $workspaceContext,
        ZavetyMichurinaStatementImportBatchRepository $batchRepository,
        ZavetyMichurinaStatementImportFileRepository $fileRepository,
        AdminPaginator $paginator,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $sort = $batchRepository->normalizeSort($request->query->getString('sort', ZavetyMichurinaStatementImportBatchRepository::SORT_CREATED_AT));
        $direction = $batchRepository->normalizeSortDirection($request->query->getString('dir', ZavetyMichurinaStatementImportBatchRepository::SORT_DESC));
        $pagination = $paginator->paginate(
            $batchRepository->createByWorkspaceQuery($workspace, $sort, $direction),
            $request->query->getInt('page', 1),
        );
        $batchSummaries = [];

        foreach ($pagination->getItems() as $batch) {
            if ($batch instanceof ZavetyMichurinaStatementImportBatch) {
                $batchSummaries[$batch->getUuid()->toRfc4122()] = $fileRepository->summarizeBatch($batch);
            }
        }

        return $this->render('admin_zavety_michurina_statement_import/index.html.twig', [
            'batches' => $pagination->getItems(),
            'pagination' => $pagination,
            'batch_summaries' => $batchSummaries,
            'status_cases' => ZavetyMichurinaStatementImportFileStatus::cases(),
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/new', name: 'app_admin_zavety_michurina_statement_import_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        WorkspaceContext $workspaceContext,
        EntityManagerInterface $entityManager,
        ZavetyMichurinaStatementImportStager $stager,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $form = $this->createForm(ZavetyMichurinaStatementImportUploadType::class, [
            'name' => sprintf('Импорт PDF от %s', (new \DateTimeImmutable())->format('d.m.Y H:i')),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted()) {
            if ($form->isValid()) {
                $uploadedFiles = $form->get('files')->getData();

                if (!is_array($uploadedFiles) || $uploadedFiles === []) {
                    $form->get('files')->addError(new FormError('Выберите хотя бы один файл.'));
                } else {
                    $currentUser = $this->getCurrentUser();
                    $formData = $form->getData();
                    $batch = new ZavetyMichurinaStatementImportBatch(
                        workspace: $workspace,
                        name: is_array($formData) ? ($formData['name'] ?? null) : null,
                        createdBy: $currentUser,
                    );
                    $entityManager->persist($batch);
                    $parsedCount = 0;
                    $failedCount = 0;

                    foreach ($uploadedFiles as $uploadedFile) {
                        if (!$uploadedFile instanceof UploadedFile) {
                            continue;
                        }

                        $importFile = $stager->stageUploadedFile($batch, $uploadedFile, $currentUser);
                        $entityManager->persist($importFile);

                        if ($importFile->getStatus() === ZavetyMichurinaStatementImportFileStatus::Failed) {
                            ++$failedCount;
                        } else {
                            ++$parsedCount;
                        }
                    }

                    $entityManager->flush();

                    if ($failedCount > 0) {
                        $this->addFlash('warning', sprintf('Пачка создана. Распознано: %d, с ошибками: %d.', $parsedCount, $failedCount));
                    } else {
                        $this->addFlash('success', sprintf('Пачка создана. Распознано файлов: %d.', $parsedCount));
                    }

                    return $this->redirectToRoute('app_admin_zavety_michurina_statement_import_show', ['uuid' => $batch->getUuid()], Response::HTTP_SEE_OTHER);
                }
            }

            return $this->render('admin_zavety_michurina_statement_import/new.html.twig', [
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('admin_zavety_michurina_statement_import/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route('/{uuid}', name: 'app_admin_zavety_michurina_statement_import_show', methods: ['GET'])]
    public function showBatch(
        string $uuid,
        Request $request,
        WorkspaceContext $workspaceContext,
        ZavetyMichurinaStatementImportBatchRepository $batchRepository,
        ZavetyMichurinaStatementImportFileRepository $fileRepository,
        AuditLogRepository $auditLogRepository,
        AdminPaginator $paginator,
        ZavetyMichurinaStatementImportPreviewBuilder $previewBuilder,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $batch = $this->findBatch($workspaceContext, $batchRepository, $uuid);
        $filters = [
            'status' => $this->normalizeImportFileStatusFilter($request->query->getString('status', 'all')),
            'readiness' => $this->normalizeReadinessFilter($request->query->getString('readiness', 'all')),
        ];
        $sort = $fileRepository->normalizeSort($request->query->getString('sort', ZavetyMichurinaStatementImportFileRepository::SORT_CREATED_AT));
        $direction = $fileRepository->normalizeSortDirection($request->query->getString('dir', ZavetyMichurinaStatementImportFileRepository::SORT_ASC));
        $parsedFiles = $fileRepository->findByBatchAndStatus($workspace, $batch, ZavetyMichurinaStatementImportFileStatus::Parsed);
        $applyReadiness = $this->buildBatchApplyReadiness($parsedFiles, $previewBuilder);

        $blockedMessagesByFile = $applyReadiness['blocked_messages_by_file'];

        if ($filters['readiness'] !== 'all') {
            $filteredFiles = $this->filterImportFilesByReadiness($parsedFiles, $blockedMessagesByFile, $filters['status'], $filters['readiness']);
            usort($filteredFiles, fn (ZavetyMichurinaStatementImportFile $left, ZavetyMichurinaStatementImportFile $right): int => $fileRepository->compareForAdminList($left, $right, $sort, $direction));
            $pagination = $this->paginateImportFiles(
                $filteredFiles,
                $request->query->getInt('page', 1),
            );
        } else {
            $filesQuery = $fileRepository->createByBatchQuery($workspace, $batch, $sort, $direction);

            if ($filters['status'] !== 'all') {
                $filesQuery
                    ->andWhere('file.status = :statusFilter')
                    ->setParameter('statusFilter', ZavetyMichurinaStatementImportFileStatus::from($filters['status']));
            }

            $pagination = $paginator->paginate(
                $filesQuery,
                $request->query->getInt('page', 1),
            );
        }

        $files = $pagination->getItems();
        $fileUuids = [];
        $fileUuidStrings = [];

        foreach ($files as $file) {
            if ($file instanceof ZavetyMichurinaStatementImportFile) {
                $fileUuids[] = $file->getUuid();
                $fileUuidStrings[] = $file->getUuid()->toRfc4122();
            }
        }

        return $this->render('admin_zavety_michurina_statement_import/show.html.twig', [
            'batch' => $batch,
            'files' => $files,
            'pagination' => $pagination,
            'summary' => $fileRepository->summarizeBatch($batch),
            'status_cases' => ZavetyMichurinaStatementImportFileStatus::cases(),
            'apply_logs' => $auditLogRepository->findLatestZavetyMichurinaImportApplyLogsByFileUuids($workspace, $fileUuids),
            'apply_readiness' => $applyReadiness,
            'preview_blockers_by_file' => array_intersect_key($blockedMessagesByFile, array_flip($fileUuidStrings)),
            'filters' => $filters,
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    #[Route('/{uuid}/apply', name: 'app_admin_zavety_michurina_statement_import_apply_batch', methods: ['POST'])]
    public function applyBatch(
        string $uuid,
        Request $request,
        WorkspaceContext $workspaceContext,
        ZavetyMichurinaStatementImportBatchRepository $batchRepository,
        ZavetyMichurinaStatementImportFileRepository $fileRepository,
        ZavetyMichurinaStatementImportApplier $applier,
        ZavetyMichurinaStatementImportPreviewBuilder $previewBuilder,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $batch = $this->findBatch($workspaceContext, $batchRepository, $uuid);

        if (!$this->isCsrfTokenValid('apply_zm_statement_import_batch'.$batch->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $files = $fileRepository->findByBatchAndStatus($workspace, $batch, ZavetyMichurinaStatementImportFileStatus::Parsed);

        if ($files === []) {
            $this->addFlash('info', 'В пачке нет распознанных файлов для применения.');

            return $this->redirectToRoute('app_admin_zavety_michurina_statement_import_show', ['uuid' => $batch->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $readiness = $this->buildBatchApplyReadiness($files, $previewBuilder);

        if ($readiness['blocked'] > 0) {
            $this->addFlash('warning', sprintf(
                'Массовое применение не запущено: готово файлов %d, заблокировано предпросмотром %d.',
                $readiness['ready'],
                $readiness['blocked'],
            ));

            foreach (array_slice($readiness['blocked_files'], 0, 5) as $blockedFile) {
                $this->addFlash('warning', sprintf('%s: %s', $blockedFile['filename'], implode('; ', $blockedFile['messages'])));
            }

            return $this->redirectToRoute('app_admin_zavety_michurina_statement_import_show', ['uuid' => $batch->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $applied = 0;
        $partial = 0;
        $failed = 0;
        $created = 0;
        $reused = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($files as $file) {
            try {
                $result = $applier->apply($file, $this->getCurrentUser());
                $resultData = $result->toArray();
                $created += $result->createdTotal();
                $reused += $result->reusedTotal();
                $skipped += $result->skippedTotal();

                if ($result->skippedTotal() > 0) {
                    ++$partial;
                    $firstWarning = $resultData['warnings'][0] ?? sprintf('пропущено операций: %d', $result->skippedTotal());
                    $warnings[] = sprintf('%s: %s', $file->getOriginalFilename(), $firstWarning);
                } else {
                    ++$applied;
                }
            } catch (\Throwable $exception) {
                ++$failed;
                $warnings[] = sprintf('%s: %s', $file->getOriginalFilename(), $exception->getMessage());
                break;
            }
        }

        if ($failed > 0) {
            $this->addFlash('danger', sprintf(
                'Массовое применение остановлено. Применено: %d, частично: %d, ошибок: %d.',
                $applied,
                $partial,
                $failed,
            ));
        } elseif ($partial > 0) {
            $this->addFlash('warning', sprintf(
                'Пачка применена частично. Применено: %d, частично: %d. Создано: %d, переиспользовано: %d, пропущено: %d.',
                $applied,
                $partial,
                $created,
                $reused,
                $skipped,
            ));
        } else {
            $this->addFlash('success', sprintf(
                'Пачка применена. Файлов: %d. Создано: %d, переиспользовано: %d.',
                $applied,
                $created,
                $reused,
            ));
        }

        foreach (array_slice($warnings, 0, 5) as $warning) {
            $this->addFlash('warning', $warning);
        }

        if (count($warnings) > 5) {
            $this->addFlash('warning', sprintf('Еще сообщений: %d.', count($warnings) - 5));
        }

        return $this->redirectToRoute('app_admin_zavety_michurina_statement_import_show', ['uuid' => $batch->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/files/{uuid}', name: 'app_admin_zavety_michurina_statement_import_file_show', methods: ['GET'])]
    public function showFile(
        string $uuid,
        WorkspaceContext $workspaceContext,
        ZavetyMichurinaStatementImportFileRepository $fileRepository,
        ZavetyMichurinaStatementImportPreviewBuilder $previewBuilder,
    ): Response {
        $file = $this->findFile($workspaceContext, $fileRepository, $uuid);
        $parsedResult = $file->getParsedResult();
        $parsedResultJson = null;

        if ($parsedResult !== null) {
            $parsedResultJson = json_encode(
                $parsedResult,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        }

        $applyPreview = $previewBuilder->build($file);

        return $this->render('admin_zavety_michurina_statement_import/file_show.html.twig', [
            'file' => $file,
            'batch' => $file->getBatch(),
            'parsed_result' => $parsedResult,
            'parsed_result_json' => $parsedResultJson,
            'apply_preview' => $applyPreview,
            'apply_preview_groups' => $this->groupApplyPreviewItems($applyPreview['items'] ?? []),
        ]);
    }

    #[Route('/files/{uuid}/apply', name: 'app_admin_zavety_michurina_statement_import_file_apply', methods: ['POST'])]
    public function applyFile(
        string $uuid,
        Request $request,
        WorkspaceContext $workspaceContext,
        ZavetyMichurinaStatementImportFileRepository $fileRepository,
        ZavetyMichurinaStatementImportApplier $applier,
    ): Response {
        $file = $this->findFile($workspaceContext, $fileRepository, $uuid);

        if (!$this->isCsrfTokenValid('apply_zm_statement_import_file'.$file->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        try {
            $result = $applier->apply($file, $this->getCurrentUser());
            $resultData = $result->toArray();

            if ($result->skippedTotal() > 0) {
                $this->addFlash('warning', sprintf(
                    'Импорт применен частично. Создано: %d, переиспользовано: %d, пропущено: %d.',
                    $result->createdTotal(),
                    $result->reusedTotal(),
                    $result->skippedTotal(),
                ));

                foreach ($resultData['warnings'] as $warning) {
                    $this->addFlash('warning', $warning);
                }
            } else {
                $this->addFlash('success', sprintf(
                    'Импорт применен. Создано: %d, переиспользовано: %d.',
                    $result->createdTotal(),
                    $result->reusedTotal(),
                ));
            }
        } catch (\Throwable $exception) {
            $this->addFlash('danger', $exception->getMessage());
        }

        return $this->redirectToRoute('app_admin_zavety_michurina_statement_import_file_show', ['uuid' => $file->getUuid()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/files/{uuid}/delete', name: 'app_admin_zavety_michurina_statement_import_file_delete', methods: ['POST'])]
    public function deleteFile(
        string $uuid,
        Request $request,
        WorkspaceContext $workspaceContext,
        ZavetyMichurinaStatementImportFileRepository $fileRepository,
        EntityManagerInterface $entityManager,
        AuditLogger $auditLogger,
    ): Response {
        $file = $this->findFile($workspaceContext, $fileRepository, $uuid);
        $batch = $file->getBatch();

        if (!$this->isCsrfTokenValid('delete_zm_statement_import_file'.$file->getUuid(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        if (!$file->canBeDeleted()) {
            $this->addFlash('warning', 'Примененный файл импорта удалить нельзя.');

            return $this->redirectToRoute('app_admin_zavety_michurina_statement_import_file_show', ['uuid' => $file->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $auditLogger->record(
            action: 'zavety_michurina_statement_import_file.deleted',
            workspace: $file->getWorkspace(),
            entityTable: 'zavety_michurina_statement_import_files',
            entityUuid: $file->getUuid(),
            oldValues: [
                'batch_uuid' => $batch?->getUuid()->toRfc4122(),
                'original_filename' => $file->getOriginalFilename(),
                'status' => $file->getStatus()->value,
                'detected_account_number' => $file->getDetectedAccountNumber(),
                'detected_subscriber_full_name' => $file->getDetectedSubscriberFullName(),
                'source_sha256' => $file->getSourceSha256(),
            ],
            changedFields: ['row_deleted'],
        );

        $entityManager->remove($file);
        $entityManager->flush();
        $this->addFlash('success', 'Файл импорта удален.');

        if ($batch instanceof ZavetyMichurinaStatementImportBatch) {
            return $this->redirectToRoute('app_admin_zavety_michurina_statement_import_show', ['uuid' => $batch->getUuid()], Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_admin_zavety_michurina_statement_import_index', [], Response::HTTP_SEE_OTHER);
    }

    private function findBatch(
        WorkspaceContext $workspaceContext,
        ZavetyMichurinaStatementImportBatchRepository $batchRepository,
        string $uuid,
    ): ZavetyMichurinaStatementImportBatch {
        try {
            $batchUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Import batch was not found.');
        }

        $batch = $batchRepository->findOneByWorkspaceAndUuid($workspaceContext->requireCurrentWorkspace(), $batchUuid);

        if (!$batch instanceof ZavetyMichurinaStatementImportBatch) {
            throw new NotFoundHttpException('Import batch was not found.');
        }

        return $batch;
    }

    private function findFile(
        WorkspaceContext $workspaceContext,
        ZavetyMichurinaStatementImportFileRepository $fileRepository,
        string $uuid,
    ): ZavetyMichurinaStatementImportFile {
        try {
            $fileUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Import file was not found.');
        }

        $file = $fileRepository->findOneByWorkspaceAndUuid($workspaceContext->requireCurrentWorkspace(), $fileUuid);

        if (!$file instanceof ZavetyMichurinaStatementImportFile) {
            throw new NotFoundHttpException('Import file was not found.');
        }

        return $file;
    }

    /**
     * @param list<ZavetyMichurinaStatementImportFile> $files
     *
     * @return array{
     *     total: int,
     *     ready: int,
     *     blocked: int,
     *     ready_files: list<array{uuid: string, filename: string}>,
     *     blocked_files: list<array{uuid: string, filename: string, messages: list<string>}>,
     *     blocked_messages_by_file: array<string, list<string>>,
     *     ready_overflow: int,
     *     blocked_overflow: int
     * }
     */
    private function buildBatchApplyReadiness(array $files, ZavetyMichurinaStatementImportPreviewBuilder $previewBuilder): array
    {
        $readyFiles = [];
        $blockedFiles = [];
        $blockedMessagesByFile = [];
        $ready = 0;
        $blocked = 0;
        $maxListedFiles = 20;

        foreach ($files as $file) {
            $preview = $previewBuilder->build($file);

            if (($preview['can_apply'] ?? false) === true) {
                ++$ready;

                if (count($readyFiles) < $maxListedFiles) {
                    $readyFiles[] = [
                        'uuid' => $file->getUuid()->toRfc4122(),
                        'filename' => $file->getOriginalFilename(),
                    ];
                }

                continue;
            }

            ++$blocked;
            $messages = [];

            foreach (($preview['items'] ?? []) as $item) {
                if (!is_array($item) || ($item['state'] ?? null) !== 'blocked') {
                    continue;
                }

                $title = is_scalar($item['title'] ?? null) ? (string) $item['title'] : 'Блокер';
                $message = is_scalar($item['message'] ?? null) ? trim((string) $item['message']) : '';
                $messages[] = $message === '' ? $title : sprintf('%s: %s', $title, $message);
            }

            if ($messages === []) {
                $messages[] = 'Предпросмотр не готов к применению.';
            }

            $blockedMessagesByFile[$file->getUuid()->toRfc4122()] = $messages;

            if (count($blockedFiles) < $maxListedFiles) {
                $blockedFiles[] = [
                    'uuid' => $file->getUuid()->toRfc4122(),
                    'filename' => $file->getOriginalFilename(),
                    'messages' => $messages,
                ];
            }
        }

        return [
            'total' => count($files),
            'ready' => $ready,
            'blocked' => $blocked,
            'ready_files' => $readyFiles,
            'blocked_files' => $blockedFiles,
            'blocked_messages_by_file' => $blockedMessagesByFile,
            'ready_overflow' => max(0, $ready - count($readyFiles)),
            'blocked_overflow' => max(0, $blocked - count($blockedFiles)),
        ];
    }

    /**
     * @param array<string, list<string>> $blockedMessagesByFile
     *
     * @param list<ZavetyMichurinaStatementImportFile> $files
     *
     * @return list<ZavetyMichurinaStatementImportFile>
     */
    private function filterImportFilesByReadiness(
        array $files,
        array $blockedMessagesByFile,
        string $statusFilter,
        string $readinessFilter,
    ): array {
        if (!in_array($statusFilter, ['all', ZavetyMichurinaStatementImportFileStatus::Parsed->value], true)) {
            return [];
        }

        $filteredFiles = [];

        foreach ($files as $file) {
            $hasBlockers = array_key_exists($file->getUuid()->toRfc4122(), $blockedMessagesByFile);

            if ($readinessFilter === 'blocked' && $hasBlockers) {
                $filteredFiles[] = $file;
            }

            if ($readinessFilter === 'ready' && !$hasBlockers) {
                $filteredFiles[] = $file;
            }
        }

        return $filteredFiles;
    }

    /**
     * @param list<ZavetyMichurinaStatementImportFile> $files
     *
     * @return PaginatedResult<ZavetyMichurinaStatementImportFile>
     */
    private function paginateImportFiles(array $files, int $page, int $perPage = 50): PaginatedResult
    {
        $totalItems = count($files);
        $perPage = max(1, min(100, $perPage));
        $totalPages = max(1, (int) ceil($totalItems / $perPage));
        $page = max(1, min($page, $totalPages));

        return new PaginatedResult(
            items: array_slice($files, ($page - 1) * $perPage, $perPage),
            totalItems: $totalItems,
            currentPage: $page,
            perPage: $perPage,
        );
    }

    private function normalizeImportFileStatusFilter(string $status): string
    {
        $status = trim($status);

        if ($status === 'all' || $status === '') {
            return 'all';
        }

        foreach (ZavetyMichurinaStatementImportFileStatus::cases() as $case) {
            if ($case->value === $status) {
                return $status;
            }
        }

        return 'all';
    }

    private function normalizeReadinessFilter(string $readiness): string
    {
        $readiness = trim($readiness);

        return in_array($readiness, ['blocked', 'ready'], true) ? $readiness : 'all';
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, array{title: string, description: string, badge_class: string, items: list<array<string, mixed>>}>
     */
    private function groupApplyPreviewItems(array $items): array
    {
        $groups = [
            'blocked' => [
                'title' => 'Блокеры',
                'description' => 'Без исправления файл не применится.',
                'badge_class' => 'text-bg-danger',
                'items' => [],
            ],
            'attention' => [
                'title' => 'Проверить',
                'description' => 'Импорт возможен, но есть нюансы.',
                'badge_class' => 'text-bg-warning',
                'items' => [],
            ],
            'create' => [
                'title' => 'Будет создано',
                'description' => 'Новые записи в хозяйстве.',
                'badge_class' => 'text-bg-primary',
                'items' => [],
            ],
            'reuse' => [
                'title' => 'Переиспользуется',
                'description' => 'Уже найдено в системе.',
                'badge_class' => 'text-bg-success',
                'items' => [],
            ],
            'skip' => [
                'title' => 'Пропускается',
                'description' => 'Действий не требуется.',
                'badge_class' => 'text-bg-secondary',
                'items' => [],
            ],
        ];

        foreach ($items as $item) {
            $state = $item['state'] ?? null;

            if (!is_string($state) || !array_key_exists($state, $groups)) {
                continue;
            }

            $groups[$state]['items'][] = $item;
        }

        return $groups;
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
