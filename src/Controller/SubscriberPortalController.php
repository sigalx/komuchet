<?php

namespace App\Controller;

use App\Entity\Account;
use App\Entity\AccountStatementSnapshot;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityTariffZone;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\ElectricityMeterReadingSource;
use App\Form\SubscriberElectricityMeterReadingType;
use App\Pagination\QueryPaginator;
use App\Repository\AccountRepository;
use App\Repository\AccrualRepository;
use App\Repository\AccountStatementAccrualSnapshotRepository;
use App\Repository\AccountStatementElectricityLineSnapshotRepository;
use App\Repository\AccountStatementElectricityRegisterSnapshotRepository;
use App\Repository\AccountStatementPaymentSnapshotRepository;
use App\Repository\AccountStatementSnapshotRepository;
use App\Repository\ElectricityMeterRepository;
use App\Repository\ElectricityMeterReadingRepository;
use App\Repository\ElectricityMeterRegisterRepository;
use App\Repository\PaymentRepository;
use App\Repository\SubscriberAccountAccessRepository;
use App\Repository\WorkspaceRepository;
use App\Security\SubscriberPortalAccessVoter;
use App\Service\AccountBalanceListProvider;
use App\Service\AccountStatementPaymentQrCodeGenerator;
use App\Service\AccountStatementPdfRenderer;
use App\Service\AccountStatementProvider;
use App\Service\AuditLogger;
use App\Service\ElectricityMeterReadingValidationViolation;
use App\Service\ElectricityMeterReadingValidator;
use App\Service\SubscriberPortalContext;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

#[Route('/portal')]
#[IsGranted('SUBSCRIBER_PORTAL_ACCESS')]
final class SubscriberPortalController extends AbstractController
{
    private const PORTAL_HISTORY_PER_PAGE = 10;

    #[Route('', name: 'app_subscriber_portal', methods: ['GET'])]
    public function index(
        SubscriberPortalContext $subscriberPortalContext,
        SubscriberAccountAccessRepository $accessRepository,
        AccountBalanceListProvider $balanceListProvider,
        ElectricityMeterRepository $meterRepository,
    ): Response {
        $workspace = $subscriberPortalContext->requireCurrentWorkspace();
        $subscriber = $subscriberPortalContext->requireCurrentSubscriber();
        $accountAccesses = $accessRepository->findActiveBySubscriber($workspace, $subscriber);

        return $this->render('subscriber_portal/index.html.twig', [
            'subscriber' => $subscriber,
            'account_rows' => $this->buildAccountRows($workspace, $accountAccesses, $balanceListProvider, $meterRepository),
        ]);
    }

    #[Route('/accounts/{uuid}', name: 'app_subscriber_portal_account_show', methods: ['GET'])]
    public function showAccount(
        string $uuid,
        Request $request,
        SubscriberPortalContext $subscriberPortalContext,
        AccountRepository $accountRepository,
        AccountBalanceListProvider $balanceListProvider,
        ElectricityMeterRepository $meterRepository,
        ElectricityMeterReadingRepository $readingRepository,
        AccrualRepository $accrualRepository,
        PaymentRepository $paymentRepository,
        AccountStatementSnapshotRepository $statementRepository,
        QueryPaginator $paginator,
    ): Response {
        $workspace = $subscriberPortalContext->requireCurrentWorkspace();

        try {
            $accountUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Account not found.');
        }

        $account = $accountRepository->findOneActiveByWorkspaceAndUuid($workspace, $accountUuid);

        if (!$account instanceof Account) {
            throw $this->createNotFoundException('Account not found.');
        }

        $this->denyAccessUnlessGranted(SubscriberPortalAccessVoter::ACCOUNT_VIEW, $account);

        $readingFilters = [
            'status' => $readingRepository->normalizeStatusFilter($request->query->getString('readings_status', ElectricityMeterReadingRepository::STATUS_FILTER_ALL)),
            'taken_on_from' => trim($request->query->getString('readings_taken_on_from')),
            'taken_on_to' => trim($request->query->getString('readings_taken_on_to')),
        ];
        $accrualFilters = [
            'status' => $accrualRepository->normalizePortalStatusFilter($request->query->getString('accruals_status', AccrualRepository::STATUS_FILTER_ALL)),
            'period_start_from' => trim($request->query->getString('accruals_period_start_from')),
            'period_start_to' => trim($request->query->getString('accruals_period_start_to')),
        ];
        $paymentFilters = [
            'status' => $paymentRepository->normalizeStatusFilter($request->query->getString('payments_status', PaymentRepository::STATUS_FILTER_ALL)),
            'paid_on_from' => trim($request->query->getString('payments_paid_on_from')),
            'paid_on_to' => trim($request->query->getString('payments_paid_on_to')),
        ];

        $readingsPagination = $paginator->paginate(
            $readingRepository->createByAccountForPortalListQuery(
                $workspace,
                $account,
                $readingFilters['status'],
                $this->parseDate($readingFilters['taken_on_from'], $workspace),
                $this->parseDate($readingFilters['taken_on_to'], $workspace),
            ),
            $request->query->getInt('readings_page', 1),
            self::PORTAL_HISTORY_PER_PAGE,
        );
        $accrualsPagination = $paginator->paginate(
            $accrualRepository->createPostedByAccountForPortalListQuery(
                $workspace,
                $account,
                $accrualFilters['status'],
                $this->parseDate($accrualFilters['period_start_from'], $workspace),
                $this->parseDate($accrualFilters['period_start_to'], $workspace),
            ),
            $request->query->getInt('accruals_page', 1),
            self::PORTAL_HISTORY_PER_PAGE,
        );
        $paymentsPagination = $paginator->paginate(
            $paymentRepository->createByAccountForPortalListQuery(
                $workspace,
                $account,
                $paymentFilters['status'],
                $this->parseDate($paymentFilters['paid_on_from'], $workspace),
                $this->parseDate($paymentFilters['paid_on_to'], $workspace),
            ),
            $request->query->getInt('payments_page', 1),
            self::PORTAL_HISTORY_PER_PAGE,
        );

        return $this->render('subscriber_portal/account_show.html.twig', [
            'account' => $account,
            'balance' => $balanceListProvider->findIndexedByWorkspaceAndAccounts($workspace, [$account])[$account->getUuid()->toRfc4122()] ?? null,
            'active_meter' => $meterRepository->findOneActiveByWorkspaceAndAccount($workspace, $account),
            'readings' => $readingsPagination->getItems(),
            'readings_pagination' => $readingsPagination,
            'accruals' => $accrualsPagination->getItems(),
            'accruals_pagination' => $accrualsPagination,
            'payments' => $paymentsPagination->getItems(),
            'payments_pagination' => $paymentsPagination,
            'statements' => $statementRepository->findLatestActiveByAccount($workspace, $account, 5),
            'filters' => [
                'readings' => $readingFilters,
                'accruals' => $accrualFilters,
                'payments' => $paymentFilters,
            ],
        ]);
    }

    #[Route('/accounts/{uuid}/readings/new', name: 'app_subscriber_portal_account_reading_new', methods: ['GET', 'POST'])]
    public function newReading(
        string $uuid,
        Request $request,
        EntityManagerInterface $entityManager,
        SubscriberPortalContext $subscriberPortalContext,
        AccountRepository $accountRepository,
        ElectricityMeterRepository $meterRepository,
        ElectricityMeterRegisterRepository $registerRepository,
        ElectricityMeterReadingRepository $readingRepository,
        AuditLogger $auditLogger,
        ElectricityMeterReadingValidator $readingValidator,
    ): Response {
        $workspace = $subscriberPortalContext->requireCurrentWorkspace();
        $subscriber = $subscriberPortalContext->requireCurrentSubscriber();
        $account = $this->findAccessibleAccount($uuid, $workspace, $accountRepository);
        $this->denyAccessUnlessGranted(SubscriberPortalAccessVoter::ACCOUNT_READING_SUBMIT, $account);

        $electricityMeter = $meterRepository->findOneActiveByWorkspaceAndAccount($workspace, $account);

        if (!$electricityMeter instanceof ElectricityMeter) {
            $this->addFlash('warning', 'Для участка не найден активный электросчетчик.');

            return $this->redirectToRoute('app_subscriber_portal_account_show', ['uuid' => $account->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $tariffZones = $this->findMeterTariffZones($workspace, $electricityMeter, $registerRepository);

        if ($tariffZones === []) {
            $this->addFlash('warning', 'У активного электросчетчика не заданы регистры.');

            return $this->redirectToRoute('app_subscriber_portal_account_show', ['uuid' => $account->getUuid()], Response::HTTP_SEE_OTHER);
        }

        $form = $this->createForm(SubscriberElectricityMeterReadingType::class, null, [
            'tariff_zones' => $tariffZones,
            'default_taken_on' => $this->todayInWorkspace($workspace),
        ]);
        $form->handleRequest($request);
        $latestReadingsByZoneUuid = $readingRepository->findLatestActiveIndexedByTariffZone($workspace, $electricityMeter);

        if ($form->isSubmitted()) {
            if ($form->isValid() && $this->validateSubscriberReadingForm($form, $workspace, $electricityMeter, $tariffZones, $readingValidator)) {
                $takenOn = $form->get('takenOn')->getData();
                $notes = $this->normalizeNullableText($form->get('notes')->getData());
                $currentUser = $this->getCurrentUser();

                foreach ($tariffZones as $tariffZone) {
                    $fieldName = SubscriberElectricityMeterReadingType::readingFieldName($tariffZone);
                    $reading = new ElectricityMeterReading(
                        $workspace,
                        $electricityMeter,
                        $tariffZone,
                        $this->normalizeDecimal((string) $form->get($fieldName)->getData(), 3),
                        $takenOn,
                        ElectricityMeterReadingSource::Subscriber,
                        $currentUser,
                    );
                    $reading
                        ->setProvidedBySubscriber($subscriber)
                        ->setCreatedBy($currentUser)
                        ->setNotes($notes);

                    $entityManager->persist($reading);
                    $auditLogger->record(
                        action: 'electricity_meter_reading.created',
                        workspace: $workspace,
                        entityTable: 'electricity_meter_readings',
                        entityUuid: $reading->getUuid(),
                        newValues: [
                            'electricity_meter_uuid' => $electricityMeter->getUuid()->toRfc4122(),
                            'tariff_zone_uuid' => $tariffZone->getUuid()->toRfc4122(),
                            'reading_value' => $reading->getReadingValue(),
                            'taken_on' => $reading->getTakenOn()->format('Y-m-d'),
                            'source' => $reading->getSource()->value,
                            'submitted_by' => $currentUser?->getUuid()->toRfc4122(),
                            'provided_by_subscriber_uuid' => $subscriber->getUuid()->toRfc4122(),
                        ],
                        changedFields: [
                            'electricity_meter_uuid',
                            'tariff_zone_uuid',
                            'reading_value',
                            'taken_on',
                            'source',
                            'submitted_by',
                            'provided_by_subscriber_uuid',
                        ],
                    );
                }

                $entityManager->flush();
                $this->addFlash('success', 'Показания переданы.');

                return $this->redirectToRoute('app_subscriber_portal_account_show', ['uuid' => $account->getUuid()], Response::HTTP_SEE_OTHER);
            }

            return $this->render('subscriber_portal/reading_new.html.twig', [
                'account' => $account,
                'electricity_meter' => $electricityMeter,
                'tariff_zones' => $tariffZones,
                'latest_readings_by_zone_uuid' => $latestReadingsByZoneUuid,
                'form' => $form,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        return $this->render('subscriber_portal/reading_new.html.twig', [
            'account' => $account,
            'electricity_meter' => $electricityMeter,
            'tariff_zones' => $tariffZones,
            'latest_readings_by_zone_uuid' => $latestReadingsByZoneUuid,
            'form' => $form,
        ]);
    }

    #[Route('/accounts/{uuid}/balance', name: 'app_subscriber_portal_account_balance', methods: ['GET'])]
    public function balance(
        string $uuid,
        SubscriberPortalContext $subscriberPortalContext,
        AccountRepository $accountRepository,
        AccountStatementProvider $statementProvider,
    ): Response {
        $workspace = $subscriberPortalContext->requireCurrentWorkspace();
        $account = $this->findAccessibleAccount($uuid, $workspace, $accountRepository);
        $this->denyAccessUnlessGranted(SubscriberPortalAccessVoter::ACCOUNT_VIEW, $account);

        return $this->render('subscriber_portal/balance.html.twig', [
            'statement' => $statementProvider->buildCurrent($workspace, $account, $this->todayInWorkspace($workspace)),
        ]);
    }

    #[Route('/accounts/{uuid}/statements/{statementUuid}', name: 'app_subscriber_portal_account_statement_snapshot_show', methods: ['GET'])]
    public function statementSnapshot(
        string $uuid,
        string $statementUuid,
        SubscriberPortalContext $subscriberPortalContext,
        AccountRepository $accountRepository,
        AccountStatementSnapshotRepository $statementRepository,
        AccountStatementAccrualSnapshotRepository $statementAccrualRepository,
        AccountStatementElectricityRegisterSnapshotRepository $statementElectricityRegisterRepository,
        AccountStatementElectricityLineSnapshotRepository $statementElectricityLineRepository,
        AccountStatementPaymentSnapshotRepository $statementPaymentRepository,
        AccountStatementPaymentQrCodeGenerator $paymentQrCodeGenerator,
    ): Response {
        $workspace = $subscriberPortalContext->requireCurrentWorkspace();
        $account = $this->findAccessibleAccount($uuid, $workspace, $accountRepository);
        $this->denyAccessUnlessGranted(SubscriberPortalAccessVoter::ACCOUNT_VIEW, $account);
        $snapshot = $this->findActiveStatementSnapshot($statementUuid, $workspace, $account, $statementRepository);

        return $this->render('subscriber_portal/statement_snapshot.html.twig', [
            'statement' => $snapshot,
            'accruals' => $statementAccrualRepository->findByStatement($workspace, $snapshot),
            'electricity_registers' => $statementElectricityRegisterRepository->findByStatement($workspace, $snapshot),
            'electricity_lines' => $statementElectricityLineRepository->findByStatement($workspace, $snapshot),
            'payments' => $statementPaymentRepository->findByStatement($workspace, $snapshot),
            'payment_qr_code' => $paymentQrCodeGenerator->generate($snapshot),
        ]);
    }

    #[Route('/accounts/{uuid}/statements/{statementUuid}/pdf', name: 'app_subscriber_portal_account_statement_snapshot_pdf', methods: ['GET'])]
    public function statementSnapshotPdf(
        string $uuid,
        string $statementUuid,
        SubscriberPortalContext $subscriberPortalContext,
        AccountRepository $accountRepository,
        AccountStatementSnapshotRepository $statementRepository,
        AccountStatementAccrualSnapshotRepository $statementAccrualRepository,
        AccountStatementElectricityRegisterSnapshotRepository $statementElectricityRegisterRepository,
        AccountStatementElectricityLineSnapshotRepository $statementElectricityLineRepository,
        AccountStatementPaymentSnapshotRepository $statementPaymentRepository,
        AccountStatementPaymentQrCodeGenerator $paymentQrCodeGenerator,
        AccountStatementPdfRenderer $pdfRenderer,
    ): Response {
        $workspace = $subscriberPortalContext->requireCurrentWorkspace();
        $account = $this->findAccessibleAccount($uuid, $workspace, $accountRepository);
        $this->denyAccessUnlessGranted(SubscriberPortalAccessVoter::ACCOUNT_VIEW, $account);
        $snapshot = $this->findActiveStatementSnapshot($statementUuid, $workspace, $account, $statementRepository);
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

    #[Route('/workspaces/switch', name: 'app_subscriber_portal_workspace_switch', methods: ['POST'])]
    public function switchWorkspace(
        Request $request,
        WorkspaceRepository $workspaceRepository,
        SubscriberPortalContext $subscriberPortalContext,
    ): Response {
        if (!$this->isCsrfTokenValid('switch_portal_workspace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $workspaceUuid = (string) $request->request->get('workspace_uuid');

        try {
            $workspace = $workspaceRepository->find(Uuid::fromString($workspaceUuid));
        } catch (\InvalidArgumentException) {
            $workspace = null;
        }

        if ($workspace === null) {
            $this->addFlash('danger', 'Хозяйство не найдено.');

            return $this->redirectToRoute('app_subscriber_portal');
        }

        try {
            $subscriberPortalContext->switchCurrentWorkspace($workspace);
        } catch (\InvalidArgumentException) {
            $this->addFlash('danger', 'Это хозяйство недоступно в личном кабинете.');

            return $this->redirectToRoute('app_subscriber_portal');
        }

        return $this->redirectToRoute('app_subscriber_portal');
    }

    /**
     * @param list<SubscriberAccountAccess> $accountAccesses
     *
     * @return list<array{access: SubscriberAccountAccess, account: Account, balance: mixed, active_meter: mixed}>
     */
    private function buildAccountRows(
        Workspace $workspace,
        array $accountAccesses,
        AccountBalanceListProvider $balanceListProvider,
        ElectricityMeterRepository $meterRepository,
    ): array {
        $accounts = [];

        foreach ($accountAccesses as $access) {
            $account = $access->getAccount();

            if ($account instanceof Account) {
                $accounts[] = $account;
            }
        }

        $balancesByAccount = $balanceListProvider->findIndexedByWorkspaceAndAccounts($workspace, $accounts);
        $metersByAccount = $meterRepository->findActiveIndexedByWorkspaceAndAccounts($workspace, $accounts);
        $rows = [];

        foreach ($accountAccesses as $access) {
            $account = $access->getAccount();

            if (!$account instanceof Account) {
                continue;
            }

            $accountUuid = $account->getUuid()->toRfc4122();
            $rows[] = [
                'access' => $access,
                'account' => $account,
                'balance' => $balancesByAccount[$accountUuid] ?? null,
                'active_meter' => $metersByAccount[$accountUuid] ?? null,
            ];
        }

        return $rows;
    }

    private function findAccessibleAccount(string $uuid, Workspace $workspace, AccountRepository $accountRepository): Account
    {
        try {
            $accountUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Account not found.');
        }

        $account = $accountRepository->findOneActiveByWorkspaceAndUuid($workspace, $accountUuid);

        if (!$account instanceof Account) {
            throw $this->createNotFoundException('Account not found.');
        }

        return $account;
    }

    private function findActiveStatementSnapshot(
        string $uuid,
        Workspace $workspace,
        Account $account,
        AccountStatementSnapshotRepository $statementRepository,
    ): AccountStatementSnapshot {
        try {
            $statementUuid = Uuid::fromString($uuid);
        } catch (\InvalidArgumentException) {
            throw $this->createNotFoundException('Statement not found.');
        }

        $statement = $statementRepository->findOneActiveByWorkspaceAccountAndUuid($workspace, $account, $statementUuid);

        if (!$statement instanceof AccountStatementSnapshot) {
            throw $this->createNotFoundException('Statement not found.');
        }

        return $statement;
    }

    /**
     * @return list<ElectricityTariffZone>
     */
    private function findMeterTariffZones(
        Workspace $workspace,
        ElectricityMeter $electricityMeter,
        ElectricityMeterRegisterRepository $registerRepository,
    ): array {
        $tariffZones = [];

        foreach ($registerRepository->findByMeter($workspace, $electricityMeter) as $register) {
            $tariffZone = $register->getTariffZone();

            if ($tariffZone instanceof ElectricityTariffZone) {
                $tariffZones[] = $tariffZone;
            }
        }

        return $tariffZones;
    }

    /**
     * @param list<ElectricityTariffZone> $tariffZones
     */
    private function validateSubscriberReadingForm(
        FormInterface $form,
        Workspace $workspace,
        ElectricityMeter $electricityMeter,
        array $tariffZones,
        ElectricityMeterReadingValidator $readingValidator,
    ): bool {
        $takenOn = $form->get('takenOn')->getData();

        if (!$takenOn instanceof DateTimeImmutable) {
            $form->get('takenOn')->addError(new FormError('Укажите дату снятия.'));

            return false;
        }

        $violations = [];
        $addedViolationFields = [];

        foreach ($tariffZones as $tariffZone) {
            $fieldName = SubscriberElectricityMeterReadingType::readingFieldName($tariffZone);
            $fieldViolations = $readingValidator->validate(
                $workspace,
                $electricityMeter,
                $tariffZone,
                $takenOn,
                $this->normalizeDecimal((string) $form->get($fieldName)->getData(), 3),
                true,
                true,
                $this->todayInWorkspace($workspace),
            );

            foreach ($fieldViolations as $violation) {
                $errorField = $this->subscriberReadingViolationField($violation, $fieldName);
                $deduplicateKey = $errorField.'.'.$violation->getCode();

                if (!isset($addedViolationFields[$deduplicateKey])) {
                    $form->get($errorField)->addError(new FormError($violation->getMessage()));
                    $addedViolationFields[$deduplicateKey] = true;
                }
                $violations[] = $violation;
            }
        }

        return $violations === [];
    }

    private function subscriberReadingViolationField(
        ElectricityMeterReadingValidationViolation $violation,
        string $readingFieldName,
    ): string {
        return match ($violation->getCode()) {
            ElectricityMeterReadingValidator::CODE_TAKEN_ON_IN_FUTURE,
            ElectricityMeterReadingValidator::CODE_TAKEN_ON_BEFORE_INSTALLATION,
            ElectricityMeterReadingValidator::CODE_TAKEN_ON_AFTER_REMOVAL => 'takenOn',
            default => $readingFieldName,
        };
    }

    private function todayInWorkspace(Workspace $workspace): DateTimeImmutable
    {
        return new DateTimeImmutable('today', $this->workspaceTimezone($workspace));
    }

    private function parseDate(string $value, Workspace $workspace): ?DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value, $this->workspaceTimezone($workspace));

        return $date instanceof DateTimeImmutable ? $date : null;
    }

    private function workspaceTimezone(Workspace $workspace): DateTimeZone
    {
        try {
            return new DateTimeZone($workspace->getTimezone());
        } catch (\Throwable) {
            return new DateTimeZone('Europe/Moscow');
        }
    }

    private function normalizeDecimal(string $value, int $scale): string
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));

        return number_format((float) $normalized, $scale, '.', '');
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function getCurrentUser(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
