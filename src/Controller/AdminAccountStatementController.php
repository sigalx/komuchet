<?php

namespace App\Controller;

use App\Entity\BillingRun;
use App\Pagination\AdminPaginator;
use App\Repository\AccountStatementDeliveryRepository;
use App\Repository\AccountStatementSnapshotRepository;
use App\Repository\BillingRunRepository;
use App\Service\WorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[IsGranted('WORKSPACE_ACCESS')]
final class AdminAccountStatementController extends AbstractController
{
    private const BILLING_RUN_FILTER_NONE = 'none';

    #[Route('/admin/account-statements', name: 'app_admin_account_statement_index', methods: ['GET'])]
    public function index(
        Request $request,
        AccountStatementSnapshotRepository $statementRepository,
        AccountStatementDeliveryRepository $deliveryRepository,
        BillingRunRepository $billingRunRepository,
        WorkspaceContext $workspaceContext,
        AdminPaginator $paginator,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $search = trim($request->query->getString('q'));
        $billingRunFilter = trim($request->query->getString('billing_run'));
        $statusFilter = $statementRepository->normalizeStatusFilter($request->query->getString('status', AccountStatementSnapshotRepository::STATUS_FILTER_ALL));
        $deliveryFilter = $statementRepository->normalizeDeliveryFilter($request->query->getString('delivery', AccountStatementSnapshotRepository::DELIVERY_FILTER_ALL));
        $generatedAtFrom = trim($request->query->getString('generated_at_from'));
        $generatedAtTo = trim($request->query->getString('generated_at_to'));
        $amountToPayFrom = trim($request->query->getString('amount_to_pay_from'));
        $amountToPayTo = trim($request->query->getString('amount_to_pay_to'));
        $sort = $statementRepository->normalizeSort($request->query->getString('sort', AccountStatementSnapshotRepository::SORT_GENERATED_AT));
        $direction = $statementRepository->normalizeSortDirection($request->query->getString('dir', AccountStatementSnapshotRepository::SORT_DESC));
        $billingRun = $this->resolveBillingRunFilter($billingRunFilter, $billingRunRepository, $workspaceContext);
        $withoutBillingRun = $billingRunFilter === self::BILLING_RUN_FILTER_NONE;
        $pagination = $paginator->paginate(
            $statementRepository->createByWorkspaceForAdminListQuery(
                workspace: $workspace,
                search: $search,
                billingRun: $billingRun,
                withoutBillingRun: $withoutBillingRun,
                statusFilter: $statusFilter,
                deliveryFilter: $deliveryFilter,
                generatedAtFrom: $this->parseDateStart($generatedAtFrom, $workspace->getTimezone()),
                generatedAtBefore: $this->parseDateBefore($generatedAtTo, $workspace->getTimezone()),
                amountToPayFrom: $this->parseMoney($amountToPayFrom),
                amountToPayTo: $this->parseMoney($amountToPayTo),
                sort: $sort,
                direction: $direction,
            ),
            $request->query->getInt('page', 1),
        );
        $statements = $pagination->getItems();

        return $this->render('admin_account_statement/index.html.twig', [
            'statements' => $statements,
            'pagination' => $pagination,
            'deliveries_by_statement' => $deliveryRepository->findByStatements($workspace, $statements),
            'billing_runs' => $billingRunRepository->findByWorkspace($workspace),
            'status_filter_choices' => AccountStatementSnapshotRepository::statusFilterChoices(),
            'delivery_filter_choices' => AccountStatementSnapshotRepository::deliveryFilterChoices(),
            'billing_run_filter_none' => self::BILLING_RUN_FILTER_NONE,
            'filters' => [
                'q' => $search,
                'billing_run' => $billingRunFilter,
                'status' => $statusFilter,
                'delivery' => $deliveryFilter,
                'generated_at_from' => $generatedAtFrom,
                'generated_at_to' => $generatedAtTo,
                'amount_to_pay_from' => $amountToPayFrom,
                'amount_to_pay_to' => $amountToPayTo,
            ],
            'sort' => [
                'field' => $sort,
                'dir' => $direction,
            ],
        ]);
    }

    private function resolveBillingRunFilter(
        string $billingRunFilter,
        BillingRunRepository $billingRunRepository,
        WorkspaceContext $workspaceContext,
    ): ?BillingRun {
        if ($billingRunFilter === '' || $billingRunFilter === self::BILLING_RUN_FILTER_NONE) {
            return null;
        }

        try {
            $uuid = Uuid::fromString($billingRunFilter);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $billingRunRepository->findOneByWorkspaceAndUuid($workspaceContext->requireCurrentWorkspace(), $uuid);
    }

    private function parseDateStart(string $value, string $timezoneName): ?DateTimeImmutable
    {
        return $this->parseDate($value, $timezoneName);
    }

    private function parseDateBefore(string $value, string $timezoneName): ?DateTimeImmutable
    {
        return $this->parseDate($value, $timezoneName)?->modify('+1 day');
    }

    private function parseDate(string $value, string $timezoneName): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        try {
            $timezone = new DateTimeZone($timezoneName);
        } catch (\Throwable) {
            $timezone = new DateTimeZone('Europe/Moscow');
        }

        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value, $timezone);

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private function parseMoney(string $value): ?string
    {
        $value = trim(str_replace([' ', ','], ['', '.'], $value));

        if ($value === '' || !is_numeric($value)) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }
}
