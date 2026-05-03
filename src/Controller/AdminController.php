<?php

namespace App\Controller;

use App\Entity\BillingSettings;
use App\Entity\Workspace;
use App\Repository\AccountStatementDeliveryRepository;
use App\Repository\BillingRunAccountIssueRepository;
use App\Repository\BillingSettingsRepository;
use App\Repository\ElectricityMeterReadingRepository;
use App\Repository\PaymentRepository;
use App\Service\AccountBalanceListProvider;
use App\Service\WorkspaceContext;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('WORKSPACE_ACCESS')]
final class AdminController extends AbstractController
{
    #[Route('/admin', name: 'app_admin', methods: ['GET'])]
    public function index(
        WorkspaceContext $workspaceContext,
        BillingSettingsRepository $billingSettingsRepository,
        BillingRunAccountIssueRepository $issueRepository,
        AccountStatementDeliveryRepository $statementDeliveryRepository,
        AccountBalanceListProvider $balanceListProvider,
        PaymentRepository $paymentRepository,
        ElectricityMeterReadingRepository $readingRepository,
    ): Response {
        $workspace = $workspaceContext->requireCurrentWorkspace();
        $billingSettings = $billingSettingsRepository->findOneByWorkspace($workspace);
        $debtorRows = array_slice(
            $balanceListProvider->findByWorkspace(
                $workspace,
                AccountBalanceListProvider::STATE_FILTER_DEBT,
                AccountBalanceListProvider::SORT_DEBT_DESC,
            ),
            0,
            5,
        );

        return $this->render('admin/index.html.twig', [
            'billing_settings' => $billingSettings,
            'invoice_schedule' => $this->buildInvoiceSchedule($billingSettings, $workspace),
            'open_issue_count' => $issueRepository->countOpenByWorkspace($workspace),
            'open_issues' => $issueRepository->findRecentOpenByWorkspace($workspace, 5),
            'delivery_summary' => $statementDeliveryRepository->summarizeActiveByWorkspace($workspace),
            'debtor_rows' => $debtorRows,
            'latest_payments' => $paymentRepository->findLatestByWorkspace($workspace, 5),
            'latest_readings' => $readingRepository->findLatestByWorkspace($workspace, 5),
        ]);
    }

    /**
     * @return array{target_date: DateTimeImmutable, days_until: int, state: string}|null
     */
    private function buildInvoiceSchedule(?BillingSettings $billingSettings, Workspace $workspace): ?array
    {
        if (!$billingSettings instanceof BillingSettings) {
            return null;
        }

        try {
            $timezone = new DateTimeZone($workspace->getTimezone());
        } catch (\Throwable) {
            $timezone = new DateTimeZone('Europe/Moscow');
        }

        $today = new DateTimeImmutable('today', $timezone);
        $generationDay = $billingSettings->getInvoiceGenerationDay();
        $year = (int) $today->format('Y');
        $month = (int) $today->format('m');

        if ((int) $today->format('j') > $generationDay) {
            $nextMonth = $today->modify('first day of next month');
            $year = (int) $nextMonth->format('Y');
            $month = (int) $nextMonth->format('m');
        }

        $targetDate = $today->setDate($year, $month, $generationDay);
        $daysUntil = (int) $today->diff($targetDate)->format('%r%a');

        return [
            'target_date' => $targetDate,
            'days_until' => $daysUntil,
            'state' => match (true) {
                $daysUntil === 0 => 'today',
                $daysUntil > 0 && $daysUntil <= 3 => 'soon',
                default => 'future',
            },
        ];
    }
}
