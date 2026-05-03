<?php

namespace App\Custom\ZavetyMichurina\ElectricityStatementImport;

use App\Entity\Account;
use App\Entity\AccountElectricityTariffProfileAssignment;
use App\Entity\Accrual;
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
use App\Entity\Workspace;
use App\Entity\ZavetyMichurinaStatementImportFile;
use App\Enum\AccrualType;
use App\Enum\AuditLogSource;
use App\Enum\ElectricityConsumptionBandAllocationMethod;
use App\Enum\ElectricityConsumptionBandRuleScopeMode;
use App\Enum\ElectricityMeterReadingSource;
use App\Enum\PaymentSource;
use App\Enum\SubscriberAccountAccessRole;
use App\Enum\ZavetyMichurinaStatementImportFileStatus;
use App\Repository\AccountElectricityTariffProfileAssignmentRepository;
use App\Repository\AccountRepository;
use App\Repository\AccrualRepository;
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
use App\Repository\SubscriberAccountAccessRepository;
use App\Repository\SubscriberRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

final class ZavetyMichurinaStatementImportApplier
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly ZavetyMichurinaStatementImportPreviewBuilder $previewBuilder,
        private readonly AccountRepository $accountRepository,
        private readonly AccountElectricityTariffProfileAssignmentRepository $accountTariffProfileAssignmentRepository,
        private readonly SubscriberRepository $subscriberRepository,
        private readonly SubscriberAccountAccessRepository $subscriberAccountAccessRepository,
        private readonly ElectricityTariffZoneRepository $electricityTariffZoneRepository,
        private readonly ElectricityTariffProfileRepository $electricityTariffProfileRepository,
        private readonly ElectricityTariffPeriodRepository $electricityTariffPeriodRepository,
        private readonly ElectricityTariffRateRepository $electricityTariffRateRepository,
        private readonly ElectricityConsumptionBandRepository $electricityConsumptionBandRepository,
        private readonly ElectricityConsumptionBandRuleRepository $electricityConsumptionBandRuleRepository,
        private readonly ElectricityConsumptionBandRuleAllScopeRepository $electricityConsumptionBandRuleAllScopeRepository,
        private readonly ElectricityConsumptionBandRuleRangeRepository $electricityConsumptionBandRuleRangeRepository,
        private readonly ElectricityMeterRepository $electricityMeterRepository,
        private readonly ElectricityMeterRegisterRepository $electricityMeterRegisterRepository,
        private readonly ElectricityMeterReadingRepository $electricityMeterReadingRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentRequisiteProfileRepository $paymentRequisiteProfileRepository,
        private readonly PaymentRequisiteAssignmentRepository $paymentRequisiteAssignmentRepository,
        private readonly AccrualRepository $accrualRepository,
    ) {
    }

    public function apply(ZavetyMichurinaStatementImportFile $file, ?User $actor = null): ZavetyMichurinaStatementImportApplyResult
    {
        $workspace = $file->getWorkspace();
        $parsedResult = $file->getParsedResult();

        if (!$workspace instanceof Workspace) {
            throw new RuntimeException('Файл импорта не привязан к хозяйству.');
        }

        if ($file->getStatus() !== ZavetyMichurinaStatementImportFileStatus::Parsed) {
            throw new RuntimeException('Применять можно только распознанные файлы.');
        }

        if (!is_array($parsedResult)) {
            throw new RuntimeException('У файла нет результата распознавания.');
        }

        $preview = $this->previewBuilder->build($file);

        if (!($preview['can_apply'] ?? false)) {
            throw new RuntimeException('В предпросмотре есть блокеры. Применение остановлено.');
        }

        $result = new ZavetyMichurinaStatementImportApplyResult();

        $this->entityManager->wrapInTransaction(function () use ($file, $workspace, $parsedResult, $actor, $result): void {
            $rows = $this->normalizedRows($parsedResult['rows'] ?? []);

            if ($rows === []) {
                throw new RuntimeException('В PDF нет строк для применения.');
            }

            $account = $this->findOrCreateAccount($workspace, $parsedResult, $actor, $result);
            $subscriber = $this->findOrCreateSubscriber($workspace, $parsedResult, $actor, $result);
            $this->findOrCreateAccess($workspace, $account, $subscriber, $actor, $result);
            $this->findOrCreatePaymentRequisites($workspace, $parsedResult, $rows, $actor, $result);
            $socialBand = $this->findOrCreateConsumptionBand($workspace, 'social_norm', 'Социальная норма', 10, $actor, $result);
            $aboveBand = $this->findOrCreateConsumptionBand($workspace, 'above_social_norm', 'Сверх социальной нормы', 20, $actor, $result);
            $tariffProfile = $this->findOrCreateTariffProfile($workspace, $actor, $result);
            $this->findOrCreateAccountTariffProfileAssignment($workspace, $account, $tariffProfile, $rows, $actor, $result);

            $tariffZone = $this->findOrCreateSingleTariffZone($workspace, $actor, $result);

            if ($tariffZone instanceof ElectricityTariffZone) {
                $this->applyTariffPeriodsAndRates($workspace, $tariffProfile, $tariffZone, $socialBand, $aboveBand, $file, $rows, $actor, $result);
                $this->applyMetersAndReadings($workspace, $account, $subscriber, $tariffZone, $parsedResult, $rows, $actor, $result);
            }

            $this->applyConsumptionBandRules($workspace, $tariffProfile, $socialBand, $aboveBand, $file, $rows, $actor, $result);
            $this->applyPayments($workspace, $account, $subscriber, $parsedResult, $rows, $actor, $result);
            $this->applyAccruals($workspace, $account, $rows, $actor, $result);

            if ($result->skippedTotal() === 0) {
                $file->markApplied($actor);
            } else {
                $file->touch($actor);
            }

            $this->auditLogger->record(
                action: 'zavety_michurina_statement_import.applied',
                workspace: $workspace,
                entityTable: 'zavety_michurina_statement_import_files',
                entityUuid: $file->getUuid(),
                newValues: [
                    'original_filename' => $file->getOriginalFilename(),
                    'status' => $file->getStatus()->value,
                    'result' => $result->toArray(),
                ],
                changedFields: ['status', 'updated_at', 'updated_by'],
                source: AuditLogSource::Import,
            );
        });

        return $result;
    }

    private function findOrCreateAccount(
        Workspace $workspace,
        array $parsedResult,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): Account {
        $accountNumber = $this->requiredString($parsedResult['account']['number'] ?? null, 'В PDF не найден номер участка.');
        $account = $this->accountRepository->findOneActiveByWorkspaceAndNumber($workspace, $accountNumber);

        if ($account instanceof Account) {
            $result->reused('accounts');

            return $account;
        }

        $account = (new Account($workspace))
            ->setNumber($accountNumber)
            ->setNotes('Создано импортом PDF-квитанции ЗМ.');
        $account->setCreatedBy($actor);
        $this->entityManager->persist($account);
        $result->created('accounts');

        $this->auditLogger->record(
            action: 'account.created',
            workspace: $workspace,
            entityTable: 'accounts',
            entityUuid: $account->getUuid(),
            newValues: [
                'number' => $account->getNumber(),
                'notes' => $account->getNotes(),
            ],
            changedFields: ['number', 'notes'],
            source: AuditLogSource::Import,
        );

        return $account;
    }

    private function findOrCreateSubscriber(
        Workspace $workspace,
        array $parsedResult,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): Subscriber {
        $fullName = $this->requiredString($parsedResult['subscriber']['full_name'] ?? null, 'В PDF не найдено ФИО абонента.');
        $name = $this->parseSubscriberName($fullName);

        if ($name === null) {
            throw new RuntimeException(sprintf('Не удалось разобрать ФИО "%s".', $fullName));
        }

        $subscriber = $this->subscriberRepository->findOneActiveByWorkspaceAndName(
            $workspace,
            $name['last_name'],
            $name['first_name'],
            $name['second_name'],
        );

        if ($subscriber instanceof Subscriber) {
            $result->reused('subscribers');

            return $subscriber;
        }

        $subscriber = (new Subscriber($workspace))
            ->setLastName($name['last_name'])
            ->setFirstName($name['first_name'])
            ->setSecondName($name['second_name'])
            ->setNotes('Создано импортом PDF-квитанции ЗМ.');
        $subscriber->setCreatedBy($actor);
        $this->entityManager->persist($subscriber);
        $result->created('subscribers');

        $this->auditLogger->record(
            action: 'subscriber.created',
            workspace: $workspace,
            entityTable: 'subscribers',
            entityUuid: $subscriber->getUuid(),
            newValues: [
                'last_name' => $subscriber->getLastName(),
                'first_name' => $subscriber->getFirstName(),
                'second_name' => $subscriber->getSecondName(),
                'display_name' => $subscriber->getDisplayName(),
                'notes' => $subscriber->getNotes(),
            ],
            changedFields: ['last_name', 'first_name', 'second_name', 'notes'],
            source: AuditLogSource::Import,
        );

        return $subscriber;
    }

    private function findOrCreateAccess(
        Workspace $workspace,
        Account $account,
        Subscriber $subscriber,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $access = $this->subscriberAccountAccessRepository->findOneActiveBySubscriberAndAccount($workspace, $subscriber, $account);

        if ($access instanceof SubscriberAccountAccess) {
            $result->reused('subscriber_account_accesses');

            return;
        }

        $access = new SubscriberAccountAccess($workspace, $subscriber, $account, SubscriberAccountAccessRole::Owner, $actor);
        $access->setNotes('Создано импортом PDF-квитанции ЗМ.');
        $this->entityManager->persist($access);
        $result->created('subscriber_account_accesses');

        $this->auditLogger->record(
            action: 'subscriber_account_access.granted',
            workspace: $workspace,
            entityTable: 'subscriber_account_accesses',
            entityPk: [
                'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
                'subscriber_uuid' => $subscriber->getUuid()->toRfc4122(),
                'account_uuid' => $account->getUuid()->toRfc4122(),
            ],
            newValues: [
                'subscriber_uuid' => $subscriber->getUuid()->toRfc4122(),
                'subscriber_name' => $subscriber->getDisplayName(),
                'account_uuid' => $account->getUuid()->toRfc4122(),
                'account_number' => $account->getNumber(),
                'access_role' => $access->getAccessRole()->value,
                'notes' => $access->getNotes(),
            ],
            changedFields: ['subscriber_uuid', 'account_uuid', 'access_role', 'notes'],
            source: AuditLogSource::Import,
        );
    }

    private function findOrCreatePaymentRequisites(
        Workspace $workspace,
        array $parsedResult,
        array $rows,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $requisites = is_array($parsedResult['payment_requisites'] ?? null) ? $parsedResult['payment_requisites'] : [];
        $bankAccount = $this->optionalString($requisites['bank_account'] ?? null);
        $bankBik = $this->optionalString($requisites['bank_bik'] ?? null);

        if ($bankAccount === null || $bankBik === null) {
            $result->skipped('payment_requisite_profiles', 'В PDF нет полного набора банковских реквизитов.');

            return;
        }

        foreach ($this->paymentRequisiteProfileRepository->findActiveByWorkspace($workspace) as $profile) {
            if ($profile instanceof PaymentRequisiteProfile && $profile->getBankAccount() === $bankAccount && $profile->getBankBik() === $bankBik) {
                $result->reused('payment_requisite_profiles');
                $this->ensureElectricityPaymentRequisiteAssignment($workspace, $profile, $this->firstPeriodStart($rows) ?? new DateTimeImmutable('today'), $actor, $result);

                return;
            }
        }

        $profile = (new PaymentRequisiteProfile($workspace, $this->firstPeriodStart($rows) ?? new DateTimeImmutable('today')))
            ->setCode($this->uniquePaymentRequisiteCode($workspace))
            ->setName('Импорт ЗМ: электроэнергия')
            ->setRecipientName($this->requiredString($requisites['recipient_name'] ?? null, 'В PDF нет получателя платежа.'))
            ->setRecipientInn($this->optionalString($requisites['recipient_inn'] ?? null))
            ->setRecipientKpp($this->optionalString($requisites['recipient_kpp'] ?? null))
            ->setBankName($this->requiredString($requisites['bank_name'] ?? null, 'В PDF нет названия банка.'))
            ->setBankBik($bankBik)
            ->setBankAccount($bankAccount)
            ->setPaymentPurposeTemplate($this->optionalString($requisites['payment_purpose'] ?? null));
        $profile->setCreatedBy($actor);
        $this->entityManager->persist($profile);
        $result->created('payment_requisite_profiles');

        $this->auditLogger->record(
            action: 'payment_requisite_profile.created',
            workspace: $workspace,
            entityTable: 'payment_requisite_profiles',
            entityUuid: $profile->getUuid(),
            newValues: [
                'code' => $profile->getCode(),
                'name' => $profile->getName(),
                'recipient_name' => $profile->getRecipientName(),
                'recipient_inn' => $profile->getRecipientInn(),
                'recipient_kpp' => $profile->getRecipientKpp(),
                'bank_name' => $profile->getBankName(),
                'bank_bik' => $profile->getBankBik(),
                'bank_account' => $profile->getBankAccount(),
                'payment_purpose_template' => $profile->getPaymentPurposeTemplate(),
                'valid_from' => $profile->getValidFrom()->format('Y-m-d'),
            ],
            changedFields: ['code', 'name', 'recipient_name', 'recipient_inn', 'recipient_kpp', 'bank_name', 'bank_bik', 'bank_account', 'payment_purpose_template', 'valid_from'],
            source: AuditLogSource::Import,
        );

        $this->ensureElectricityPaymentRequisiteAssignment($workspace, $profile, $profile->getValidFrom(), $actor, $result);
    }

    private function ensureElectricityPaymentRequisiteAssignment(
        Workspace $workspace,
        PaymentRequisiteProfile $profile,
        DateTimeImmutable $validFrom,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $currentAssignment = null;

        foreach ($this->paymentRequisiteAssignmentRepository->findOpenByWorkspace($workspace) as $assignment) {
            if ($assignment->getAccrualType() === AccrualType::Electricity) {
                $currentAssignment = $assignment;

                break;
            }
        }

        if (
            $currentAssignment instanceof PaymentRequisiteAssignment
            && $currentAssignment->getPaymentRequisiteProfile()?->getUuid()->equals($profile->getUuid())
        ) {
            $result->reused('payment_requisite_assignments');

            return;
        }

        if ($currentAssignment instanceof PaymentRequisiteAssignment) {
            $result->skipped('payment_requisite_assignments', 'В хозяйстве уже назначены другие реквизиты для электроэнергии.');

            return;
        }

        $assignment = new PaymentRequisiteAssignment(
            $workspace,
            $profile,
            AccrualType::Electricity,
            $validFrom,
            $actor,
        );
        $this->entityManager->persist($assignment);
        $result->created('payment_requisite_assignments');

        $this->auditLogger->record(
            action: 'payment_requisite_assignment.created',
            workspace: $workspace,
            entityTable: 'payment_requisite_assignments',
            entityUuid: $assignment->getUuid(),
            newValues: [
                'payment_requisite_profile_uuid' => $profile->getUuid()->toRfc4122(),
                'payment_requisite_profile_code' => $profile->getCode(),
                'accrual_type' => AccrualType::Electricity->value,
                'valid_from' => $assignment->getValidFrom()->format('Y-m-d'),
            ],
            changedFields: ['payment_requisite_profile_uuid', 'accrual_type', 'valid_from'],
            source: AuditLogSource::Import,
        );
    }

    private function findOrCreateConsumptionBand(
        Workspace $workspace,
        string $code,
        string $name,
        int $sortOrder,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): ElectricityConsumptionBand {
        $band = $this->electricityConsumptionBandRepository->findOneActiveByWorkspaceAndCode($workspace, $code);

        if ($band instanceof ElectricityConsumptionBand) {
            $result->reused('electricity_consumption_bands');

            return $band;
        }

        $band = (new ElectricityConsumptionBand($workspace))
            ->setCode($code)
            ->setName($name)
            ->setSortOrder($sortOrder)
            ->setDescription('Создано импортом PDF-квитанции ЗМ.');
        $band->setCreatedBy($actor);
        $this->entityManager->persist($band);
        $result->created('electricity_consumption_bands');

        $this->auditLogger->record(
            action: 'electricity_consumption_band.created',
            workspace: $workspace,
            entityTable: 'electricity_consumption_bands',
            entityUuid: $band->getUuid(),
            newValues: [
                'code' => $band->getCode(),
                'name' => $band->getName(),
                'sort_order' => $band->getSortOrder(),
                'description' => $band->getDescription(),
            ],
            changedFields: ['code', 'name', 'sort_order', 'description'],
            source: AuditLogSource::Import,
        );

        return $band;
    }

    private function findOrCreateTariffProfile(
        Workspace $workspace,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): ElectricityTariffProfile {
        $profile = $this->electricityTariffProfileRepository->findOneActiveByWorkspaceAndCode($workspace, 'imported_electricity');

        if ($profile instanceof ElectricityTariffProfile) {
            $result->reused('electricity_tariff_profiles');

            return $profile;
        }

        $profile = (new ElectricityTariffProfile($workspace))
            ->setCode('imported_electricity')
            ->setName('Импортированная электроэнергия')
            ->setDescription('Создано импортом PDF-квитанций ЗМ.');
        $profile->setCreatedBy($actor);
        $this->entityManager->persist($profile);
        $result->created('electricity_tariff_profiles');

        $this->auditLogger->record(
            action: 'electricity_tariff_profile.created',
            workspace: $workspace,
            entityTable: 'electricity_tariff_profiles',
            entityUuid: $profile->getUuid(),
            newValues: [
                'code' => $profile->getCode(),
                'name' => $profile->getName(),
                'description' => $profile->getDescription(),
            ],
            changedFields: ['code', 'name', 'description'],
            source: AuditLogSource::Import,
        );

        return $profile;
    }

    private function findOrCreateAccountTariffProfileAssignment(
        Workspace $workspace,
        Account $account,
        ElectricityTariffProfile $tariffProfile,
        array $rows,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $validFrom = $this->firstPeriodStart($rows);

        if (!$validFrom instanceof DateTimeImmutable) {
            $result->skipped('account_electricity_tariff_profile_assignments', 'В PDF нет периода для назначения тарифного профиля участку.');

            return;
        }

        $openAssignment = $this->accountTariffProfileAssignmentRepository->findOneOpenEndedByAccount($workspace, $account);

        if ($openAssignment instanceof AccountElectricityTariffProfileAssignment) {
            if ($openAssignment->getTariffProfile()?->getUuid()->equals($tariffProfile->getUuid())) {
                $result->reused('account_electricity_tariff_profile_assignments');
            } else {
                $result->skipped(
                    'account_electricity_tariff_profile_assignments',
                    sprintf('У участка %s уже есть открытое назначение другого тарифного профиля.', $account->getNumber()),
                );
            }

            return;
        }

        $assignment = (new AccountElectricityTariffProfileAssignment($workspace, $account, $tariffProfile, $validFrom, $actor))
            ->setNotes('Создано импортом PDF-квитанции ЗМ.');
        $this->entityManager->persist($assignment);
        $result->created('account_electricity_tariff_profile_assignments');

        $this->auditLogger->record(
            action: 'account_tariff_profile_assignment.created',
            workspace: $workspace,
            entityTable: 'account_electricity_tariff_profile_assignments',
            entityPk: [
                'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
                'account_uuid' => $account->getUuid()->toRfc4122(),
                'valid_from' => $assignment->getValidFrom()->format('Y-m-d'),
            ],
            newValues: [
                'account_uuid' => $account->getUuid()->toRfc4122(),
                'account_number' => $account->getNumber(),
                'tariff_profile_uuid' => $tariffProfile->getUuid()->toRfc4122(),
                'tariff_profile_code' => $tariffProfile->getCode(),
                'valid_from' => $assignment->getValidFrom()->format('Y-m-d'),
                'valid_to' => $assignment->getValidTo()?->format('Y-m-d'),
                'notes' => $assignment->getNotes(),
            ],
            changedFields: ['account_uuid', 'tariff_profile_uuid', 'valid_from', 'valid_to', 'notes'],
            source: AuditLogSource::Import,
        );
    }

    private function findOrCreateSingleTariffZone(
        Workspace $workspace,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): ?ElectricityTariffZone {
        $zones = $this->electricityTariffZoneRepository->findActiveByWorkspace($workspace);

        if (count($zones) === 1) {
            $result->reused('electricity_tariff_zones');

            return $zones[0];
        }

        if (count($zones) > 1) {
            $result->skipped('electricity_meter_readings', 'В хозяйстве несколько тарифных зон; импорт показаний требует ручного выбора зоны.');

            return null;
        }

        $zone = (new ElectricityTariffZone($workspace))
            ->setCode('single')
            ->setName('Однотарифная')
            ->setDescription('Создано импортом PDF-квитанции ЗМ.')
            ->setSortOrder(10);
        $zone->setCreatedBy($actor);
        $this->entityManager->persist($zone);
        $result->created('electricity_tariff_zones');

        $this->auditLogger->record(
            action: 'electricity_tariff_zone.created',
            workspace: $workspace,
            entityTable: 'electricity_tariff_zones',
            entityUuid: $zone->getUuid(),
            newValues: [
                'code' => $zone->getCode(),
                'name' => $zone->getName(),
                'sort_order' => $zone->getSortOrder(),
                'description' => $zone->getDescription(),
            ],
            changedFields: ['code', 'name', 'sort_order', 'description'],
            source: AuditLogSource::Import,
        );

        return $zone;
    }

    private function applyTariffPeriodsAndRates(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        ElectricityTariffZone $tariffZone,
        ElectricityConsumptionBand $socialBand,
        ElectricityConsumptionBand $aboveBand,
        ZavetyMichurinaStatementImportFile $file,
        array $rows,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $segments = $this->buildTariffRateSegments($rows);

        if ($segments === []) {
            $result->skipped('electricity_tariff_periods', 'В PDF нет ставок тарифов для создания тарифных периодов.');

            return;
        }

        foreach ($segments as $segment) {
            $validFrom = $this->dateOrNull($segment['valid_from']);
            $validTo = $this->dateOrNull($segment['valid_to']);
            $socialRate = $this->optionalDecimal($segment['social_norm_rate'] ?? null);
            $aboveRate = $this->optionalDecimal($segment['above_norm_rate'] ?? null);

            if (!$validFrom instanceof DateTimeImmutable || $socialRate === null || $aboveRate === null) {
                $result->skipped('electricity_tariff_periods', 'Тарифный период из PDF без даты или ставок пропущен.');
                continue;
            }

            $tariffPeriod = $this->findExactTariffPeriod($workspace, $tariffProfile, $validFrom);

            if ($tariffPeriod instanceof ElectricityTariffPeriod) {
                if (!$this->sameNullableDate($tariffPeriod->getValidTo(), $validTo)) {
                    $result->skipped(
                        'electricity_tariff_periods',
                        sprintf('Тарифный период с %s уже есть, но дата окончания отличается.', $validFrom->format('Y-m-d')),
                    );
                    continue;
                }

                $result->reused('electricity_tariff_periods');
            } else {
                $overlappingPeriod = $this->electricityTariffPeriodRepository->findOverlappingActivePeriod(
                    $workspace,
                    $tariffProfile,
                    $validFrom,
                    $validTo,
                );

                if ($overlappingPeriod instanceof ElectricityTariffPeriod) {
                    $result->skipped(
                        'electricity_tariff_periods',
                        sprintf('Тарифный период с %s пересекается с уже существующим периодом с %s.', $validFrom->format('Y-m-d'), $overlappingPeriod->getValidFrom()->format('Y-m-d')),
                    );
                    continue;
                }

                $tariffPeriod = (new ElectricityTariffPeriod($workspace, $tariffProfile, $validFrom))
                    ->setValidTo($validTo)
                    ->setSourceDocument($this->sourceDocument($file))
                    ->setNotes('Создано импортом PDF-квитанции ЗМ.');
                $tariffPeriod->setCreatedBy($actor);
                $this->entityManager->persist($tariffPeriod);
                $result->created('electricity_tariff_periods');

                $this->auditLogger->record(
                    action: 'electricity_tariff_period.created',
                    workspace: $workspace,
                    entityTable: 'electricity_tariff_periods',
                    entityUuid: $tariffPeriod->getUuid(),
                    newValues: [
                        'tariff_profile_uuid' => $tariffProfile->getUuid()->toRfc4122(),
                        'tariff_profile_code' => $tariffProfile->getCode(),
                        'valid_from' => $tariffPeriod->getValidFrom()->format('Y-m-d'),
                        'valid_to' => $tariffPeriod->getValidTo()?->format('Y-m-d'),
                        'source_document' => $tariffPeriod->getSourceDocument(),
                        'notes' => $tariffPeriod->getNotes(),
                    ],
                    changedFields: ['tariff_profile_uuid', 'valid_from', 'valid_to', 'source_document', 'notes'],
                    source: AuditLogSource::Import,
                );
            }

            $this->findOrCreateTariffRate($workspace, $tariffPeriod, $tariffZone, $socialBand, $socialRate, $result);
            $this->findOrCreateTariffRate($workspace, $tariffPeriod, $tariffZone, $aboveBand, $aboveRate, $result);
        }
    }

    private function findOrCreateTariffRate(
        Workspace $workspace,
        ElectricityTariffPeriod $tariffPeriod,
        ElectricityTariffZone $tariffZone,
        ElectricityConsumptionBand $consumptionBand,
        string $rateValue,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $tariffRate = $this->electricityTariffRateRepository->findOneByPeriodZoneAndBand(
            $workspace,
            $tariffPeriod,
            $tariffZone,
            $consumptionBand,
        );

        if ($tariffRate instanceof ElectricityTariffRate) {
            if (bccomp($tariffRate->getRate(), $rateValue, 6) === 0) {
                $result->reused('electricity_tariff_rates');
            } else {
                $result->skipped(
                    'electricity_tariff_rates',
                    sprintf(
                        'Ставка для периода %s, зоны %s, диапазона %s уже есть, но отличается: в системе %s, в PDF %s.',
                        $tariffPeriod->getValidFrom()->format('Y-m-d'),
                        $tariffZone->getCode(),
                        $consumptionBand->getCode(),
                        $tariffRate->getRate(),
                        $rateValue,
                    ),
                );
            }

            return;
        }

        $tariffRate = new ElectricityTariffRate($workspace, $tariffPeriod, $tariffZone, $consumptionBand, $rateValue);
        $this->entityManager->persist($tariffRate);
        $result->created('electricity_tariff_rates');

        $this->auditLogger->record(
            action: 'electricity_tariff_rate.created',
            workspace: $workspace,
            entityTable: 'electricity_tariff_rates',
            entityPk: [
                'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
                'tariff_period_uuid' => $tariffPeriod->getUuid()->toRfc4122(),
                'tariff_zone_uuid' => $tariffZone->getUuid()->toRfc4122(),
                'consumption_band_uuid' => $consumptionBand->getUuid()->toRfc4122(),
            ],
            newValues: [
                'tariff_period_uuid' => $tariffPeriod->getUuid()->toRfc4122(),
                'tariff_zone_uuid' => $tariffZone->getUuid()->toRfc4122(),
                'tariff_zone_code' => $tariffZone->getCode(),
                'consumption_band_uuid' => $consumptionBand->getUuid()->toRfc4122(),
                'consumption_band_code' => $consumptionBand->getCode(),
                'rate' => $tariffRate->getRate(),
            ],
            changedFields: ['tariff_period_uuid', 'tariff_zone_uuid', 'consumption_band_uuid', 'rate'],
            source: AuditLogSource::Import,
        );
    }

    private function applyConsumptionBandRules(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        ElectricityConsumptionBand $socialBand,
        ElectricityConsumptionBand $aboveBand,
        ZavetyMichurinaStatementImportFile $file,
        array $rows,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $segments = $this->buildSocialNormRuleSegments($rows);

        if ($segments === []) {
            $result->skipped('electricity_consumption_band_rules', 'В PDF нет значений социальной нормы для создания правил диапазонов.');

            return;
        }

        foreach ($segments as $segment) {
            $validFrom = $this->dateOrNull($segment['valid_from']);
            $validTo = $this->dateOrNull($segment['valid_to']);
            $month = is_numeric($segment['month'] ?? null) ? (int) $segment['month'] : 0;
            $socialNormKwh = $this->optionalDecimal($segment['social_norm_kwh'] ?? null);

            if (!$validFrom instanceof DateTimeImmutable || $month < 1 || $month > 12 || $socialNormKwh === null || bccomp($socialNormKwh, '0', 3) <= 0) {
                $result->skipped('electricity_consumption_band_rules', 'Правило социальной нормы из PDF без месяца, даты или положительной нормы пропущено.');
                continue;
            }

            $rule = $this->findExactConsumptionBandRule($workspace, $tariffProfile, $month, $validFrom);

            if ($rule instanceof ElectricityConsumptionBandRule) {
                if (!$this->sameNullableDate($rule->getValidTo(), $validTo)) {
                    $result->skipped(
                        'electricity_consumption_band_rules',
                        sprintf('Правило соцнормы для месяца %02d с %s уже есть, но дата окончания отличается.', $month, $validFrom->format('Y-m-d')),
                    );
                    continue;
                }

                if (!$this->ruleRangesMatch($workspace, $rule, $socialBand, $aboveBand, $socialNormKwh)) {
                    $result->skipped(
                        'electricity_consumption_band_rules',
                        sprintf('Правило соцнормы для месяца %02d с %s уже есть, но диапазоны отличаются.', $month, $validFrom->format('Y-m-d')),
                    );
                    continue;
                }

                $result->reused('electricity_consumption_band_rules');
                $this->findOrCreateAllScope($workspace, $rule, $result);
                continue;
            }

            $overlappingRule = $this->electricityConsumptionBandRuleRepository->findOverlappingActiveRuleWithSamePriority(
                $workspace,
                $tariffProfile,
                $month,
                100,
                $validFrom,
                $validTo,
            );

            if ($overlappingRule instanceof ElectricityConsumptionBandRule) {
                $result->skipped(
                    'electricity_consumption_band_rules',
                    sprintf('Правило соцнормы для месяца %02d с %s пересекается с существующим правилом с %s.', $month, $validFrom->format('Y-m-d'), $overlappingRule->getValidFrom()->format('Y-m-d')),
                );
                continue;
            }

            $rule = (new ElectricityConsumptionBandRule($workspace, $tariffProfile, $validFrom, $month))
                ->setValidTo($validTo)
                ->setAllocationMethod(ElectricityConsumptionBandAllocationMethod::TotalProportional)
                ->setPriority(100)
                ->setSourceDocument($this->sourceDocument($file))
                ->setNotes('Создано импортом PDF-квитанции ЗМ.');
            $rule->setCreatedBy($actor);
            $this->entityManager->persist($rule);
            $result->created('electricity_consumption_band_rules');

            $this->auditLogger->record(
                action: 'electricity_consumption_band_rule.created',
                workspace: $workspace,
                entityTable: 'electricity_consumption_band_rules',
                entityUuid: $rule->getUuid(),
                newValues: [
                    'tariff_profile_uuid' => $tariffProfile->getUuid()->toRfc4122(),
                    'tariff_profile_code' => $tariffProfile->getCode(),
                    'valid_from' => $rule->getValidFrom()->format('Y-m-d'),
                    'valid_to' => $rule->getValidTo()?->format('Y-m-d'),
                    'month' => $rule->getMonth(),
                    'allocation_method' => $rule->getAllocationMethod()->value,
                    'priority' => $rule->getPriority(),
                    'source_document' => $rule->getSourceDocument(),
                    'notes' => $rule->getNotes(),
                ],
                changedFields: ['tariff_profile_uuid', 'valid_from', 'valid_to', 'month', 'allocation_method', 'priority', 'source_document', 'notes'],
                source: AuditLogSource::Import,
            );

            $this->findOrCreateAllScope($workspace, $rule, $result);
            $this->findOrCreateConsumptionBandRuleRange($workspace, $rule, $socialBand, '0', $socialNormKwh, $result);
            $this->findOrCreateConsumptionBandRuleRange($workspace, $rule, $aboveBand, $socialNormKwh, null, $result);
        }
    }

    private function findOrCreateAllScope(
        Workspace $workspace,
        ElectricityConsumptionBandRule $rule,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $scope = $this->electricityConsumptionBandRuleAllScopeRepository->findOneByRule($workspace, $rule);

        if ($scope instanceof ElectricityConsumptionBandRuleAllScope) {
            if ($scope->getMode() === ElectricityConsumptionBandRuleScopeMode::Include) {
                $result->reused('electricity_consumption_band_rule_all_scopes');
            } else {
                $result->skipped(
                    'electricity_consumption_band_rule_all_scopes',
                    sprintf('Правило соцнормы %s уже имеет область применения не для всех участков.', $rule->getUuid()->toRfc4122()),
                );
            }

            return;
        }

        $scope = new ElectricityConsumptionBandRuleAllScope($workspace, $rule, ElectricityConsumptionBandRuleScopeMode::Include);
        $this->entityManager->persist($scope);
        $result->created('electricity_consumption_band_rule_all_scopes');

        $this->auditLogger->record(
            action: 'electricity_consumption_band_rule_all_scope.created',
            workspace: $workspace,
            entityTable: 'electricity_consumption_band_rule_all_scopes',
            entityPk: [
                'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
                'rule_uuid' => $rule->getUuid()->toRfc4122(),
            ],
            newValues: [
                'rule_uuid' => $rule->getUuid()->toRfc4122(),
                'mode' => $scope->getMode()->value,
            ],
            changedFields: ['rule_uuid', 'mode'],
            source: AuditLogSource::Import,
        );
    }

    private function findOrCreateConsumptionBandRuleRange(
        Workspace $workspace,
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBand $consumptionBand,
        string $lowerBoundKwh,
        ?string $upperBoundKwh,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $range = $this->electricityConsumptionBandRuleRangeRepository->findOneByRuleAndBand($workspace, $rule, $consumptionBand);

        if ($range instanceof ElectricityConsumptionBandRuleRange) {
            if (bccomp($range->getLowerBoundKwh(), $lowerBoundKwh, 3) === 0
                && $this->sameNullableDecimal($range->getUpperBoundKwh(), $upperBoundKwh, 3)
            ) {
                $result->reused('electricity_consumption_band_rule_ranges');
            } else {
                $result->skipped(
                    'electricity_consumption_band_rule_ranges',
                    sprintf('Диапазон %s для правила %s уже есть, но границы отличаются.', $consumptionBand->getCode(), $rule->getUuid()->toRfc4122()),
                );
            }

            return;
        }

        $range = new ElectricityConsumptionBandRuleRange($workspace, $rule, $consumptionBand, $lowerBoundKwh, $upperBoundKwh);
        $this->entityManager->persist($range);
        $result->created('electricity_consumption_band_rule_ranges');

        $this->auditLogger->record(
            action: 'electricity_consumption_band_rule_range.created',
            workspace: $workspace,
            entityTable: 'electricity_consumption_band_rule_ranges',
            entityPk: [
                'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
                'rule_uuid' => $rule->getUuid()->toRfc4122(),
                'consumption_band_uuid' => $consumptionBand->getUuid()->toRfc4122(),
            ],
            newValues: [
                'rule_uuid' => $rule->getUuid()->toRfc4122(),
                'consumption_band_uuid' => $consumptionBand->getUuid()->toRfc4122(),
                'consumption_band_code' => $consumptionBand->getCode(),
                'lower_bound_kwh' => $range->getLowerBoundKwh(),
                'upper_bound_kwh' => $range->getUpperBoundKwh(),
            ],
            changedFields: ['rule_uuid', 'consumption_band_uuid', 'lower_bound_kwh', 'upper_bound_kwh'],
            source: AuditLogSource::Import,
        );
    }

    private function applyMetersAndReadings(
        Workspace $workspace,
        Account $account,
        Subscriber $subscriber,
        ElectricityTariffZone $tariffZone,
        array $parsedResult,
        array $rows,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $segments = $this->buildMeterSegmentsWithRows($rows, is_array($parsedResult['electricity_meter'] ?? null) ? $parsedResult['electricity_meter'] : []);

        if ($segments === []) {
            $result->skipped('electricity_meter_readings', 'В PDF нет строк показаний.');

            return;
        }

        $activeMeter = $this->electricityMeterRepository->findOneActiveByWorkspaceAndAccount($workspace, $account);
        $lastSegment = $segments[array_key_last($segments)];
        $lastSerial = $this->optionalString($lastSegment['serial_number'] ?? null);

        if ($activeMeter instanceof ElectricityMeter && $lastSerial !== null && $activeMeter->getSerialNumber() !== null && $activeMeter->getSerialNumber() !== $lastSerial) {
            $result->skipped(
                'electricity_meter_readings',
                sprintf('В системе активен счетчик № %s, в PDF счетчик № %s. Показания не импортированы.', $activeMeter->getSerialNumber(), $lastSerial),
            );

            return;
        }

        $metersBySegment = [];

        foreach ($segments as $index => $segment) {
            $isLast = $index === array_key_last($segments);
            $meter = null;

            if ($isLast && $activeMeter instanceof ElectricityMeter) {
                $meter = $activeMeter;
                $result->reused('electricity_meters');
                $this->enrichActiveMeterFromImportedSegment($workspace, $meter, $segment, $actor);
            } else {
                $meter = new ElectricityMeter(
                    workspace: $workspace,
                    account: $account,
                    installedOn: $this->dateOrNull($segment['installed_on'] ?? null) ?? $this->dateOrNull($segment['from'] ?? null) ?? new DateTimeImmutable('today'),
                );
                $meter
                    ->setSerialNumber($this->optionalString($segment['serial_number'] ?? null))
                    ->setNotes('Создано импортом PDF-квитанции ЗМ.');

                if (!$isLast) {
                    $meter->setRemovedOn(
                        $this->dateOrNull($segments[$index + 1]['installed_on'] ?? null)
                        ?? $this->dateOrNull($segments[$index + 1]['from'] ?? null)
                    );
                }

                $meter->setCreatedBy($actor);
                $this->entityManager->persist($meter);
                $result->created('electricity_meters');
                $this->auditMeterCreated($workspace, $meter);
            }

            $this->findOrCreateMeterRegister($workspace, $meter, $tariffZone, $result);
            $metersBySegment[$index] = $meter;
        }

        $localReadingKeys = [];

        foreach ($segments as $index => $segment) {
            $meter = $metersBySegment[$index] ?? null;

            if (!$meter instanceof ElectricityMeter) {
                continue;
            }

            foreach ($segment['rows'] as $row) {
                $takenOn = $this->readingTakenOnOrNull($row);
                $readingValue = $this->optionalString($row['reading_value_kwh'] ?? null);

                if (!$takenOn instanceof DateTimeImmutable || $readingValue === null) {
                    $result->skipped('electricity_meter_readings', 'Строка показания без даты или значения пропущена.');
                    continue;
                }

                $localKey = $meter->getUuid()->toRfc4122().'|'.$tariffZone->getUuid()->toRfc4122().'|'.$takenOn->format('Y-m-d');

                if (isset($localReadingKeys[$localKey])) {
                    $result->skipped('electricity_meter_readings', sprintf('Дубль показания за %s внутри PDF пропущен.', $takenOn->format('Y-m-d')));
                    continue;
                }

                $localReadingKeys[$localKey] = true;
                $existingReading = $this->electricityMeterReadingRepository->findOneActiveByMeterZoneAndTakenOn($workspace, $meter, $tariffZone, $takenOn);

                if ($existingReading instanceof ElectricityMeterReading) {
                    if (bccomp($existingReading->getReadingValue(), $readingValue, 3) === 0) {
                        $result->reused('electricity_meter_readings');
                    } else {
                        $result->skipped(
                            'electricity_meter_readings',
                            sprintf('Конфликт показания за %s: в системе %s, в PDF %s.', $takenOn->format('Y-m-d'), $existingReading->getReadingValue(), $readingValue),
                        );
                    }

                    continue;
                }

                $reading = new ElectricityMeterReading(
                    workspace: $workspace,
                    electricityMeter: $meter,
                    tariffZone: $tariffZone,
                    readingValue: $readingValue,
                    takenOn: $takenOn,
                    source: ElectricityMeterReadingSource::Import,
                    submittedBy: $actor,
                );
                $reading
                    ->setProvidedBySubscriber($subscriber)
                    ->setNotes('Импорт из PDF-квитанции ЗМ.');
                $reading->setCreatedBy($actor);
                $this->entityManager->persist($reading);
                $result->created('electricity_meter_readings');

                $this->auditLogger->record(
                    action: 'electricity_meter_reading.created',
                    workspace: $workspace,
                    entityTable: 'electricity_meter_readings',
                    entityUuid: $reading->getUuid(),
                    newValues: [
                        'account_uuid' => $account->getUuid()->toRfc4122(),
                        'account_number' => $account->getNumber(),
                        'electricity_meter_uuid' => $meter->getUuid()->toRfc4122(),
                        'tariff_zone_uuid' => $tariffZone->getUuid()->toRfc4122(),
                        'tariff_zone_code' => $tariffZone->getCode(),
                        'reading_value' => $reading->getReadingValue(),
                        'taken_on' => $reading->getTakenOn()->format('Y-m-d'),
                        'source' => $reading->getSource()->value,
                        'provided_by_subscriber_uuid' => $subscriber->getUuid()->toRfc4122(),
                        'notes' => $reading->getNotes(),
                    ],
                    changedFields: ['electricity_meter_uuid', 'tariff_zone_uuid', 'reading_value', 'taken_on', 'source', 'provided_by_subscriber_uuid', 'notes'],
                    source: AuditLogSource::Import,
                );
            }
        }
    }

    /**
     * @param array{serial_number?: string|null} $segment
     */
    private function enrichActiveMeterFromImportedSegment(Workspace $workspace, ElectricityMeter $meter, array $segment, ?User $actor): void
    {
        $importedSerialNumber = $this->optionalString($segment['serial_number'] ?? null);

        if ($importedSerialNumber === null || $this->optionalString($meter->getSerialNumber()) !== null) {
            return;
        }

        $oldValues = $this->meterAuditValues($meter);
        $meter
            ->setSerialNumber($importedSerialNumber)
            ->touch($actor);

        $this->auditLogger->record(
            action: 'electricity_meter.updated',
            workspace: $workspace,
            entityTable: 'electricity_meters',
            entityUuid: $meter->getUuid(),
            oldValues: $oldValues,
            newValues: $this->meterAuditValues($meter),
            changedFields: ['serial_number'],
            source: AuditLogSource::Import,
        );
    }

    private function findOrCreateMeterRegister(
        Workspace $workspace,
        ElectricityMeter $meter,
        ElectricityTariffZone $tariffZone,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $register = $this->electricityMeterRegisterRepository->findOneByMeterAndTariffZone($workspace, $meter, $tariffZone);

        if ($register instanceof ElectricityMeterRegister) {
            $result->reused('electricity_meter_registers');

            return;
        }

        $register = new ElectricityMeterRegister($workspace, $meter, $tariffZone);
        $this->entityManager->persist($register);
        $result->created('electricity_meter_registers');

        $this->auditLogger->record(
            action: 'electricity_meter_register.created',
            workspace: $workspace,
            entityTable: 'electricity_meter_registers',
            entityPk: [
                'workspace_uuid' => $workspace->getUuid()->toRfc4122(),
                'electricity_meter_uuid' => $meter->getUuid()->toRfc4122(),
                'tariff_zone_uuid' => $tariffZone->getUuid()->toRfc4122(),
            ],
            newValues: [
                'electricity_meter_uuid' => $meter->getUuid()->toRfc4122(),
                'tariff_zone_uuid' => $tariffZone->getUuid()->toRfc4122(),
                'tariff_zone_code' => $tariffZone->getCode(),
            ],
            changedFields: ['electricity_meter_uuid', 'tariff_zone_uuid'],
            source: AuditLogSource::Import,
        );
    }

    private function applyPayments(
        Workspace $workspace,
        Account $account,
        Subscriber $subscriber,
        array $parsedResult,
        array $rows,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $paymentRequisites = is_array($parsedResult['payment_requisites'] ?? null) ? $parsedResult['payment_requisites'] : [];
        $payerName = ZavetyMichurinaPersonNameNormalizer::normalizeFullName($this->optionalString($paymentRequisites['payer_name'] ?? null))
            ?? $subscriber->getDisplayName();
        $purpose = $this->optionalString($paymentRequisites['payment_purpose'] ?? null);
        $localPaymentKeys = [];

        foreach ($rows as $row) {
            $paidOn = $this->dateOrNull($row['paid_on'] ?? null);
            $amount = $this->optionalMoney($row['paid_amount'] ?? null);

            if (!$paidOn instanceof DateTimeImmutable || $amount === null) {
                continue;
            }

            $key = $paidOn->format('Y-m-d').'|'.$amount;

            if (isset($localPaymentKeys[$key])) {
                $result->skipped('payments', sprintf('Дубль оплаты %s на %s руб. внутри PDF пропущен.', $paidOn->format('Y-m-d'), $amount));
                continue;
            }

            $localPaymentKeys[$key] = true;

            if ($this->findMatchingActivePayment($workspace, $account, $paidOn, $amount) instanceof Payment) {
                $result->reused('payments');
                continue;
            }

            $payment = new Payment($workspace, $account, $amount, $paidOn, PaymentSource::Import, $actor);
            $payment
                ->setPayerName($payerName)
                ->setPurpose($purpose)
                ->setExternalReference($this->paymentExternalReference($account, $paidOn, $amount));
            $this->entityManager->persist($payment);
            $result->created('payments');

            $this->auditLogger->record(
                action: 'payment.created',
                workspace: $workspace,
                entityTable: 'payments',
                entityUuid: $payment->getUuid(),
                newValues: [
                    'account_uuid' => $account->getUuid()->toRfc4122(),
                    'account_number' => $account->getNumber(),
                    'amount' => $payment->getAmount(),
                    'paid_on' => $payment->getPaidOn()->format('Y-m-d'),
                    'source' => $payment->getSource()->value,
                    'payer_name' => $payment->getPayerName(),
                    'purpose' => $payment->getPurpose(),
                    'external_reference' => $payment->getExternalReference(),
                ],
                changedFields: ['account_uuid', 'amount', 'paid_on', 'source', 'payer_name', 'purpose', 'external_reference'],
                source: AuditLogSource::Import,
            );
        }
    }

    private function applyAccruals(
        Workspace $workspace,
        Account $account,
        array $rows,
        ?User $actor,
        ZavetyMichurinaStatementImportApplyResult $result,
    ): void {
        $localAccrualKeys = [];

        foreach ($rows as $row) {
            $periodStart = $this->dateOrNull($row['period_start'] ?? null);
            $amount = $this->optionalMoney($row['accrued_amount'] ?? null);

            if (!$periodStart instanceof DateTimeImmutable || $amount === null) {
                $result->skipped('accruals', 'Строка начисления без периода или суммы пропущена.');
                continue;
            }

            $periodEnd = $periodStart->modify('+1 month');
            $key = $periodStart->format('Y-m-d').'|'.$periodEnd->format('Y-m-d');

            if (isset($localAccrualKeys[$key])) {
                $result->skipped('accruals', sprintf('Дубль начисления за %s внутри PDF пропущен.', $periodStart->format('Y-m-d')));
                continue;
            }

            $localAccrualKeys[$key] = true;
            $existingAccrual = $this->accrualRepository->findOneActivePostedByAccountTypeAndPeriod(
                $workspace,
                $account,
                AccrualType::Electricity,
                $periodStart,
                $periodEnd,
            );

            if ($existingAccrual instanceof Accrual) {
                if (bccomp($existingAccrual->getAmount(), $amount, 2) === 0) {
                    $result->reused('accruals');
                } else {
                    $result->skipped(
                        'accruals',
                        sprintf('Конфликт начисления за %s: в системе %s, в PDF %s.', $periodStart->format('Y-m-d'), $existingAccrual->getAmount(), $amount),
                    );
                }

                continue;
            }

            $accrual = new Accrual(
                workspace: $workspace,
                account: $account,
                type: AccrualType::Electricity,
                periodStart: $periodStart,
                periodEnd: $periodEnd,
                amount: $amount,
                createdBy: $actor,
            );
            $accrual
                ->setCalculationVersion(ZavetyMichurinaStatementImportFile::PARSER_VERSION)
                ->setNotes('Историческое начисление из PDF-квитанции ЗМ.')
                ->post($actor);
            $this->entityManager->persist($accrual);
            $result->created('accruals');

            $this->auditLogger->record(
                action: 'accrual.created',
                workspace: $workspace,
                entityTable: 'accruals',
                entityUuid: $accrual->getUuid(),
                newValues: [
                    'account_uuid' => $account->getUuid()->toRfc4122(),
                    'account_number' => $account->getNumber(),
                    'type' => $accrual->getType()->value,
                    'period_start' => $accrual->getPeriodStart()->format('Y-m-d'),
                    'period_end' => $accrual->getPeriodEnd()->format('Y-m-d'),
                    'amount' => $accrual->getAmount(),
                    'posted_at' => $accrual->getPostedAt()?->format(DATE_ATOM),
                    'calculation_version' => $accrual->getCalculationVersion(),
                    'notes' => $accrual->getNotes(),
                ],
                changedFields: ['account_uuid', 'type', 'period_start', 'period_end', 'amount', 'posted_at', 'calculation_version', 'notes'],
                source: AuditLogSource::Import,
            );
        }
    }

    private function auditMeterCreated(Workspace $workspace, ElectricityMeter $meter): void
    {
        $account = $meter->getAccount();

        $this->auditLogger->record(
            action: 'electricity_meter.created',
            workspace: $workspace,
            entityTable: 'electricity_meters',
            entityUuid: $meter->getUuid(),
            newValues: [
                'account_uuid' => $account?->getUuid()->toRfc4122(),
                'account_number' => $account?->getNumber(),
                'serial_number' => $meter->getSerialNumber(),
                'model' => $meter->getModel(),
                'installed_on' => $meter->getInstalledOn()->format('Y-m-d'),
                'removed_on' => $meter->getRemovedOn()?->format('Y-m-d'),
                'notes' => $meter->getNotes(),
            ],
            changedFields: ['account_uuid', 'serial_number', 'model', 'installed_on', 'removed_on', 'notes'],
            source: AuditLogSource::Import,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function meterAuditValues(ElectricityMeter $meter): array
    {
        $account = $meter->getAccount();

        return [
            'account_uuid' => $account?->getUuid()->toRfc4122(),
            'account_number' => $account?->getNumber(),
            'serial_number' => $meter->getSerialNumber(),
            'model' => $meter->getModel(),
            'installed_on' => $meter->getInstalledOn()->format('Y-m-d'),
            'removed_on' => $meter->getRemovedOn()?->format('Y-m-d'),
            'notes' => $meter->getNotes(),
        ];
    }

    private function findMatchingActivePayment(Workspace $workspace, Account $account, DateTimeImmutable $paidOn, string $amount): ?Payment
    {
        return $this->paymentRepository->createQueryBuilder('payment')
            ->andWhere('payment.workspace = :workspace')
            ->andWhere('payment.account = :account')
            ->andWhere('payment.paidOn = :paidOn')
            ->andWhere('payment.amount = :amount')
            ->andWhere('payment.cancelledAt IS NULL')
            ->andWhere('payment.replacingPayment IS NULL')
            ->setParameter('workspace', $workspace)
            ->setParameter('account', $account)
            ->setParameter('paidOn', $paidOn)
            ->setParameter('amount', $amount)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizedRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row) && isset($row['period_start'])) {
                $rows[] = $row;
            }
        }

        usort(
            $rows,
            static fn (array $left, array $right): int => strcmp((string) $left['period_start'], (string) $right['period_start']),
        );

        return $rows;
    }

    /**
     * @return list<array{from: string|null, installed_on: string|null, serial_number: string|null, rows: list<array<string, mixed>>}>
     */
    private function buildMeterSegmentsWithRows(array $rows, array $meterData): array
    {
        if ($rows === []) {
            return [];
        }

        $segments = [];
        $currentRows = [$rows[0]];
        $previous = $rows[0];

        foreach (array_slice($rows, 1) as $row) {
            $previousReading = $this->optionalString($previous['reading_value_kwh'] ?? null);
            $currentReading = $this->optionalString($row['reading_value_kwh'] ?? null);

            if ($previousReading !== null && $currentReading !== null && bccomp($currentReading, $previousReading, 3) < 0) {
                $segments[] = [
                    'from' => $this->optionalString($currentRows[0]['period_start'] ?? null),
                    'installed_on' => $this->optionalString($currentRows[0]['period_start'] ?? null),
                    'serial_number' => null,
                    'rows' => $currentRows,
                ];
                $currentRows = [];
            }

            $currentRows[] = $row;
            $previous = $row;
        }

        $segments[] = [
            'from' => $this->optionalString($currentRows[0]['period_start'] ?? null),
            'installed_on' => $this->optionalString($meterData['installed_on'] ?? null) ?? $this->optionalString($currentRows[0]['period_start'] ?? null),
            'serial_number' => $this->optionalString($meterData['serial_number'] ?? null),
            'rows' => $currentRows,
        ];

        return $segments;
    }

    /**
     * @return list<array{valid_from: string, valid_to: string|null, social_norm_rate: string, above_norm_rate: string}>
     */
    private function buildTariffRateSegments(array $rows): array
    {
        $segments = [];
        $current = null;

        foreach ($rows as $row) {
            $periodStart = $this->optionalString($row['period_start'] ?? null);
            $socialNormRate = $this->optionalDecimal($row['social_norm_rate'] ?? null);
            $aboveNormRate = $this->optionalDecimal($row['above_norm_rate'] ?? null);

            if ($periodStart === null || $socialNormRate === null || $aboveNormRate === null) {
                continue;
            }

            $key = $socialNormRate.'|'.$aboveNormRate;

            if ($current === null) {
                $current = [
                    'key' => $key,
                    'valid_from' => $periodStart,
                    'valid_to' => null,
                    'social_norm_rate' => $socialNormRate,
                    'above_norm_rate' => $aboveNormRate,
                ];
                continue;
            }

            if ($current['key'] === $key) {
                continue;
            }

            $current['valid_to'] = $periodStart;
            unset($current['key']);
            $segments[] = $current;
            $current = [
                'key' => $key,
                'valid_from' => $periodStart,
                'valid_to' => null,
                'social_norm_rate' => $socialNormRate,
                'above_norm_rate' => $aboveNormRate,
            ];
        }

        if ($current !== null) {
            unset($current['key']);
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * @return list<array{month: int, valid_from: string, valid_to: string|null, social_norm_kwh: string}>
     */
    private function buildSocialNormRuleSegments(array $rows): array
    {
        $byMonth = [];

        foreach ($rows as $row) {
            $month = is_numeric($row['month'] ?? null) ? (int) $row['month'] : null;
            $periodStart = $this->optionalString($row['period_start'] ?? null);
            $socialNormKwh = $this->optionalDecimal($row['social_norm_kwh'] ?? null);

            if ($month === null || $month < 1 || $month > 12 || $periodStart === null || $socialNormKwh === null) {
                continue;
            }

            $byMonth[$month][] = [
                'period_start' => $periodStart,
                'social_norm_kwh' => $socialNormKwh,
            ];
        }

        ksort($byMonth);
        $segments = [];

        foreach ($byMonth as $month => $monthRows) {
            usort(
                $monthRows,
                static fn (array $left, array $right): int => strcmp((string) $left['period_start'], (string) $right['period_start']),
            );

            $current = null;

            foreach ($monthRows as $monthRow) {
                if ($current === null) {
                    $current = [
                        'month' => (int) $month,
                        'valid_from' => $monthRow['period_start'],
                        'valid_to' => null,
                        'social_norm_kwh' => $monthRow['social_norm_kwh'],
                    ];
                    continue;
                }

                if (bccomp((string) $current['social_norm_kwh'], (string) $monthRow['social_norm_kwh'], 3) === 0) {
                    continue;
                }

                $current['valid_to'] = $monthRow['period_start'];
                $segments[] = $current;
                $current = [
                    'month' => (int) $month,
                    'valid_from' => $monthRow['period_start'],
                    'valid_to' => null,
                    'social_norm_kwh' => $monthRow['social_norm_kwh'],
                ];
            }

            if ($current !== null) {
                $segments[] = $current;
            }
        }

        return $segments;
    }

    private function findExactTariffPeriod(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        DateTimeImmutable $validFrom,
    ): ?ElectricityTariffPeriod {
        foreach ($this->electricityTariffPeriodRepository->findActiveByProfile($workspace, $tariffProfile) as $period) {
            if ($period->getValidFrom()->format('Y-m-d') === $validFrom->format('Y-m-d')) {
                return $period;
            }
        }

        return null;
    }

    private function findExactConsumptionBandRule(
        Workspace $workspace,
        ElectricityTariffProfile $tariffProfile,
        int $month,
        DateTimeImmutable $validFrom,
    ): ?ElectricityConsumptionBandRule {
        foreach ($this->electricityConsumptionBandRuleRepository->findActiveByProfile($workspace, $tariffProfile) as $rule) {
            if ($rule->getMonth() === $month
                && $rule->getPriority() === 100
                && $rule->getValidFrom()->format('Y-m-d') === $validFrom->format('Y-m-d')
            ) {
                return $rule;
            }
        }

        return null;
    }

    private function ruleRangesMatch(
        Workspace $workspace,
        ElectricityConsumptionBandRule $rule,
        ElectricityConsumptionBand $socialBand,
        ElectricityConsumptionBand $aboveBand,
        string $socialNormKwh,
    ): bool {
        $socialRange = $this->electricityConsumptionBandRuleRangeRepository->findOneByRuleAndBand($workspace, $rule, $socialBand);
        $aboveRange = $this->electricityConsumptionBandRuleRangeRepository->findOneByRuleAndBand($workspace, $rule, $aboveBand);

        if (!$socialRange instanceof ElectricityConsumptionBandRuleRange || !$aboveRange instanceof ElectricityConsumptionBandRuleRange) {
            return false;
        }

        return bccomp($socialRange->getLowerBoundKwh(), '0', 3) === 0
            && $this->sameNullableDecimal($socialRange->getUpperBoundKwh(), $socialNormKwh, 3)
            && bccomp($aboveRange->getLowerBoundKwh(), $socialNormKwh, 3) === 0
            && $aboveRange->getUpperBoundKwh() === null;
    }

    private function uniquePaymentRequisiteCode(Workspace $workspace): string
    {
        $base = 'zm_import_electricity';
        $code = $base;
        $suffix = 2;

        while ($this->paymentRequisiteProfileRepository->findOneActiveByWorkspaceAndCode($workspace, $code) instanceof PaymentRequisiteProfile) {
            $code = sprintf('%s_%d', $base, $suffix);
            ++$suffix;
        }

        return $code;
    }

    private function paymentExternalReference(Account $account, DateTimeImmutable $paidOn, string $amount): string
    {
        return sprintf('zm-pdf:%s:%s:%s', $account->getNumber(), $paidOn->format('Y-m-d'), $amount);
    }

    private function firstPeriodStart(array $rows): ?DateTimeImmutable
    {
        foreach ($rows as $row) {
            $date = $this->dateOrNull($row['period_start'] ?? null);

            if ($date instanceof DateTimeImmutable) {
                return $date;
            }
        }

        return null;
    }

    /**
     * @return array{last_name: string, first_name: string, second_name: string|null}|null
     */
    private function parseSubscriberName(string $fullName): ?array
    {
        $fullName = ZavetyMichurinaPersonNameNormalizer::normalizeFullName($fullName) ?? '';
        $parts = preg_split('/\s+/u', trim($fullName));

        if (!is_array($parts) || count($parts) < 2) {
            return null;
        }

        return [
            'last_name' => $parts[0],
            'first_name' => $parts[1],
            'second_name' => array_slice($parts, 2) === [] ? null : implode(' ', array_slice($parts, 2)),
        ];
    }

    private function requiredString(mixed $value, string $message): string
    {
        $value = $this->optionalString($value);

        if ($value === null) {
            throw new RuntimeException($message);
        }

        return $value;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function optionalMoney(mixed $value): ?string
    {
        $value = $this->optionalString($value);

        if ($value === null) {
            return null;
        }

        $value = str_replace([' ', ','], ['', '.'], $value);

        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        [$rubles, $kopecks] = array_pad(explode('.', $value, 2), 2, '0');

        return $rubles.'.'.str_pad(substr($kopecks, 0, 2), 2, '0');
    }

    private function optionalDecimal(mixed $value): ?string
    {
        $value = $this->optionalString($value);

        if ($value === null) {
            return null;
        }

        $value = str_replace([' ', ','], ['', '.'], $value);

        if (!preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        return $value;
    }

    private function dateOrNull(mixed $value): ?DateTimeImmutable
    {
        $value = $this->optionalString($value);

        if ($value === null) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date === false ? null : $date;
    }

    private function readingTakenOnOrNull(array $row): ?DateTimeImmutable
    {
        $periodStart = $this->dateOrNull($row['period_start'] ?? null);

        return $periodStart?->modify('+1 month');
    }

    private function sameNullableDate(?DateTimeImmutable $left, ?DateTimeImmutable $right): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return $left->format('Y-m-d') === $right->format('Y-m-d');
    }

    private function sameNullableDecimal(?string $left, ?string $right, int $scale): bool
    {
        if ($left === null || $right === null) {
            return $left === null && $right === null;
        }

        return bccomp($left, $right, $scale) === 0;
    }

    private function sourceDocument(ZavetyMichurinaStatementImportFile $file): string
    {
        return sprintf('Импорт PDF ЗМ: %s', $file->getOriginalFilename());
    }
}
