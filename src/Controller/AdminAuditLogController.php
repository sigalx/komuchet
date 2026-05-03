<?php

namespace App\Controller;

use App\Entity\User;
use App\Enum\AuditLogSource;
use App\Repository\AuditLogRepository;
use App\Repository\WorkspaceRepository;
use App\Pagination\AdminPaginator;
use App\Service\WorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('WORKSPACE_ADMIN')]
final class AdminAuditLogController extends AbstractController
{
    #[Route('/admin/audit-logs', name: 'app_admin_audit_log_index', methods: ['GET'])]
    public function index(
        Request $request,
        AuditLogRepository $auditLogRepository,
        WorkspaceRepository $workspaceRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $currentWorkspace = $workspaceContext->requireCurrentWorkspace();
        $globalAdmin = $this->getUser() instanceof User && $this->getUser()->isAdmin();
        $rawFilters = $this->buildRawFilters($request, $globalAdmin);
        $parsedFilters = $this->buildParsedFilters($rawFilters);
        $sort = $auditLogRepository->normalizeSort($request->query->getString('sort', AuditLogRepository::SORT_OCCURRED_AT));
        $direction = $auditLogRepository->normalizeSortDirection($request->query->getString('dir', AuditLogRepository::SORT_DESC));
        $pagination = $paginator->paginate(
            $auditLogRepository->createForAdminQuery($currentWorkspace, $globalAdmin, $parsedFilters, $sort, $direction),
            $request->query->getInt('page', 1),
        );

        return $this->render('admin_audit_log/index.html.twig', [
            'audit_logs' => $pagination->getItems(),
            'pagination' => $pagination,
            'filters' => $rawFilters,
            'sources' => AuditLogSource::cases(),
            'is_global_admin' => $globalAdmin,
            'workspaces' => $globalAdmin ? $workspaceRepository->findBy([], ['code' => 'ASC']) : [],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    /**
     * @return array{
     *     workspace: string,
     *     action: string,
     *     source: string,
     *     actor: string,
     *     entity_table: string,
     *     entity_uuid: string,
     *     occurred_from: string,
     *     occurred_to: string
     * }
     */
    private function buildRawFilters(Request $request, bool $globalAdmin): array
    {
        return [
            'workspace' => $globalAdmin ? trim($request->query->getString('workspace', 'all')) : 'current',
            'action' => trim($request->query->getString('action')),
            'source' => trim($request->query->getString('source')),
            'actor' => trim($request->query->getString('actor')),
            'entity_table' => trim($request->query->getString('entity_table')),
            'entity_uuid' => trim($request->query->getString('entity_uuid')),
            'occurred_from' => trim($request->query->getString('occurred_from')),
            'occurred_to' => trim($request->query->getString('occurred_to')),
        ];
    }

    /**
     * @param array<string, string> $rawFilters
     *
     * @return array<string, mixed>
     */
    private function buildParsedFilters(array $rawFilters): array
    {
        return [
            'workspace' => $rawFilters['workspace'] ?? 'all',
            'action' => $rawFilters['action'] ?? '',
            'source' => AuditLogSource::tryFrom($rawFilters['source'] ?? ''),
            'actor' => $rawFilters['actor'] ?? '',
            'entity_table' => $rawFilters['entity_table'] ?? '',
            'entity_uuid' => Uuid::isValid($rawFilters['entity_uuid'] ?? '') ? Uuid::fromString($rawFilters['entity_uuid']) : null,
            'occurred_from' => $this->parseDate($rawFilters['occurred_from'] ?? ''),
            'occurred_to' => $this->parseDate($rawFilters['occurred_to'] ?? '', endExclusive: true),
        ];
    }

    private function parseDate(string $value, bool $endExclusive = false): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value, new DateTimeZone('Europe/Moscow'));

        if (!$date instanceof DateTimeImmutable) {
            return null;
        }

        return $endExclusive ? $date->modify('+1 day') : $date;
    }
}
