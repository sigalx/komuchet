<?php

namespace App\Command;

use App\Demo\DemoPeopleCatalog;
use App\Demo\DemoPerson;
use App\Entity\Account;
use App\Entity\AccountElectricityTariffProfileAssignment;
use App\Entity\AccountGroup;
use App\Entity\AccountGroupMember;
use App\Entity\AccountStatementDelivery;
use App\Entity\AccountStatementDeliveryAttempt;
use App\Entity\AccountStatementSnapshot;
use App\Entity\Accrual;
use App\Entity\BillingRun;
use App\Entity\BillingSettings;
use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRule;
use App\Entity\ElectricityConsumptionBandRuleAllScope;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffProfile;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\Payment;
use App\Entity\PaymentRequisiteAssignment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Subscriber;
use App\Entity\SubscriberAccountAccess;
use App\Entity\User;
use App\Entity\UserEmailIdentity;
use App\Entity\Workspace;
use App\Entity\WorkspaceUserRoleAssignment;
use App\Enum\AccrualType;
use App\Enum\BillingRunKind;
use App\Enum\ElectricityMeterReadingSource;
use App\Enum\PaymentSource;
use App\Enum\SubscriberAccountAccessRole;
use App\Enum\WorkspaceUserRoleCode;
use App\Enum\ElectricityConsumptionBandAllocationMethod;
use App\Enum\ElectricityConsumptionBandRuleScopeMode;
use App\Repository\AccountElectricityTariffProfileAssignmentRepository;
use App\Repository\AccountGroupRepository;
use App\Repository\AccountGroupMemberRepository;
use App\Repository\AccountRepository;
use App\Repository\AccountStatementDeliveryRepository;
use App\Repository\AccountStatementSnapshotRepository;
use App\Repository\AccrualRepository;
use App\Repository\BillingRunAccountIssueRepository;
use App\Repository\BillingRunRepository;
use App\Repository\BillingSettingsRepository;
use App\Repository\ElectricityConsumptionBandRepository;
use App\Repository\ElectricityConsumptionBandRuleAllScopeRepository;
use App\Repository\ElectricityConsumptionBandRuleRangeRepository;
use App\Repository\ElectricityConsumptionBandRuleRepository;
use App\Repository\ElectricityMeterReadingRepository;
use App\Repository\ElectricityMeterRegisterRepository;
use App\Repository\ElectricityMeterRepository;
use App\Repository\ElectricityTariffPeriodRepository;
use App\Repository\ElectricityTariffProfileRepository;
use App\Repository\ElectricityTariffRateRepository;
use App\Repository\ElectricityTariffZoneRepository;
use App\Repository\PaymentRepository;
use App\Repository\PaymentRequisiteAssignmentRepository;
use App\Repository\PaymentRequisiteProfileRepository;
use App\Repository\UserEmailIdentityRepository;
use App\Repository\SubscriberAccountAccessRepository;
use App\Repository\SubscriberRepository;
use App\Repository\WorkspaceRepository;
use App\Repository\WorkspaceUserRoleAssignmentRepository;
use App\Service\AccountStatementDeliveryEnqueuer;
use App\Service\AccountStatementSnapshotGenerator;
use App\Service\BillingRunIssueGenerator;
use App\Service\ElectricityBillingRunAccrualGenerator;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(
    name: 'app:demo:seed',
    description: 'Создать демо-хозяйство с псевдоданными для обучения и демонстраций.',
)]
class DemoSeedCommand extends Command
{
    private const DEFAULT_WORKSPACE_CODE = 'demo';
    private const DEFAULT_WORKSPACE_NAME = 'Демо-хозяйство КомУчёт';
    private const DEFAULT_SIZE = 'medium';
    private const DEFAULT_SEED = 'komuchet-demo';
    private const DEFAULT_TIMEZONE = 'Europe/Moscow';
    private const DEFAULT_INVOICE_GENERATION_DAY = 5;
    private const DEFAULT_READING_FRESHNESS_WINDOW_DAYS = 15;
    private const RESET_CONFIRMATION = 'demo';
    private const DEMO_SOURCE_DOCUMENT = 'Демо-данные КомУчёт';
    private const DEMO_READING_MONTH_COUNT = 12;
    private const DEMO_STALE_READING_ACCOUNT_NUMBER = 3;
    private const DEMO_CORRECTED_READING_ACCOUNT_NUMBER = 4;
    private const DEMO_PAYMENT_REQUISITE_PROFILE_CODE = 'demo-default';

    private const RESET_WORKSPACE_TABLES = [
        'audit_logs',
        'zavety_michurina_statement_import_files',
        'zavety_michurina_statement_import_batches',
        'account_statement_delivery_attempts',
        'account_statement_deliveries',
        'account_statement_electricity_lines',
        'account_statement_electricity_registers',
        'account_statement_accruals',
        'account_statement_payments',
        'account_statements',
        'payment_requisite_assignments',
        'payment_requisite_profiles',
        'electricity_accrual_lines',
        'electricity_accrual_registers',
        'electricity_accrual_contexts',
        'accruals',
        'billing_run_account_issues',
        'billing_runs',
        'payments',
        'electricity_meter_readings',
        'electricity_meter_registers',
        'electricity_meters',
        'account_electricity_tariff_profile_assignments',
        'electricity_consumption_band_rule_account_scopes',
        'electricity_consumption_band_rule_group_scopes',
        'electricity_consumption_band_rule_all_scopes',
        'electricity_consumption_band_rule_ranges',
        'electricity_consumption_band_rules',
        'electricity_tariff_rates',
        'electricity_tariff_periods',
        'electricity_consumption_bands',
        'electricity_tariff_profiles',
        'electricity_tariff_zones',
        'account_group_members',
        'subscriber_account_accesses',
        'account_groups',
        'subscribers',
        'accounts',
        'billing_settings',
        'workspace_user_role_assignments',
    ];

    private const RESET_IMMUTABLE_DELETE_TRIGGERS = [
        'audit_logs' => 'trg_audit_logs_immutable_delete',
        'electricity_meter_registers' => 'trg_electricity_meter_registers_immutable_delete',
    ];

    private const DATASET_SIZES = [
        'small' => [
            'accounts' => '15-20',
            'subscribers' => '10-15',
            'accountCount' => 15,
            'ownerCount' => 12,
            'representativeCount' => 3,
            'history' => '12 месяцев',
            'purpose' => 'быстрая smoke-демонстрация',
        ],
        'medium' => [
            'accounts' => '40-50',
            'subscribers' => '30-40',
            'accountCount' => 40,
            'ownerCount' => 32,
            'representativeCount' => 8,
            'history' => '12 месяцев',
            'purpose' => 'основной режим обучения оператора',
        ],
        'large' => [
            'accounts' => '80-100',
            'subscribers' => '60-80',
            'accountCount' => 80,
            'ownerCount' => 64,
            'representativeCount' => 16,
            'history' => '12 месяцев',
            'purpose' => 'проверка списков, фильтров, поиска и производительности',
        ],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkspaceRepository $workspaceRepository,
        private readonly BillingSettingsRepository $billingSettingsRepository,
        private readonly AccountRepository $accountRepository,
        private readonly AccountGroupMemberRepository $accountGroupMemberRepository,
        private readonly AccountGroupRepository $accountGroupRepository,
        private readonly SubscriberRepository $subscriberRepository,
        private readonly SubscriberAccountAccessRepository $subscriberAccountAccessRepository,
        private readonly UserEmailIdentityRepository $emailIdentityRepository,
        private readonly WorkspaceUserRoleAssignmentRepository $workspaceRoleAssignmentRepository,
        private readonly ElectricityTariffZoneRepository $tariffZoneRepository,
        private readonly ElectricityTariffProfileRepository $tariffProfileRepository,
        private readonly ElectricityConsumptionBandRepository $consumptionBandRepository,
        private readonly ElectricityTariffPeriodRepository $tariffPeriodRepository,
        private readonly ElectricityTariffRateRepository $tariffRateRepository,
        private readonly ElectricityConsumptionBandRuleRepository $consumptionBandRuleRepository,
        private readonly ElectricityConsumptionBandRuleRangeRepository $consumptionBandRuleRangeRepository,
        private readonly ElectricityConsumptionBandRuleAllScopeRepository $consumptionBandRuleAllScopeRepository,
        private readonly AccountElectricityTariffProfileAssignmentRepository $accountTariffProfileAssignmentRepository,
        private readonly ElectricityMeterRepository $electricityMeterRepository,
        private readonly ElectricityMeterRegisterRepository $electricityMeterRegisterRepository,
        private readonly ElectricityMeterReadingRepository $electricityMeterReadingRepository,
        private readonly AccrualRepository $accrualRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentRequisiteProfileRepository $paymentRequisiteProfileRepository,
        private readonly PaymentRequisiteAssignmentRepository $paymentRequisiteAssignmentRepository,
        private readonly BillingRunRepository $billingRunRepository,
        private readonly BillingRunAccountIssueRepository $billingRunAccountIssueRepository,
        private readonly BillingRunIssueGenerator $billingRunIssueGenerator,
        private readonly ElectricityBillingRunAccrualGenerator $electricityBillingRunAccrualGenerator,
        private readonly AccountStatementSnapshotRepository $accountStatementSnapshotRepository,
        private readonly AccountStatementDeliveryRepository $accountStatementDeliveryRepository,
        private readonly AccountStatementSnapshotGenerator $accountStatementSnapshotGenerator,
        private readonly AccountStatementDeliveryEnqueuer $accountStatementDeliveryEnqueuer,
        #[Autowire('%kernel.environment%')]
        private readonly string $kernelEnvironment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('workspace-code', null, InputOption::VALUE_REQUIRED, 'Код создаваемого или обновляемого демо-хозяйства.', self::DEFAULT_WORKSPACE_CODE)
            ->addOption('workspace-name', null, InputOption::VALUE_REQUIRED, 'Отображаемое название демо-хозяйства.', self::DEFAULT_WORKSPACE_NAME)
            ->addOption('size', null, InputOption::VALUE_REQUIRED, 'Размер набора: small, medium, large.', self::DEFAULT_SIZE)
            ->addOption('as-of', null, InputOption::VALUE_REQUIRED, 'Опорная дата генерации в формате YYYY-MM-DD. По умолчанию текущая дата.')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'Seed детерминированной генерации.', self::DEFAULT_SEED)
            ->addOption('grant-admin-email', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Выдать существующему пользователю роль admin в демо-хозяйстве.')
            ->addOption('grant-operator-email', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Выдать существующему пользователю роль operator в демо-хозяйстве.')
            ->addOption('grant-subscriber-email', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Связать существующего пользователя с демо-абонентом для проверки личного кабинета.')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Сбросить данные демо-хозяйства перед генерацией.')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Подтверждение destructive reset. Для --reset требуется --confirm=demo.')
            ->addOption('allow-prod-reset', null, InputOption::VALUE_NONE, 'Разрешить --reset в prod-like окружении.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $workspaceCode = $this->stringOption($input, 'workspace-code');
        $workspaceName = $this->stringOption($input, 'workspace-name');
        $size = $this->stringOption($input, 'size');
        $asOfRaw = $this->nullableStringOption($input, 'as-of') ?? (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
        $seed = $this->stringOption($input, 'seed');
        $reset = (bool) $input->getOption('reset');
        $confirm = $this->nullableStringOption($input, 'confirm');
        $allowProdReset = (bool) $input->getOption('allow-prod-reset');
        $grantRequests = $this->collectGrantRequests($input);
        $subscriberGrantRequests = $this->collectSubscriberGrantRequests($input);

        $errors = $this->validateOptions(
            workspaceCode: $workspaceCode,
            workspaceName: $workspaceName,
            size: $size,
            asOfRaw: $asOfRaw,
            seed: $seed,
            reset: $reset,
            confirm: $confirm,
            allowProdReset: $allowProdReset,
        );

        $grantErrors = $this->validateGrantRequests($grantRequests);
        $subscriberGrantErrors = $this->validateSubscriberGrantRequests($subscriberGrantRequests);
        $errors = [...$errors, ...$grantErrors, ...$subscriberGrantErrors];

        if ($errors !== []) {
            $io->error($errors);

            return Command::INVALID;
        }

        $resetResult = $reset ? $this->resetDemoWorkspace($workspaceCode) : null;

        [$resolvedGrantRequests, $grantResolveErrors] = $this->resolveGrantRequests($grantRequests);
        [$resolvedSubscriberGrantRequests, $subscriberGrantResolveErrors] = $this->resolveSubscriberGrantRequests($subscriberGrantRequests);

        $resolveErrors = [...$grantResolveErrors, ...$subscriberGrantResolveErrors];

        if ($resolveErrors !== []) {
            $io->error($resolveErrors);

            return Command::INVALID;
        }

        $asOf = $this->parseDate($asOfRaw);
        \assert($asOf instanceof DateTimeImmutable);
        $sizeConfig = self::DATASET_SIZES[$size];
        [$workspace, $workspaceCreated] = $this->createOrUpdateWorkspace($workspaceCode, $workspaceName);
        [$billingSettings, $billingSettingsCreated] = $this->createOrUpdateBillingSettings($workspace, $workspaceName);
        [$accountGroupResults, $summerGroup, $yearRoundGroup] = $this->createOrUpdateDemoAccountGroups($workspace);
        $tariffResults = $this->createOrUpdateDemoTariffs($workspace, $asOf);
        $accountSubscriberResults = $this->createOrUpdateDemoAccountsAndSubscribers(
            workspace: $workspace,
            summerGroup: $summerGroup,
            yearRoundGroup: $yearRoundGroup,
            sizeConfig: $sizeConfig,
            seed: $seed,
            asOf: $asOf,
        );
        $this->entityManager->flush();

        $electricityMeterResults = $this->createOrUpdateDemoElectricityMeters($workspace, $asOf);
        $this->entityManager->flush();

        $electricityReadingResults = $this->createOrUpdateDemoElectricityReadings($workspace, $asOf);
        $financialResults = $this->createOrUpdateDemoFinancialScenarios($workspace, $asOf);
        [$paymentRequisiteResults, $paymentRequisiteProfile] = $this->createOrUpdateDemoPaymentRequisites($workspace, $asOf);
        $this->entityManager->flush();

        $billingRunResults = $this->createOrUpdateDemoBillingRuns($workspace, $asOf);
        $this->entityManager->flush();

        $statementResults = $this->createOrUpdateDemoStatementsAndDeliveries($workspace, $asOf, $paymentRequisiteProfile);
        $grantResults = $this->grantWorkspaceAccess($workspace, $resolvedGrantRequests);
        $subscriberPortalGrantResults = $this->grantSubscriberPortalAccess($workspace, $resolvedSubscriberGrantRequests);

        $this->entityManager->flush();

        $io->title('Demo seed');

        $io->definitionList(
            ['Окружение' => $this->kernelEnvironment],
            ['UUID хозяйства' => $workspace->getUuid()->toRfc4122()],
            ['Код хозяйства' => $workspace->getCode()],
            ['Название хозяйства' => $workspace->getName()],
            ['Хозяйство' => $workspaceCreated ? 'created' : 'updated'],
            ['Настройки расчетов' => $billingSettingsCreated ? 'created' : 'updated'],
            ['День формирования квитанций' => (string) $billingSettings->getInvoiceGenerationDay()],
            ['Окно актуальности показаний, дней' => (string) $billingSettings->getReadingFreshnessWindowDays()],
            ['Часовой пояс' => $workspace->getTimezone()],
            ['Размер набора' => $size],
            ['Опорная дата' => $asOf->format('Y-m-d')],
            ['Seed' => $seed],
        );

        if ($resetResult !== null) {
            $io->section('Reset демо-хозяйства');

            if ($resetResult['existed'] === false) {
                $io->text('Хозяйство с таким кодом не найдено, удалять было нечего.');
            } else {
                $io->listing([
                    sprintf('Удаленное хозяйство: %s', $resetResult['workspaceUuid']),
                    sprintf('Удалено строк: %d', $resetResult['deletedRows']),
                    sprintf('Затронуто таблиц: %d', $resetResult['deletedTables']),
                ]);
            }
        }

        $io->section('Доступ к демо-хозяйству');

        if ($grantResults === []) {
            $io->text('Роли существующим пользователям не выдавались. Используйте --grant-admin-email или --grant-operator-email.');
        } else {
            $io->listing($grantResults);
        }

        $io->section('Доступ к личному кабинету');

        if ($subscriberPortalGrantResults === []) {
            $io->text('Портальные доступы существующим пользователям не выдавались. Используйте --grant-subscriber-email.');
        } else {
            $io->listing($subscriberPortalGrantResults);
        }

        $io->section('Тарифная модель');
        $io->listing($tariffResults);

        $io->section('Группы участков');
        $io->listing($accountGroupResults);

        $io->section('Участки и абоненты');
        $io->listing($accountSubscriberResults);

        $io->section('Электросчетчики');
        $io->listing($electricityMeterResults);

        $io->section('Показания электросчетчиков');
        $io->listing($electricityReadingResults);

        $io->section('Оплаты и финансовые сценарии');
        $io->listing($financialResults);

        $io->section('Платежные реквизиты');
        $io->listing($paymentRequisiteResults);

        $io->section('Расчеты');
        $io->listing($billingRunResults);

        $io->section('Квитанции и доставки');
        $io->listing($statementResults);

        $io->section('Планируемый набор');
        $io->listing([
            sprintf('Участки: %s', $sizeConfig['accounts']),
            sprintf('Абоненты: %s', $sizeConfig['subscribers']),
            sprintf('История: %s', $sizeConfig['history']),
            sprintf('Назначение: %s', $sizeConfig['purpose']),
        ]);

        $io->section('Обязательные сценарии');
        $io->listing([
            'участок с долгом',
            'участок с переплатой',
            'участок без актуальных показаний',
            'участок с исправленным показанием',
            'участок с двумя владельцами или представителями',
            'абонент с двумя участками',
            'двухтарифный счетчик',
            'квитанция в очереди на отправку',
            'квитанция с неуспешной доставкой',
        ]);

        $io->success('Демо-хозяйство и базовые демо-данные текущего шага готовы.');

        return Command::SUCCESS;
    }

    /**
     * @return array{existed: bool, workspaceUuid: string|null, deletedRows: int, deletedTables: int}
     */
    private function resetDemoWorkspace(string $workspaceCode): array
    {
        $connection = $this->entityManager->getConnection();
        $workspaceUuid = $connection->fetchOne(
            'SELECT uuid::text FROM workspaces WHERE code = :workspace_code',
            ['workspace_code' => $workspaceCode],
        );

        if ($workspaceUuid === false) {
            return [
                'existed' => false,
                'workspaceUuid' => null,
                'deletedRows' => 0,
                'deletedTables' => 0,
            ];
        }

        $deletedRows = 0;
        $deletedTables = 0;
        $workspaceUuid = (string) $workspaceUuid;

        $connection->beginTransaction();

        try {
            foreach (self::RESET_IMMUTABLE_DELETE_TRIGGERS as $table => $trigger) {
                $connection->executeStatement(sprintf('ALTER TABLE %s DISABLE TRIGGER %s', $table, $trigger));
            }

            foreach (self::RESET_WORKSPACE_TABLES as $table) {
                $rowCount = $connection->executeStatement(
                    sprintf('DELETE FROM %s WHERE workspace_uuid = :workspace_uuid', $table),
                    ['workspace_uuid' => $workspaceUuid],
                );

                $deletedRows += $rowCount;

                if ($rowCount > 0) {
                    ++$deletedTables;
                }
            }

            $workspaceRowCount = $connection->executeStatement(
                'DELETE FROM workspaces WHERE uuid = :workspace_uuid AND code = :workspace_code',
                [
                    'workspace_uuid' => $workspaceUuid,
                    'workspace_code' => $workspaceCode,
                ],
            );

            $deletedRows += $workspaceRowCount;

            if ($workspaceRowCount > 0) {
                ++$deletedTables;
            }

            foreach (self::RESET_IMMUTABLE_DELETE_TRIGGERS as $table => $trigger) {
                $connection->executeStatement(sprintf('ALTER TABLE %s ENABLE TRIGGER %s', $table, $trigger));
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        $this->entityManager->clear();

        return [
            'existed' => true,
            'workspaceUuid' => $workspaceUuid,
            'deletedRows' => $deletedRows,
            'deletedTables' => $deletedTables,
        ];
    }

    /**
     * @param list<array{email: string, emailNormalized: string, roleCode: WorkspaceUserRoleCode}> $grantRequests
     *
     * @return array{list<array{email: string, roleCode: WorkspaceUserRoleCode, user: User}>, list<string>}
     */
    private function resolveGrantRequests(array $grantRequests): array
    {
        $resolved = [];
        $errors = [];

        foreach ($grantRequests as $grantRequest) {
            $identity = $this->emailIdentityRepository->findOneActiveByEmailNormalized($grantRequest['emailNormalized']);

            if (!$identity instanceof UserEmailIdentity || !$identity->getUser() instanceof User) {
                $errors[] = sprintf('Пользователь с активным email "%s" не найден.', $grantRequest['email']);

                continue;
            }

            if ($identity->getVerifiedAt() === null) {
                $errors[] = sprintf('Email "%s" не подтвержден, вход по нему невозможен.', $grantRequest['email']);

                continue;
            }

            $user = $identity->getUser();

            if (!$user->isLoginAllowed()) {
                $errors[] = sprintf('Пользователь "%s" не активен: %s.', $grantRequest['email'], $user->getStatusLabel());

                continue;
            }

            $resolved[] = [
                'email' => $identity->getEmail(),
                'roleCode' => $grantRequest['roleCode'],
                'user' => $user,
            ];
        }

        return [$resolved, $errors];
    }

    /**
     * @param list<array{email: string, emailNormalized: string}> $grantRequests
     *
     * @return array{list<array{email: string, user: User}>, list<string>}
     */
    private function resolveSubscriberGrantRequests(array $grantRequests): array
    {
        $resolved = [];
        $errors = [];

        foreach ($grantRequests as $grantRequest) {
            $identity = $this->emailIdentityRepository->findOneActiveByEmailNormalized($grantRequest['emailNormalized']);

            if (!$identity instanceof UserEmailIdentity || !$identity->getUser() instanceof User) {
                $errors[] = sprintf('Пользователь с активным email "%s" не найден.', $grantRequest['email']);

                continue;
            }

            if ($identity->getVerifiedAt() === null) {
                $errors[] = sprintf('Email "%s" не подтвержден, вход по нему невозможен.', $grantRequest['email']);

                continue;
            }

            $user = $identity->getUser();

            if (!$user->isLoginAllowed()) {
                $errors[] = sprintf('Пользователь "%s" не активен: %s.', $grantRequest['email'], $user->getStatusLabel());

                continue;
            }

            $resolved[] = [
                'email' => $identity->getEmail(),
                'user' => $user,
            ];
        }

        return [$resolved, $errors];
    }

    /**
     * @param list<array{email: string, roleCode: WorkspaceUserRoleCode, user: User}> $grantRequests
     *
     * @return list<string>
     */
    private function grantWorkspaceAccess(Workspace $workspace, array $grantRequests): array
    {
        $results = [];

        foreach ($grantRequests as $grantRequest) {
            $existingAssignment = $this->workspaceRoleAssignmentRepository->findOneActiveByWorkspaceUserAndRole(
                $workspace,
                $grantRequest['user'],
                $grantRequest['roleCode'],
            );

            if ($existingAssignment instanceof WorkspaceUserRoleAssignment) {
                $results[] = sprintf(
                    '%s: роль %s уже активна',
                    $grantRequest['email'],
                    $grantRequest['roleCode']->value,
                );

                continue;
            }

            $this->entityManager->persist(new WorkspaceUserRoleAssignment(
                $workspace,
                $grantRequest['user'],
                $grantRequest['roleCode'],
            ));

            $results[] = sprintf(
                '%s: выдана роль %s',
                $grantRequest['email'],
                $grantRequest['roleCode']->value,
            );
        }

        return $results;
    }

    /**
     * @param list<array{email: string, user: User}> $grantRequests
     *
     * @return list<string>
     */
    private function grantSubscriberPortalAccess(Workspace $workspace, array $grantRequests): array
    {
        if ($grantRequests === []) {
            return [];
        }

        $results = [];
        $targetSubscribers = $this->findDemoSubscriberPortalGrantTargets($workspace);

        foreach ($grantRequests as $grantRequest) {
            $existingSubscriber = $this->subscriberRepository->findOneActiveByWorkspaceAndUser(
                $workspace,
                $grantRequest['user'],
            );

            if ($existingSubscriber instanceof Subscriber) {
                $results[] = sprintf(
                    '%s: личный кабинет уже активен, %s',
                    $grantRequest['email'],
                    $this->describeSubscriberPortalAccess($workspace, $existingSubscriber),
                );

                continue;
            }

            $targetSubscriber = $this->nextUnlinkedSubscriber($targetSubscribers);

            if (!$targetSubscriber instanceof Subscriber) {
                $results[] = sprintf('%s: нет свободного демо-абонента для привязки', $grantRequest['email']);

                continue;
            }

            $targetSubscriber->setUser($grantRequest['user'])->touch();

            $results[] = sprintf(
                '%s: выдан доступ к личному кабинету, %s',
                $grantRequest['email'],
                $this->describeSubscriberPortalAccess($workspace, $targetSubscriber),
            );
        }

        return $results;
    }

    /**
     * @return list<Subscriber>
     */
    private function findDemoSubscriberPortalGrantTargets(Workspace $workspace): array
    {
        $ownerSubscribers = [];
        $representativeSubscribers = [];

        foreach ($this->accountRepository->findActiveByWorkspace($workspace) as $account) {
            foreach ($this->subscriberAccountAccessRepository->findActiveByAccount($workspace, $account) as $access) {
                $subscriber = $access->getSubscriber();

                if (!$subscriber instanceof Subscriber) {
                    continue;
                }

                $key = $subscriber->getUuid()->toRfc4122();

                if ($access->getAccessRole() === SubscriberAccountAccessRole::Owner) {
                    $ownerSubscribers[$key] = $subscriber;
                } else {
                    $representativeSubscribers[$key] = $subscriber;
                }
            }
        }

        return array_values($ownerSubscribers + $representativeSubscribers);
    }

    /**
     * @param list<Subscriber> $subscribers
     */
    private function nextUnlinkedSubscriber(array $subscribers): ?Subscriber
    {
        foreach ($subscribers as $subscriber) {
            if (!$subscriber->getUser() instanceof User) {
                return $subscriber;
            }
        }

        return null;
    }

    private function describeSubscriberPortalAccess(Workspace $workspace, Subscriber $subscriber): string
    {
        $accountNumbers = [];

        foreach ($this->subscriberAccountAccessRepository->findActiveBySubscriber($workspace, $subscriber) as $access) {
            $account = $access->getAccount();

            if ($account instanceof Account) {
                $accountNumbers[] = $account->getNumber();
            }
        }

        sort($accountNumbers);

        $accountSummary = $accountNumbers === []
            ? 'без участков'
            : sprintf('участки: %s', implode(', ', $accountNumbers));

        return sprintf('абонент %s, %s', $subscriber->getDisplayName(), $accountSummary);
    }

    /**
     * @return array{Workspace, bool}
     */
    private function createOrUpdateWorkspace(string $workspaceCode, string $workspaceName): array
    {
        $workspace = $this->workspaceRepository->findOneBy(['code' => $workspaceCode]);
        $created = false;

        if (!$workspace instanceof Workspace) {
            $workspace = (new Workspace())
                ->setCode($workspaceCode);

            $this->entityManager->persist($workspace);
            $created = true;
        }

        $workspace
            ->setName($workspaceName)
            ->setDescription('Демо-хозяйство для обучения операторов, демонстраций и проверки pilot-сценариев.')
            ->setTimezone(self::DEFAULT_TIMEZONE)
            ->touch();

        return [$workspace, $created];
    }

    /**
     * @return array{BillingSettings, bool}
     */
    private function createOrUpdateBillingSettings(Workspace $workspace, string $associationName): array
    {
        $billingSettings = $this->billingSettingsRepository->findOneByWorkspace($workspace);
        $created = false;

        if (!$billingSettings instanceof BillingSettings) {
            $billingSettings = new BillingSettings($workspace, $associationName);
            $this->entityManager->persist($billingSettings);
            $created = true;
        }

        $billingSettings
            ->setAssociationName($associationName)
            ->setInvoiceGenerationDay(self::DEFAULT_INVOICE_GENERATION_DAY)
            ->setReadingFreshnessWindowDays(self::DEFAULT_READING_FRESHNESS_WINDOW_DAYS)
            ->touch();

        return [$billingSettings, $created];
    }

    /**
     * @return array{list<string>, AccountGroup, AccountGroup}
     */
    private function createOrUpdateDemoAccountGroups(Workspace $workspace): array
    {
        $results = [];

        $summerGroup = $this->createOrUpdateAccountGroup(
            $workspace,
            'summer',
            'Летние',
            'Участки, которые обычно используют только в теплый сезон.',
            $results,
        );
        $yearRoundGroup = $this->createOrUpdateAccountGroup(
            $workspace,
            'year_round',
            'Круглогодичные',
            'Участки с регулярным проживанием или использованием круглый год.',
            $results,
        );

        return [$results, $summerGroup, $yearRoundGroup];
    }

    /**
     * @param list<string> $results
     */
    private function createOrUpdateAccountGroup(
        Workspace $workspace,
        string $code,
        string $name,
        string $description,
        array &$results,
    ): AccountGroup {
        $accountGroup = $this->accountGroupRepository->findOneActiveByWorkspaceAndCode($workspace, $code);
        $created = false;

        if (!$accountGroup instanceof AccountGroup) {
            $accountGroup = (new AccountGroup($workspace))->setCode($code);
            $this->entityManager->persist($accountGroup);
            $created = true;
        }

        $accountGroup
            ->setName($name)
            ->setDescription($description)
            ->touch();

        $results[] = sprintf('Группа участков %s: %s', $code, $created ? 'created' : 'updated');

        return $accountGroup;
    }

    /**
     * @param array{accountCount: int, ownerCount: int, representativeCount: int} $sizeConfig
     *
     * @return list<string>
     */
    private function createOrUpdateDemoAccountsAndSubscribers(
        Workspace $workspace,
        AccountGroup $summerGroup,
        AccountGroup $yearRoundGroup,
        array $sizeConfig,
        string $seed,
        DateTimeImmutable $asOf,
    ): array {
        $catalog = new DemoPeopleCatalog($seed);
        $validFrom = new DateTimeImmutable(sprintf('%d-01-01', ((int) $asOf->format('Y')) - 1), new DateTimeZone('UTC'));
        $owners = [];
        $representatives = [];

        for ($ownerNumber = 1; $ownerNumber <= $sizeConfig['ownerCount']; ++$ownerNumber) {
            $person = $ownerNumber <= $sizeConfig['representativeCount']
                ? $catalog->family($ownerNumber)->owner
                : $catalog->person($ownerNumber + $sizeConfig['representativeCount']);

            $owners[$ownerNumber] = $this->createOrUpdateSubscriber(
                $workspace,
                $person,
                sprintf('Демо-владелец #%d.', $ownerNumber),
            );
        }

        for ($representativeNumber = 1; $representativeNumber <= $sizeConfig['representativeCount']; ++$representativeNumber) {
            $representatives[$representativeNumber] = $this->createOrUpdateSubscriber(
                $workspace,
                $catalog->family($representativeNumber)->spouse,
                sprintf('Демо-представитель #%d.', $representativeNumber),
            );
        }

        $ownerAccessCount = 0;
        $representativeAccessCount = 0;
        $summerMembershipCount = 0;
        $yearRoundMembershipCount = 0;

        for ($accountNumber = 1; $accountNumber <= $sizeConfig['accountCount']; ++$accountNumber) {
            $account = $this->createOrUpdateAccount($workspace, $accountNumber);
            $ownerNumber = (($accountNumber - 1) % $sizeConfig['ownerCount']) + 1;
            $owner = $owners[$ownerNumber];

            $this->createOrUpdateSubscriberAccountAccess(
                $workspace,
                $owner,
                $account,
                SubscriberAccountAccessRole::Owner,
                'Демо-доступ владельца участка.',
            );
            ++$ownerAccessCount;

            if ($accountNumber <= $sizeConfig['representativeCount']) {
                $this->createOrUpdateSubscriberAccountAccess(
                    $workspace,
                    $representatives[$accountNumber],
                    $account,
                    SubscriberAccountAccessRole::Representative,
                    'Демо-доступ представителя владельца.',
                );
                ++$representativeAccessCount;
            }

            $targetGroup = $accountNumber % 3 === 0 ? $yearRoundGroup : $summerGroup;
            $this->createAccountGroupMemberIfMissing($workspace, $targetGroup, $account, $validFrom);

            if ($targetGroup === $yearRoundGroup) {
                ++$yearRoundMembershipCount;
            } else {
                ++$summerMembershipCount;
            }
        }

        return [
            sprintf('Участки: %d', $sizeConfig['accountCount']),
            sprintf('Абоненты-владельцы: %d', $sizeConfig['ownerCount']),
            sprintf('Абоненты-представители: %d', $sizeConfig['representativeCount']),
            sprintf('Доступы владельцев к участкам: %d', $ownerAccessCount),
            sprintf('Доступы представителей к участкам: %d', $representativeAccessCount),
            sprintf('Членство в группе "%s": %d', $summerGroup->getName(), $summerMembershipCount),
            sprintf('Членство в группе "%s": %d', $yearRoundGroup->getName(), $yearRoundMembershipCount),
        ];
    }

    private function createOrUpdateAccount(Workspace $workspace, int $number): Account
    {
        $accountNumber = sprintf('9-%03d', $number);
        $account = $this->accountRepository->findOneActiveByWorkspaceAndNumber($workspace, $accountNumber);

        if (!$account instanceof Account) {
            $account = (new Account($workspace))->setNumber($accountNumber);
            $this->entityManager->persist($account);
        }

        return $account
            ->setNotes('Демо-участок для обучения операторов.')
            ->touch();
    }

    private function createOrUpdateSubscriber(Workspace $workspace, DemoPerson $person, string $notes): Subscriber
    {
        $subscriber = $this->subscriberRepository->findOneActiveByWorkspaceAndContactEmail($workspace, $person->email);

        if (!$subscriber instanceof Subscriber) {
            $subscriber = new Subscriber($workspace);
            $this->entityManager->persist($subscriber);
        }

        return $subscriber
            ->setLastName($person->lastName)
            ->setFirstName($person->firstName)
            ->setSecondName($person->secondName)
            ->setContactEmail($person->email)
            ->setContactPhone($this->demoPhone($person->number))
            ->setNotes($notes)
            ->touch();
    }

    private function createOrUpdateSubscriberAccountAccess(
        Workspace $workspace,
        Subscriber $subscriber,
        Account $account,
        SubscriberAccountAccessRole $accessRole,
        string $notes,
    ): SubscriberAccountAccess {
        $access = $this->subscriberAccountAccessRepository->findOneActiveBySubscriberAndAccount(
            $workspace,
            $subscriber,
            $account,
        );

        if (!$access instanceof SubscriberAccountAccess) {
            $access = new SubscriberAccountAccess($workspace, $subscriber, $account, $accessRole);
            $this->entityManager->persist($access);
        }

        return $access
            ->setAccessRole($accessRole)
            ->setNotes($notes);
    }

    private function createAccountGroupMemberIfMissing(
        Workspace $workspace,
        AccountGroup $accountGroup,
        Account $account,
        DateTimeImmutable $validFrom,
    ): AccountGroupMember {
        $member = $this->accountGroupMemberRepository->findOneActiveByGroupAndAccount(
            $workspace,
            $accountGroup,
            $account,
        );

        if (!$member instanceof AccountGroupMember) {
            $member = new AccountGroupMember($workspace, $accountGroup, $account, $validFrom);
            $this->entityManager->persist($member);
        }

        return $member;
    }

    private function demoPhone(int $number): string
    {
        return sprintf('+7 000 000-%02d-%02d', intdiv($number, 100), $number % 100);
    }

    /**
     * @return list<string>
     */
    private function createOrUpdateDemoElectricityMeters(Workspace $workspace, DateTimeImmutable $asOf): array
    {
        $singleProfile = $this->tariffProfileRepository->findOneActiveByWorkspaceAndCode($workspace, 'single_rate');
        $twoRateProfile = $this->tariffProfileRepository->findOneActiveByWorkspaceAndCode($workspace, 'two_rate');
        $singleZone = $this->tariffZoneRepository->findOneActiveByWorkspaceAndCode($workspace, 'single');
        $dayZone = $this->tariffZoneRepository->findOneActiveByWorkspaceAndCode($workspace, 'day');
        $nightZone = $this->tariffZoneRepository->findOneActiveByWorkspaceAndCode($workspace, 'night');

        if (!$singleProfile instanceof ElectricityTariffProfile
            || !$twoRateProfile instanceof ElectricityTariffProfile
            || !$singleZone instanceof ElectricityTariffZone
            || !$dayZone instanceof ElectricityTariffZone
            || !$nightZone instanceof ElectricityTariffZone
        ) {
            throw new \LogicException('Demo electricity tariffs must be created before demo meters.');
        }

        $accounts = $this->accountRepository->findActiveByWorkspace($workspace);
        $installedOn = new DateTimeImmutable(sprintf('%d-01-01', ((int) $asOf->format('Y')) - 1), new DateTimeZone('UTC'));
        $verifiedOn = $installedOn->modify('-1 month');
        $verificationValidUntil = $verifiedOn->modify('+16 years');
        $singleMeterCount = 0;
        $twoRateMeterCount = 0;
        $registerCount = 0;
        $assignmentCount = 0;

        foreach ($accounts as $index => $account) {
            $accountNumber = $index + 1;
            $isTwoRate = $accountNumber % 5 === 0;
            $meter = $this->createOrUpdateElectricityMeter(
                workspace: $workspace,
                account: $account,
                accountNumber: $accountNumber,
                isTwoRate: $isTwoRate,
                installedOn: $installedOn,
                verifiedOn: $verifiedOn,
                verificationValidUntil: $verificationValidUntil,
            );

            if ($isTwoRate) {
                $this->createElectricityMeterRegisterIfMissing($workspace, $meter, $dayZone);
                $this->createElectricityMeterRegisterIfMissing($workspace, $meter, $nightZone);
                $this->createOrUpdateAccountTariffProfileAssignment($workspace, $account, $twoRateProfile, $installedOn);
                ++$twoRateMeterCount;
                $registerCount += 2;
            } else {
                $this->createElectricityMeterRegisterIfMissing($workspace, $meter, $singleZone);
                $this->createOrUpdateAccountTariffProfileAssignment($workspace, $account, $singleProfile, $installedOn);
                ++$singleMeterCount;
                ++$registerCount;
            }

            ++$assignmentCount;
        }

        return [
            sprintf('Однотарифные счетчики: %d', $singleMeterCount),
            sprintf('Двухтарифные счетчики: %d', $twoRateMeterCount),
            sprintf('Регистры счетчиков: %d', $registerCount),
            sprintf('Назначения тарифных профилей участкам: %d', $assignmentCount),
        ];
    }

    private function createOrUpdateElectricityMeter(
        Workspace $workspace,
        Account $account,
        int $accountNumber,
        bool $isTwoRate,
        DateTimeImmutable $installedOn,
        DateTimeImmutable $verifiedOn,
        DateTimeImmutable $verificationValidUntil,
    ): ElectricityMeter {
        $meter = $this->electricityMeterRepository->findOneActiveByWorkspaceAndAccount($workspace, $account);

        if (!$meter instanceof ElectricityMeter) {
            $meter = new ElectricityMeter($workspace, $account, $installedOn);
            $this->entityManager->persist($meter);
        }

        return $meter
            ->setSerialNumber(sprintf('DEMO-EL-%04d', $accountNumber))
            ->setModel($isTwoRate ? 'Меркурий 200.02 DEMO' : 'Меркурий 201.8 DEMO')
            ->setInstalledOn($installedOn)
            ->setVerifiedOn($verifiedOn)
            ->setVerificationValidUntil($verificationValidUntil)
            ->setNotes($isTwoRate ? 'Демо-двухтарифный электросчетчик.' : 'Демо-однотарифный электросчетчик.')
            ->touch();
    }

    private function createElectricityMeterRegisterIfMissing(
        Workspace $workspace,
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
    ): ElectricityMeterRegister {
        $register = $this->electricityMeterRegisterRepository->findOneByMeterAndTariffZone($workspace, $meter, $tariffZone);

        if (!$register instanceof ElectricityMeterRegister) {
            $register = new ElectricityMeterRegister($workspace, $meter, $tariffZone);
            $this->entityManager->persist($register);
        }

        return $register;
    }

    private function createOrUpdateAccountTariffProfileAssignment(
        Workspace $workspace,
        Account $account,
        ElectricityTariffProfile $tariffProfile,
        DateTimeImmutable $validFrom,
    ): AccountElectricityTariffProfileAssignment {
        $assignment = $this->accountTariffProfileAssignmentRepository->findOneOpenEndedByAccount($workspace, $account);

        if (!$assignment instanceof AccountElectricityTariffProfileAssignment) {
            $assignment = new AccountElectricityTariffProfileAssignment($workspace, $account, $tariffProfile, $validFrom);
            $this->entityManager->persist($assignment);
        }

        return $assignment
            ->setValidTo(null)
            ->setNotes('Демо-назначение тарифного профиля для участка.');
    }

    /**
     * @return list<string>
     */
    private function createOrUpdateDemoElectricityReadings(Workspace $workspace, DateTimeImmutable $asOf): array
    {
        $readingDates = $this->demoReadingDates($asOf);
        $meters = $this->electricityMeterRepository->findActiveByWorkspace($workspace);
        $activeReadingCount = 0;
        $twoRateReadingCount = 0;
        $supersededReadingCount = 0;
        $staleAccountNumbers = [];

        foreach ($meters as $meter) {
            $account = $meter->getAccount();

            if (!$account instanceof Account) {
                continue;
            }

            $accountNumber = $this->demoAccountNumberIndex($account);
            $provider = $this->findDemoReadingProvider($workspace, $account);
            $registers = $this->electricityMeterRegisterRepository->findByMeter($workspace, $meter);
            $currentValues = [];

            foreach ($registers as $register) {
                $tariffZone = $register->getTariffZone();

                if (!$tariffZone instanceof ElectricityTariffZone || $tariffZone->getCode() === null) {
                    continue;
                }

                $currentValues[$tariffZone->getUuid()->toRfc4122()] = $this->demoInitialReadingValue($accountNumber, $tariffZone->getCode());
            }

            foreach ($readingDates as $monthIndex => $takenOn) {
                if ($accountNumber === self::DEMO_STALE_READING_ACCOUNT_NUMBER && $monthIndex >= self::DEMO_READING_MONTH_COUNT - 2) {
                    $staleAccountNumbers[$accountNumber] = true;
                    continue;
                }

                foreach ($registers as $register) {
                    $tariffZone = $register->getTariffZone();

                    if (!$tariffZone instanceof ElectricityTariffZone || $tariffZone->getCode() === null) {
                        continue;
                    }

                    $tariffZoneUuid = $tariffZone->getUuid()->toRfc4122();
                    $currentValues[$tariffZoneUuid] += $this->demoMonthlyConsumptionKwh(
                        accountNumber: $accountNumber,
                        monthIndex: $monthIndex,
                        takenOn: $takenOn,
                        tariffZoneCode: $tariffZone->getCode(),
                    );

                    $reading = $this->createOrUpdateDemoReading(
                        workspace: $workspace,
                        meter: $meter,
                        tariffZone: $tariffZone,
                        readingValue: $this->formatReadingValue($currentValues[$tariffZoneUuid]),
                        takenOn: $takenOn,
                        source: ElectricityMeterReadingSource::Subscriber,
                        provider: $provider,
                        notes: 'Демо-показание электросчетчика за расчетный месяц.',
                    );

                    ++$activeReadingCount;

                    if ($tariffZone->getCode() !== 'single') {
                        ++$twoRateReadingCount;
                    }

                    if (
                        $accountNumber === self::DEMO_CORRECTED_READING_ACCOUNT_NUMBER
                        && self::DEMO_READING_MONTH_COUNT - $monthIndex === 3
                        && $tariffZone->getCode() === 'single'
                    ) {
                        $this->createOrUpdateSupersededDemoReading(
                            workspace: $workspace,
                            replacement: $reading,
                            wrongReadingValue: $this->formatReadingValue($currentValues[$tariffZoneUuid] + 42.5),
                            provider: $provider,
                        );
                        ++$supersededReadingCount;
                    }
                }
            }
        }

        return [
            sprintf('Активные показания: %d', $activeReadingCount),
            sprintf('Показания двухтарифных регистров: %d', $twoRateReadingCount),
            sprintf('Исправленные ошибочные показания: %d', $supersededReadingCount),
            sprintf('Участки без актуальных показаний: %d', count($staleAccountNumbers)),
        ];
    }

    /**
     * @return list<DateTimeImmutable>
     */
    private function demoReadingDates(DateTimeImmutable $asOf): array
    {
        $anchorMonth = new DateTimeImmutable($asOf->format('Y-m-01'), new DateTimeZone('UTC'));
        $dates = [];

        for ($offset = self::DEMO_READING_MONTH_COUNT - 1; $offset >= 0; --$offset) {
            $month = $anchorMonth->modify(sprintf('-%d months', $offset));
            $dates[] = new DateTimeImmutable($month->format('Y-m-04'), new DateTimeZone('UTC'));
        }

        return $dates;
    }

    private function createOrUpdateDemoReading(
        Workspace $workspace,
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
        string $readingValue,
        DateTimeImmutable $takenOn,
        ElectricityMeterReadingSource $source,
        ?Subscriber $provider,
        string $notes,
    ): ElectricityMeterReading {
        $reading = $this->electricityMeterReadingRepository->findOneActiveByMeterZoneAndTakenOn(
            $workspace,
            $meter,
            $tariffZone,
            $takenOn,
        );

        if (!$reading instanceof ElectricityMeterReading) {
            $reading = new ElectricityMeterReading($workspace, $meter, $tariffZone, $readingValue, $takenOn, $source);
            $this->entityManager->persist($reading);
        }

        return $reading
            ->setReadingValue($readingValue)
            ->setSource($source)
            ->setProvidedBySubscriber($provider)
            ->setNotes($notes)
            ->touch();
    }

    private function createOrUpdateSupersededDemoReading(
        Workspace $workspace,
        ElectricityMeterReading $replacement,
        string $wrongReadingValue,
        ?Subscriber $provider,
    ): ElectricityMeterReading {
        $meter = $replacement->getElectricityMeter();
        $tariffZone = $replacement->getTariffZone();

        if (!$meter instanceof ElectricityMeter || !$tariffZone instanceof ElectricityTariffZone) {
            throw new \LogicException('Replacement demo reading must have meter and tariff zone.');
        }

        $superseded = $this->electricityMeterReadingRepository->findOneSupersededByReplacement($workspace, $replacement);

        if (!$superseded instanceof ElectricityMeterReading) {
            $superseded = new ElectricityMeterReading(
                $workspace,
                $meter,
                $tariffZone,
                $wrongReadingValue,
                $replacement->getTakenOn(),
                ElectricityMeterReadingSource::Subscriber,
            );
            $this->entityManager->persist($superseded);
        }

        $superseded
            ->setReadingValue($wrongReadingValue)
            ->setSource(ElectricityMeterReadingSource::Subscriber)
            ->setProvidedBySubscriber($provider)
            ->setNotes('Демо-ошибочное показание, исправленное оператором.');

        if ($superseded->getReplacingReading() !== $replacement) {
            $superseded->markReplacedBy($replacement, 'Демо-исправление ошибочного показания.');
        } else {
            $superseded->touch();
        }

        $replacement
            ->setSource(ElectricityMeterReadingSource::Admin)
            ->setProvidedBySubscriber($provider)
            ->setNotes('Демо-исправленное показание вместо ошибочного значения.')
            ->touch();

        return $superseded;
    }

    private function findDemoReadingProvider(Workspace $workspace, Account $account): ?Subscriber
    {
        $firstSubscriber = null;

        foreach ($this->subscriberAccountAccessRepository->findActiveByAccount($workspace, $account) as $access) {
            $subscriber = $access->getSubscriber();

            if (!$subscriber instanceof Subscriber) {
                continue;
            }

            $firstSubscriber ??= $subscriber;

            if ($access->getAccessRole() === SubscriberAccountAccessRole::Owner) {
                return $subscriber;
            }
        }

        return $firstSubscriber;
    }

    private function demoAccountNumberIndex(Account $account): int
    {
        $number = $account->getNumber() ?? '';

        if (preg_match('/(\d+)$/', $number, $matches) === 1) {
            return (int) $matches[1];
        }

        return 1;
    }

    private function demoInitialReadingValue(int $accountNumber, string $tariffZoneCode): float
    {
        return match ($tariffZoneCode) {
            'day' => 1500 + ($accountNumber * 31),
            'night' => 500 + ($accountNumber * 17),
            default => 2200 + ($accountNumber * 43),
        };
    }

    private function demoMonthlyConsumptionKwh(
        int $accountNumber,
        int $monthIndex,
        DateTimeImmutable $takenOn,
        string $tariffZoneCode,
    ): float {
        $month = (int) $takenOn->format('n');
        $baseConsumption = 70 + (($accountNumber * 13 + $monthIndex * 17) % 55);

        if (in_array($month, [1, 2, 12], true)) {
            $baseConsumption += 55;
        }

        if ($accountNumber % 3 === 0) {
            $baseConsumption += 25;
        }

        if ($tariffZoneCode === 'day') {
            return floor($baseConsumption * 0.7);
        }

        if ($tariffZoneCode === 'night') {
            return $baseConsumption - floor($baseConsumption * 0.7);
        }

        return $baseConsumption;
    }

    private function formatReadingValue(float $value): string
    {
        return number_format($value, 3, '.', '');
    }

    /**
     * @return list<string>
     */
    private function createOrUpdateDemoFinancialScenarios(Workspace $workspace, DateTimeImmutable $asOf): array
    {
        $accounts = $this->accountRepository->findActiveByWorkspace($workspace);
        $periodStart = $this->demoFinancialPeriodStart($asOf);
        $periodEnd = $this->demoFinancialPeriodEnd($asOf);
        $accrualCount = 0;
        $paymentCount = 0;
        $debtCount = 0;
        $overpaymentCount = 0;
        $settledCount = 0;
        $missedPaymentCount = 0;

        foreach ($accounts as $account) {
            $accountNumber = $this->demoAccountNumberIndex($account);
            $scenario = $this->demoFinancialScenario($accountNumber);
            $accrualAmount = $this->demoFinancialAccrualAmount($accountNumber);

            $this->createOrUpdateDemoAccrual($workspace, $account, $periodStart, $periodEnd, $accrualAmount, $scenario);
            ++$accrualCount;

            if ($scenario === 'missed_payment') {
                ++$missedPaymentCount;
                continue;
            }

            $paymentAmount = match ($scenario) {
                'debt' => $this->formatMoney(max(1, ((float) $accrualAmount) - 500)),
                'overpayment' => $this->formatMoney(((float) $accrualAmount) + 600),
                default => $accrualAmount,
            };
            $provider = $this->findDemoReadingProvider($workspace, $account);

            $this->createOrUpdateDemoPayment(
                workspace: $workspace,
                account: $account,
                amount: $paymentAmount,
                paidOn: $this->demoPaymentDate($asOf, $accountNumber),
                payerName: $provider?->getDisplayName(),
                scenario: $scenario,
            );
            ++$paymentCount;

            if ($scenario === 'debt') {
                ++$debtCount;
            } elseif ($scenario === 'overpayment') {
                ++$overpaymentCount;
            } else {
                ++$settledCount;
            }
        }

        return [
            sprintf('Posted-начисления: %d', $accrualCount),
            sprintf('Активные оплаты: %d', $paymentCount),
            sprintf('Сценарии с долгом: %d', $debtCount + $missedPaymentCount),
            sprintf('Сценарии с переплатой: %d', $overpaymentCount),
            sprintf('Сценарии с ровным балансом: %d', $settledCount),
            sprintf('Сценарии с пропущенной оплатой: %d', $missedPaymentCount),
        ];
    }

    private function createOrUpdateDemoAccrual(
        Workspace $workspace,
        Account $account,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        string $amount,
        string $scenario,
    ): Accrual {
        $accrual = $this->accrualRepository->findOneActivePostedByAccountTypeAndPeriod(
            $workspace,
            $account,
            AccrualType::Electricity,
            $periodStart,
            $periodEnd,
        );

        if (!$accrual instanceof Accrual) {
            $accrual = new Accrual($workspace, $account, AccrualType::Electricity, $periodStart, $periodEnd, $amount);
            $accrual->post();
            $this->entityManager->persist($accrual);
        }

        return $accrual
            ->setAmount($amount)
            ->setCalculationVersion('demo-seed-v1')
            ->setNotes(sprintf('Демо-начисление электроэнергии: сценарий %s.', $scenario))
            ->touch();
    }

    private function createOrUpdateDemoPayment(
        Workspace $workspace,
        Account $account,
        string $amount,
        DateTimeImmutable $paidOn,
        ?string $payerName,
        string $scenario,
    ): Payment {
        $externalReference = sprintf(
            'DEMO-PAYMENT-%s-%s',
            $workspace->getCode(),
            $account->getNumber(),
        );
        $payment = $this->paymentRepository->findOneByWorkspaceAndExternalReference($workspace, $externalReference);

        if (!$payment instanceof Payment) {
            $payment = new Payment($workspace, $account, $amount, $paidOn, PaymentSource::Import);
            $this->entityManager->persist($payment);
        }

        return $payment
            ->setAmount($amount)
            ->setPaidOn($paidOn)
            ->setPaidAt(new DateTimeImmutable($paidOn->format('Y-m-d').' 12:00:00', new DateTimeZone('UTC')))
            ->setSource(PaymentSource::Import)
            ->setPayerName($payerName)
            ->setPurpose(sprintf('Демо-оплата электроэнергии, сценарий %s.', $scenario))
            ->setExternalReference($externalReference)
            ->touch();
    }

    private function demoFinancialPeriodStart(DateTimeImmutable $asOf): DateTimeImmutable
    {
        $anchorMonth = new DateTimeImmutable($asOf->format('Y-m-01'), new DateTimeZone('UTC'));

        return $anchorMonth->modify('-12 months');
    }

    private function demoFinancialPeriodEnd(DateTimeImmutable $asOf): DateTimeImmutable
    {
        return new DateTimeImmutable($asOf->format('Y-m-01'), new DateTimeZone('UTC'));
    }

    private function demoFinancialAccrualAmount(int $accountNumber): string
    {
        return $this->formatMoney(2000 + (($accountNumber * 137) % 900));
    }

    private function demoFinancialScenario(int $accountNumber): string
    {
        return match (($accountNumber - 1) % 4) {
            0 => 'debt',
            1 => 'overpayment',
            2 => 'missed_payment',
            default => 'settled',
        };
    }

    private function demoPaymentDate(DateTimeImmutable $asOf, int $accountNumber): DateTimeImmutable
    {
        return (new DateTimeImmutable($asOf->format('Y-m-d'), new DateTimeZone('UTC')))
            ->modify(sprintf('-%d days', 3 + ($accountNumber % 9)));
    }

    private function formatMoney(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @return array{list<string>, PaymentRequisiteProfile}
     */
    private function createOrUpdateDemoPaymentRequisites(Workspace $workspace, DateTimeImmutable $asOf): array
    {
        $validFrom = new DateTimeImmutable(sprintf('%d-01-01', ((int) $asOf->format('Y')) - 1), new DateTimeZone('UTC'));
        $profile = $this->paymentRequisiteProfileRepository->findOneActiveByWorkspaceAndCode(
            $workspace,
            self::DEMO_PAYMENT_REQUISITE_PROFILE_CODE,
        );
        $profileCreated = false;

        if (!$profile instanceof PaymentRequisiteProfile) {
            $profile = (new PaymentRequisiteProfile($workspace, $validFrom))
                ->setCode(self::DEMO_PAYMENT_REQUISITE_PROFILE_CODE);
            $this->entityManager->persist($profile);
            $profileCreated = true;
        }

        $profile
            ->setName('Демо-реквизиты для квитанций')
            ->setRecipientName('НКО "Демо-хозяйство КомУчёт"')
            ->setRecipientInn('7700000000')
            ->setRecipientKpp('770001001')
            ->setBankName('АО "Демо Банк"')
            ->setBankBik('044525000')
            ->setBankCorrespondentAccount('30101810000000000000')
            ->setBankAccount('40703810000000000001')
            ->setPaymentPurposeTemplate('Оплата по квитанции {statement_number}, участок {account_number}, {workspace_name}')
            ->setValidFrom($validFrom)
            ->setValidTo(null)
            ->touch();

        $currentDefaultAssignment = $this->paymentRequisiteAssignmentRepository->findCurrentByScope($workspace, null, $asOf);
        $assignmentCreated = false;
        $assignmentReplaced = false;

        if (
            $currentDefaultAssignment instanceof PaymentRequisiteAssignment
            && $currentDefaultAssignment->getPaymentRequisiteProfile()?->getUuid()->toRfc4122() !== $profile->getUuid()->toRfc4122()
        ) {
            $currentDefaultAssignment->close('Заменено демо-реквизитами.');
            $currentDefaultAssignment = null;
            $assignmentReplaced = true;
        }

        if (!$currentDefaultAssignment instanceof PaymentRequisiteAssignment) {
            $this->entityManager->persist(new PaymentRequisiteAssignment(
                $workspace,
                $profile,
                null,
                $validFrom,
            ));
            $assignmentCreated = true;
        }

        return [[
            sprintf('Профиль реквизитов: %s', $profileCreated ? 'created' : 'updated'),
            sprintf('Код профиля: %s', $profile->getCode()),
            sprintf('Назначение по умолчанию: %s', $assignmentCreated ? ($assignmentReplaced ? 'replaced' : 'created') : 'active'),
            'QR-код квитанции будет строиться из snapshot-реквизитов при открытии печатной формы или PDF.',
        ], $profile];
    }

    /**
     * @return list<string>
     */
    private function createOrUpdateDemoBillingRuns(Workspace $workspace, DateTimeImmutable $asOf): array
    {
        $anchorMonth = new DateTimeImmutable($asOf->format('Y-m-01'), new DateTimeZone('UTC'));
        $billingRuns = [
            $this->createOrUpdateDemoBillingRun(
                $workspace,
                $anchorMonth->modify('-4 months'),
                $anchorMonth->modify('-3 months'),
                true,
            ),
            $this->createOrUpdateDemoBillingRun(
                $workspace,
                $anchorMonth->modify('-3 months'),
                $anchorMonth->modify('-2 months'),
                true,
            ),
            $this->createOrUpdateDemoBillingRun(
                $workspace,
                $anchorMonth->modify('-1 month'),
                $anchorMonth,
                false,
            ),
        ];

        $postedRunCount = 0;
        $draftRunCount = 0;
        $openIssueCount = 0;
        $billingRunAccrualCount = 0;

        foreach ($billingRuns as $billingRun) {
            if ($billingRun->isPosted()) {
                ++$postedRunCount;
            } elseif ($billingRun->isDraft()) {
                ++$draftRunCount;
            }

            $openIssueCount += $this->billingRunAccountIssueRepository->countOpenByBillingRun($workspace, $billingRun);
            $billingRunAccrualCount += count($this->accrualRepository->findByBillingRun($workspace, $billingRun));
        }

        return [
            sprintf('Расчетные запуски: %d', count($billingRuns)),
            sprintf('Проведенные расчеты: %d', $postedRunCount),
            sprintf('Черновики расчетов: %d', $draftRunCount),
            sprintf('Начисления внутри расчетов: %d', $billingRunAccrualCount),
            sprintf('Открытые проблемы расчетов: %d', $openIssueCount),
        ];
    }

    private function createOrUpdateDemoBillingRun(
        Workspace $workspace,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $periodEnd,
        bool $postWhenPossible,
    ): BillingRun {
        $billingRun = $this->billingRunRepository->findOneActiveByKindAndPeriod(
            $workspace,
            BillingRunKind::Electricity,
            $periodStart,
            $periodEnd,
        );

        if (!$billingRun instanceof BillingRun) {
            $billingRun = new BillingRun($workspace, BillingRunKind::Electricity, $periodStart, $periodEnd);
            $this->entityManager->persist($billingRun);
        }

        if (!$billingRun->isDraft()) {
            return $billingRun;
        }

        $this->billingRunIssueGenerator->generateForDraft($billingRun);
        $this->entityManager->flush();

        $this->electricityBillingRunAccrualGenerator->generateForDraft($billingRun);
        $billingRun->markAccrualsGenerated();
        $this->entityManager->flush();

        if ($postWhenPossible && $this->billingRunAccountIssueRepository->countOpenByBillingRun($workspace, $billingRun) === 0) {
            $postedAccrualCount = 0;

            foreach ($this->accrualRepository->findByBillingRun($workspace, $billingRun) as $accrual) {
                if (!$accrual->isDraft()) {
                    continue;
                }

                $accrual->post();
                ++$postedAccrualCount;
            }

            if (0 < $postedAccrualCount) {
                $billingRun->post();
            }
        }

        return $billingRun;
    }

    /**
     * @return list<string>
     */
    private function createOrUpdateDemoStatementsAndDeliveries(
        Workspace $workspace,
        DateTimeImmutable $asOf,
        PaymentRequisiteProfile $paymentRequisiteProfile,
    ): array
    {
        $postedBillingRuns = $this->demoPostedBillingRuns($workspace, $asOf);
        $statements = [];
        $requisiteBackfillCount = 0;
        $statementsWithRequisitesCount = 0;

        foreach ($postedBillingRuns as $billingRun) {
            $this->createMissingDemoStatementsForPostedBillingRun($workspace, $billingRun, $asOf);
            $this->entityManager->flush();
            $runStatements = $this->accountStatementSnapshotRepository->findByBillingRun($workspace, $billingRun);

            foreach ($runStatements as $statement) {
                if (!$statement->hasPaymentRequisites()) {
                    $statement->applyPaymentRequisites(
                        $paymentRequisiteProfile,
                        $this->renderDemoPaymentPurpose($paymentRequisiteProfile, $statement),
                    );
                    ++$requisiteBackfillCount;
                }

                if ($statement->hasPaymentRequisites()) {
                    ++$statementsWithRequisitesCount;
                }

                if ($this->accountStatementDeliveryRepository->findByStatement($workspace, $statement) === []) {
                    $this->accountStatementDeliveryEnqueuer->enqueueForActiveAccountSubscribers($workspace, $statement);
                }

                $statements[] = $statement;
            }
        }

        $this->entityManager->flush();

        $deliveryCounts = $this->applyDemoDeliveryStates($workspace, $statements);

        return [
            sprintf('Snapshot-квитанции: %d', count($statements)),
            sprintf('Snapshot-квитанции с реквизитами: %d', $statementsWithRequisitesCount),
            sprintf('Snapshot-квитанции с backfill реквизитов: %d', $requisiteBackfillCount),
            sprintf('Доставки queued: %d', $deliveryCounts['queued']),
            sprintf('Доставки sent: %d', $deliveryCounts['sent']),
            sprintf('Доставки failed: %d', $deliveryCounts['failed']),
            sprintf('Доставки cancelled: %d', $deliveryCounts['cancelled']),
        ];
    }

    private function createMissingDemoStatementsForPostedBillingRun(
        Workspace $workspace,
        BillingRun $billingRun,
        DateTimeImmutable $statementDate,
    ): int {
        $createdCount = 0;

        foreach ($this->accrualRepository->findActivePostedAccountsByBillingRun($workspace, $billingRun) as $account) {
            $existingStatement = $this->accountStatementSnapshotRepository->findOneActiveByBillingRunAndAccount(
                $workspace,
                $billingRun,
                $account,
            );

            if ($existingStatement instanceof AccountStatementSnapshot) {
                continue;
            }

            $this->accountStatementSnapshotGenerator->generateCurrent(
                workspace: $workspace,
                account: $account,
                statementDate: $statementDate,
                billingRun: $billingRun,
            );
            ++$createdCount;
        }

        return $createdCount;
    }

    private function renderDemoPaymentPurpose(
        PaymentRequisiteProfile $profile,
        AccountStatementSnapshot $statement,
    ): string {
        $template = $profile->getPaymentPurposeTemplate() ?: 'Оплата по квитанции {statement_number}, участок {account_number}';

        return strtr($template, [
            '{statement_number}' => $statement->getNumber(),
            '{account_number}' => $statement->getAccountNumber(),
            '{statement_date}' => $statement->getStatementDate()->format('d.m.Y'),
            '{amount_to_pay}' => number_format((float) $statement->getAmountToPay(), 2, '.', ''),
            '{workspace_name}' => $statement->getWorkspaceName(),
        ]);
    }

    /**
     * @return list<BillingRun>
     */
    private function demoPostedBillingRuns(Workspace $workspace, DateTimeImmutable $asOf): array
    {
        $anchorMonth = new DateTimeImmutable($asOf->format('Y-m-01'), new DateTimeZone('UTC'));
        $postedRuns = [];

        foreach ([
            [$anchorMonth->modify('-4 months'), $anchorMonth->modify('-3 months')],
            [$anchorMonth->modify('-3 months'), $anchorMonth->modify('-2 months')],
        ] as [$periodStart, $periodEnd]) {
            $billingRun = $this->billingRunRepository->findOneActiveByKindAndPeriod(
                $workspace,
                BillingRunKind::Electricity,
                $periodStart,
                $periodEnd,
            );

            if ($billingRun instanceof BillingRun && $billingRun->isPosted()) {
                $postedRuns[] = $billingRun;
            }
        }

        return $postedRuns;
    }

    /**
     * @param list<AccountStatementSnapshot> $statements
     *
     * @return array{queued: int, sent: int, failed: int, cancelled: int}
     */
    private function applyDemoDeliveryStates(Workspace $workspace, array $statements): array
    {
        $deliveriesByStatement = $this->accountStatementDeliveryRepository->findByStatements($workspace, $statements);
        $deliveryIndex = 0;
        $counts = [
            'queued' => 0,
            'sent' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        foreach ($statements as $statement) {
            $deliveries = $deliveriesByStatement[$statement->getUuid()->toRfc4122()] ?? [];

            foreach ($deliveries as $delivery) {
                $attempt = $this->latestOrCreateDemoDeliveryAttempt($workspace, $delivery);

                match ($deliveryIndex % 4) {
                    1 => $attempt->markSucceeded(sprintf('demo-message-%04d', $deliveryIndex + 1)),
                    2 => $attempt->markFailed('Демо-ошибка SMTP: адрес временно недоступен.'),
                    3 => $delivery->isCancelled() ? $delivery : $delivery->cancel('Демо-отмена квитанции после пересмотра.'),
                    default => $attempt,
                };

                ++$deliveryIndex;
            }
        }

        $this->entityManager->flush();
        $deliveriesByStatement = $this->accountStatementDeliveryRepository->findByStatements($workspace, $statements);

        foreach ($deliveriesByStatement as $deliveries) {
            foreach ($deliveries as $delivery) {
                if ($delivery->isCancelled()) {
                    ++$counts['cancelled'];

                    continue;
                }

                $statusCode = $delivery->getLatestAttempt()?->getStatusCode() ?? 'queued';

                if (isset($counts[$statusCode])) {
                    ++$counts[$statusCode];
                }
            }
        }

        return $counts;
    }

    private function latestOrCreateDemoDeliveryAttempt(
        Workspace $workspace,
        AccountStatementDelivery $delivery,
    ): AccountStatementDeliveryAttempt {
        $attempt = $delivery->getLatestAttempt();

        if (!$attempt instanceof AccountStatementDeliveryAttempt) {
            $attempt = new AccountStatementDeliveryAttempt($workspace, $delivery, 1);
            $delivery->addAttempt($attempt);
            $this->entityManager->persist($attempt);
        }

        return $attempt;
    }

    /**
     * @return list<string>
     */
    private function createOrUpdateDemoTariffs(Workspace $workspace, DateTimeImmutable $asOf): array
    {
        $results = [];
        $validFrom = new DateTimeImmutable(sprintf('%d-01-01', ((int) $asOf->format('Y')) - 1), new DateTimeZone('UTC'));

        $singleZone = $this->createOrUpdateTariffZone(
            $workspace,
            'single',
            'Однотарифная',
            'Единая тарифная зона для однотарифных счетчиков.',
            10,
            $results,
        );
        $dayZone = $this->createOrUpdateTariffZone(
            $workspace,
            'day',
            'Дневная зона',
            'Демонстрационная дневная зона двухтарифного счетчика.',
            20,
            $results,
        );
        $nightZone = $this->createOrUpdateTariffZone(
            $workspace,
            'night',
            'Ночная зона',
            'Демонстрационная ночная зона двухтарифного счетчика.',
            30,
            $results,
        );

        $socialBand = $this->createOrUpdateConsumptionBand(
            $workspace,
            'social_norm',
            'Социальная норма',
            'Потребление в пределах месячной социальной нормы.',
            10,
            $results,
        );
        $aboveBand = $this->createOrUpdateConsumptionBand(
            $workspace,
            'above_social_norm',
            'Сверх социальной нормы',
            'Потребление сверх месячной социальной нормы.',
            20,
            $results,
        );

        $singleProfile = $this->createOrUpdateTariffProfile(
            $workspace,
            'single_rate',
            'Однотарифный',
            'Профиль для счетчиков с одним регистром.',
            $results,
        );
        $twoRateProfile = $this->createOrUpdateTariffProfile(
            $workspace,
            'two_rate',
            'Двухтарифный',
            'Профиль для счетчиков с дневным и ночным регистрами.',
            $results,
        );

        $singlePeriod = $this->createOrUpdateTariffPeriod($workspace, $singleProfile, $validFrom, $results);
        $twoRatePeriod = $this->createOrUpdateTariffPeriod($workspace, $twoRateProfile, $validFrom, $results);

        $this->createOrUpdateTariffRate($workspace, $singlePeriod, $singleZone, $socialBand, '5.500000', $results);
        $this->createOrUpdateTariffRate($workspace, $singlePeriod, $singleZone, $aboveBand, '7.800000', $results);
        $this->createOrUpdateTariffRate($workspace, $twoRatePeriod, $dayZone, $socialBand, '6.200000', $results);
        $this->createOrUpdateTariffRate($workspace, $twoRatePeriod, $dayZone, $aboveBand, '8.500000', $results);
        $this->createOrUpdateTariffRate($workspace, $twoRatePeriod, $nightZone, $socialBand, '2.400000', $results);
        $this->createOrUpdateTariffRate($workspace, $twoRatePeriod, $nightZone, $aboveBand, '3.200000', $results);

        foreach ([$singleProfile, $twoRateProfile] as $profile) {
            for ($month = 1; $month <= 12; ++$month) {
                $socialNormLimit = in_array($month, [1, 2, 12], true) ? '250.000' : '150.000';
                $rule = $this->createOrUpdateConsumptionBandRule($workspace, $profile, $validFrom, $month, $results);

                $this->createOrUpdateConsumptionBandRuleAllScope($workspace, $rule);
                $this->createOrUpdateConsumptionBandRuleRange($workspace, $rule, $socialBand, '0.000', $socialNormLimit);
                $this->createOrUpdateConsumptionBandRuleRange($workspace, $rule, $aboveBand, $socialNormLimit, null);
            }
        }

        $results[] = 'Правила социальных норм: 12 месяцев на каждый тарифный профиль; зима 250 кВт⋅ч, остальные месяцы 150 кВт⋅ч.';

        return $results;
    }

    /**
     * @param list<string> $results
     */
    private function createOrUpdateTariffZone(
        Workspace $workspace,
        string $code,
        string $name,
        string $description,
        int $sortOrder,
        array &$results,
    ): ElectricityTariffZone {
        $tariffZone = $this->tariffZoneRepository->findOneActiveByWorkspaceAndCode($workspace, $code);
        $created = false;

        if (!$tariffZone instanceof ElectricityTariffZone) {
            $tariffZone = (new ElectricityTariffZone($workspace))->setCode($code);
            $this->entityManager->persist($tariffZone);
            $created = true;
        }

        $tariffZone
            ->setName($name)
            ->setDescription($description)
            ->setSortOrder($sortOrder)
            ->touch();

        $results[] = sprintf('Тарифная зона %s: %s', $code, $created ? 'created' : 'updated');

        return $tariffZone;
    }

    /**
     * @param list<string> $results
     */
    private function createOrUpdateConsumptionBand(
        Workspace $workspace,
        string $code,
        string $name,
        string $description,
        int $sortOrder,
        array &$results,
    ): ElectricityConsumptionBand {
        $consumptionBand = $this->consumptionBandRepository->findOneActiveByWorkspaceAndCode($workspace, $code);
        $created = false;

        if (!$consumptionBand instanceof ElectricityConsumptionBand) {
            $consumptionBand = (new ElectricityConsumptionBand($workspace))->setCode($code);
            $this->entityManager->persist($consumptionBand);
            $created = true;
        }

        $consumptionBand
            ->setName($name)
            ->setDescription($description)
            ->setSortOrder($sortOrder)
            ->touch();

        $results[] = sprintf('Диапазон потребления %s: %s', $code, $created ? 'created' : 'updated');

        return $consumptionBand;
    }

    /**
     * @param list<string> $results
     */
    private function createOrUpdateTariffProfile(
        Workspace $workspace,
        string $code,
        string $name,
        string $description,
        array &$results,
    ): ElectricityTariffProfile {
        $tariffProfile = $this->tariffProfileRepository->findOneActiveByWorkspaceAndCode($workspace, $code);
        $created = false;

        if (!$tariffProfile instanceof ElectricityTariffProfile) {
            $tariffProfile = (new ElectricityTariffProfile($workspace))->setCode($code);
            $this->entityManager->persist($tariffProfile);
            $created = true;
        }

        $tariffProfile
            ->setName($name)
            ->setDescription($description)
            ->touch();

        $results[] = sprintf('Тарифный профиль %s: %s', $code, $created ? 'created' : 'updated');

        return $tariffProfile;
    }

    /**
     * @param list<string> $results
     */
    private function createOrUpdateTariffPeriod(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        DateTimeImmutable $validFrom,
        array &$results,
    ): ElectricityTariffPeriod {
        $tariffPeriod = null;

        foreach ($this->tariffPeriodRepository->findActiveByProfile($workspace, $tariffProfile) as $candidate) {
            if ($candidate->getValidFrom()->format('Y-m-d') === $validFrom->format('Y-m-d')) {
                $tariffPeriod = $candidate;
                break;
            }
        }

        $created = false;

        if (!$tariffPeriod instanceof ElectricityTariffPeriod) {
            $tariffPeriod = new ElectricityTariffPeriod($workspace, $tariffProfile, $validFrom);
            $this->entityManager->persist($tariffPeriod);
            $created = true;
        }

        $tariffPeriod
            ->setValidTo(null)
            ->setSourceDocument(self::DEMO_SOURCE_DOCUMENT)
            ->setNotes('Демонстрационный тарифный период для обучения.')
            ->touch();

        $results[] = sprintf(
            'Тарифный период %s с %s: %s',
            $tariffProfile->getCode(),
            $validFrom->format('Y-m-d'),
            $created ? 'created' : 'updated',
        );

        return $tariffPeriod;
    }

    /**
     * @param list<string> $results
     */
    private function createOrUpdateTariffRate(
        Workspace $workspace,
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffZone $tariffZone,
        ElectricityConsumptionBand $consumptionBand,
        string $rate,
        array &$results,
    ): ElectricityTariffRate {
        $tariffRate = $this->tariffRateRepository->findOneByPeriodZoneAndBand(
            $workspace,
            $tariffPeriod,
            $tariffZone,
            $consumptionBand,
        );
        $created = false;

        if (!$tariffRate instanceof ElectricityTariffRate) {
            $tariffRate = new ElectricityTariffRate($workspace, $tariffPeriod, $tariffZone, $consumptionBand, $rate);
            $this->entityManager->persist($tariffRate);
            $created = true;
        }

        $tariffRate
            ->setRate($rate)
            ->touch();

        $results[] = sprintf(
            'Ставка %s / %s / %s: %s',
            $tariffPeriod->getTariffProfile()?->getCode() ?? '<profile>',
            $tariffZone->getCode(),
            $consumptionBand->getCode(),
            $created ? 'created' : 'updated',
        );

        return $tariffRate;
    }

    /**
     * @param list<string> $results
     */
    private function createOrUpdateConsumptionBandRule(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        DateTimeImmutable $validFrom,
        int $month,
        array &$results,
    ): ElectricityConsumptionBandRule {
        $rule = null;

        foreach ($this->consumptionBandRuleRepository->findActiveByProfile($workspace, $tariffProfile) as $candidate) {
            if ($candidate->getMonth() === $month
                && $candidate->getPriority() === 100
                && $candidate->getValidFrom()->format('Y-m-d') === $validFrom->format('Y-m-d')
            ) {
                $rule = $candidate;
                break;
            }
        }

        if (!$rule instanceof ElectricityConsumptionBandRule) {
            $rule = new ElectricityConsumptionBandRule($workspace, $tariffProfile, $validFrom, $month);
            $this->entityManager->persist($rule);
        }

        $rule
            ->setValidTo(null)
            ->setAllocationMethod(ElectricityConsumptionBandAllocationMethod::TotalProportional)
            ->setPriority(100)
            ->setSourceDocument(self::DEMO_SOURCE_DOCUMENT)
            ->setNotes('Демонстрационная месячная социальная норма.')
            ->touch();

        return $rule;
    }

    private function createOrUpdateConsumptionBandRuleAllScope(
        Workspace $workspace,
        ElectricityConsumptionBandRule $rule,
    ): ElectricityConsumptionBandRuleAllScope {
        $allScope = $this->consumptionBandRuleAllScopeRepository->findOneByRule($workspace, $rule);

        if (!$allScope instanceof ElectricityConsumptionBandRuleAllScope) {
            $allScope = new ElectricityConsumptionBandRuleAllScope(
                $workspace,
                $rule,
                ElectricityConsumptionBandRuleScopeMode::Include,
            );
            $this->entityManager->persist($allScope);
        }

        return $allScope->setMode(ElectricityConsumptionBandRuleScopeMode::Include);
    }

    private function createOrUpdateConsumptionBandRuleRange(
        Workspace $workspace,
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBand $consumptionBand,
        string $lowerBoundKwh,
        ?string $upperBoundKwh,
    ): ElectricityConsumptionBandRuleRange {
        $range = $this->consumptionBandRuleRangeRepository->findOneByRuleAndBand($workspace, $rule, $consumptionBand);

        if (!$range instanceof ElectricityConsumptionBandRuleRange) {
            $range = new ElectricityConsumptionBandRuleRange(
                $workspace,
                $rule,
                $consumptionBand,
                $lowerBoundKwh,
                $upperBoundKwh,
            );
            $this->entityManager->persist($range);
        }

        return $range
            ->setLowerBoundKwh($lowerBoundKwh)
            ->setUpperBoundKwh($upperBoundKwh);
    }

    /**
     * @return list<string>
     */
    private function validateOptions(
        string $workspaceCode,
        string $workspaceName,
        string $size,
        string $asOfRaw,
        string $seed,
        bool $reset,
        ?string $confirm,
        bool $allowProdReset,
    ): array {
        $errors = [];

        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $workspaceCode)) {
            $errors[] = 'Код хозяйства должен соответствовать /^[a-z0-9][a-z0-9_-]*$/.';
        }

        if ($workspaceName === '') {
            $errors[] = 'Название хозяйства не должно быть пустым.';
        }

        if (!array_key_exists($size, self::DATASET_SIZES)) {
            $errors[] = 'Размер набора должен быть одним из: small, medium, large.';
        }

        if ($this->parseDate($asOfRaw) === null) {
            $errors[] = 'Опорная дата --as-of должна быть в формате YYYY-MM-DD.';
        }

        if ($seed === '') {
            $errors[] = 'Seed не должен быть пустым.';
        }

        if (!$reset) {
            return $errors;
        }

        if (!preg_match('/^demo(?:-|$)/', $workspaceCode)) {
            $errors[] = '--reset разрешен только для хозяйств с кодом demo или demo-*.';
        }

        if ($confirm !== self::RESET_CONFIRMATION) {
            $errors[] = '--reset требует явного подтверждения --confirm=demo.';
        }

        if ($this->kernelEnvironment === 'prod' && !$allowProdReset) {
            $errors[] = '--reset в prod-окружении требует дополнительный флаг --allow-prod-reset.';
        }

        return $errors;
    }

    /**
     * @param list<array{email: string, emailNormalized: string, roleCode: WorkspaceUserRoleCode}> $grantRequests
     *
     * @return list<string>
     */
    private function validateGrantRequests(array $grantRequests): array
    {
        $errors = [];

        foreach ($grantRequests as $grantRequest) {
            if (!filter_var($grantRequest['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = sprintf('Некорректный email для выдачи доступа: "%s".', $grantRequest['email']);
            }
        }

        return $errors;
    }

    /**
     * @param list<array{email: string, emailNormalized: string}> $grantRequests
     *
     * @return list<string>
     */
    private function validateSubscriberGrantRequests(array $grantRequests): array
    {
        $errors = [];

        foreach ($grantRequests as $grantRequest) {
            if (!filter_var($grantRequest['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = sprintf('Некорректный email для выдачи доступа к личному кабинету: "%s".', $grantRequest['email']);
            }
        }

        return $errors;
    }

    /**
     * @return list<array{email: string, emailNormalized: string, roleCode: WorkspaceUserRoleCode}>
     */
    private function collectGrantRequests(InputInterface $input): array
    {
        $requestsByKey = [];

        foreach ([
            'grant-admin-email' => WorkspaceUserRoleCode::Admin,
            'grant-operator-email' => WorkspaceUserRoleCode::Operator,
        ] as $optionName => $roleCode) {
            $values = $input->getOption($optionName);

            if (!is_array($values)) {
                continue;
            }

            foreach ($values as $value) {
                $email = trim(is_scalar($value) ? (string) $value : '');

                if ($email === '') {
                    continue;
                }

                $emailNormalized = UserEmailIdentity::normalizeEmail($email);
                $key = sprintf('%s:%s', $emailNormalized, $roleCode->value);
                $requestsByKey[$key] = [
                    'email' => $email,
                    'emailNormalized' => $emailNormalized,
                    'roleCode' => $roleCode,
                ];
            }
        }

        return array_values($requestsByKey);
    }

    /**
     * @return list<array{email: string, emailNormalized: string}>
     */
    private function collectSubscriberGrantRequests(InputInterface $input): array
    {
        $requestsByEmail = [];
        $values = $input->getOption('grant-subscriber-email');

        if (!is_array($values)) {
            return [];
        }

        foreach ($values as $value) {
            $email = trim(is_scalar($value) ? (string) $value : '');

            if ($email === '') {
                continue;
            }

            $emailNormalized = UserEmailIdentity::normalizeEmail($email);
            $requestsByEmail[$emailNormalized] = [
                'email' => $email,
                'emailNormalized' => $emailNormalized,
            ];
        }

        return array_values($requestsByEmail);
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();

        if (!$date instanceof DateTimeImmutable) {
            return null;
        }

        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $date;
    }

    private function stringOption(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);

        return trim(is_scalar($value) ? (string) $value : '');
    }

    private function nullableStringOption(InputInterface $input, string $name): ?string
    {
        $value = $this->stringOption($input, $name);

        return $value === '' ? null : $value;
    }
}
