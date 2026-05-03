<?php

namespace App\Custom\ZavetyMichurina\ElectricityStatementImport;

use App\Entity\Account;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityTariffZone;
use App\Entity\Payment;
use App\Entity\PaymentRequisiteProfile;
use App\Entity\Subscriber;
use App\Entity\Workspace;
use App\Entity\ZavetyMichurinaStatementImportFile;
use App\Enum\ZavetyMichurinaStatementImportFileStatus;
use App\Repository\AccountRepository;
use App\Repository\ElectricityConsumptionBandRepository;
use App\Repository\ElectricityMeterReadingRepository;
use App\Repository\ElectricityMeterRepository;
use App\Repository\ElectricityTariffProfileRepository;
use App\Repository\ElectricityTariffZoneRepository;
use App\Repository\PaymentRepository;
use App\Repository\PaymentRequisiteProfileRepository;
use App\Repository\SubscriberAccountAccessRepository;
use App\Repository\SubscriberRepository;
use DateTimeImmutable;

final class ZavetyMichurinaStatementImportPreviewBuilder
{
    private const STATE_REUSE = 'reuse';
    private const STATE_CREATE = 'create';
    private const STATE_ATTENTION = 'attention';
    private const STATE_BLOCKED = 'blocked';
    private const STATE_SKIP = 'skip';

    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly SubscriberRepository $subscriberRepository,
        private readonly SubscriberAccountAccessRepository $subscriberAccountAccessRepository,
        private readonly ElectricityMeterRepository $electricityMeterRepository,
        private readonly ElectricityMeterReadingRepository $electricityMeterReadingRepository,
        private readonly ElectricityTariffZoneRepository $electricityTariffZoneRepository,
        private readonly ElectricityTariffProfileRepository $electricityTariffProfileRepository,
        private readonly ElectricityConsumptionBandRepository $electricityConsumptionBandRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentRequisiteProfileRepository $paymentRequisiteProfileRepository,
    ) {
    }

    /**
     * @return array{
     *     available: bool,
     *     can_apply: bool,
     *     summary: array{reuse: int, create: int, attention: int, blocked: int, skip: int},
     *     items: list<array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}>,
     *     meter_segments: list<array<string, string|null>>,
     *     tariff_periods: list<array{from: string, to: string|null, social_norm_rate: string, above_norm_rate: string, rows: int}>,
     *     social_norms: list<array{period_start: string, month: int, value: string}>,
     *     warnings: list<string>
     * }
     */
    public function build(ZavetyMichurinaStatementImportFile $file): array
    {
        $workspace = $file->getWorkspace();
        $parsedResult = $file->getParsedResult();

        if (!$workspace instanceof Workspace || $file->getStatus() !== ZavetyMichurinaStatementImportFileStatus::Parsed || !is_array($parsedResult)) {
            return $this->emptyPreview('Предпросмотр доступен только для успешно распознанных файлов.');
        }

        $items = [];
        $warnings = $this->stringList($parsedResult['warnings'] ?? []);
        $rows = $this->normalizedRows($parsedResult['rows'] ?? []);
        $accountNumber = $this->optionalString($parsedResult['account']['number'] ?? null);
        $subscriberFullName = $this->optionalString($parsedResult['subscriber']['full_name'] ?? null);
        $meterData = is_array($parsedResult['electricity_meter'] ?? null) ? $parsedResult['electricity_meter'] : [];
        $paymentRequisites = is_array($parsedResult['payment_requisites'] ?? null) ? $parsedResult['payment_requisites'] : [];

        $account = null;
        if ($accountNumber === null) {
            $items[] = $this->item('Участок', self::STATE_BLOCKED, 'В PDF не найден номер участка.', []);
        } else {
            $account = $this->accountRepository->findOneActiveByWorkspaceAndNumber($workspace, $accountNumber);
            $items[] = $account instanceof Account
                ? $this->item('Участок', self::STATE_REUSE, sprintf('Найден существующий участок %s.', $account->getNumber()), [])
                : $this->item('Участок', self::STATE_CREATE, sprintf('Будет создан участок %s.', $accountNumber), []);
        }

        $subscriber = null;
        $subscriberName = $this->parseSubscriberName($subscriberFullName);
        if ($subscriberFullName === null || $subscriberName === null) {
            $items[] = $this->item('Абонент', self::STATE_BLOCKED, 'В PDF не найдено корректное ФИО абонента.', []);
        } else {
            $subscriber = $this->subscriberRepository->findOneActiveByWorkspaceAndName(
                $workspace,
                $subscriberName['last_name'],
                $subscriberName['first_name'],
                $subscriberName['second_name'],
            );
            $items[] = $subscriber instanceof Subscriber
                ? $this->item('Абонент', self::STATE_REUSE, sprintf('Найден существующий абонент %s.', $subscriber->getDisplayName()), [])
                : $this->item('Абонент', self::STATE_CREATE, sprintf('Будет создан абонент %s.', $subscriberFullName), []);
        }

        if ($account instanceof Account && $subscriber instanceof Subscriber) {
            $access = $this->subscriberAccountAccessRepository->findOneActiveBySubscriberAndAccount($workspace, $subscriber, $account);
            $items[] = $access === null
                ? $this->item('Доступ к участку', self::STATE_CREATE, 'Будет создана связь абонента с участком.', [])
                : $this->item('Доступ к участку', self::STATE_REUSE, 'Активная связь абонента с участком уже существует.', []);
        } elseif ($accountNumber !== null && $subscriberFullName !== null) {
            $items[] = $this->item('Доступ к участку', self::STATE_CREATE, 'Связь будет создана после создания недостающих сущностей.', []);
        } else {
            $items[] = $this->item('Доступ к участку', self::STATE_BLOCKED, 'Нельзя подготовить связь без участка и абонента.', []);
        }

        $meterSegments = $this->buildMeterSegments($rows, $meterData);
        $activeMeter = $account instanceof Account ? $this->electricityMeterRepository->findOneActiveByWorkspaceAndAccount($workspace, $account) : null;
        $items[] = $this->buildMeterItem($activeMeter, $meterSegments, $meterData, $accountNumber !== null);

        $tariffZones = $this->electricityTariffZoneRepository->findActiveByWorkspace($workspace);
        $singleTariffZone = count($tariffZones) === 1 ? $tariffZones[0] : null;
        $items[] = $this->buildTariffZoneItem($tariffZones);

        $items[] = $this->buildTariffAndBandItem($workspace, $rows);
        $items[] = $this->buildSocialNormItem($workspace, $rows);
        $items[] = $this->buildReadingsItem($workspace, $rows, $activeMeter, $singleTariffZone, count($meterSegments));
        $items[] = $this->buildPaymentsItem($workspace, $rows, $account);
        $items[] = $this->buildPaymentRequisitesItem($workspace, $paymentRequisites);
        $items[] = $this->buildAccrualsItem($rows, $parsedResult);

        $summary = $this->summarize($items);

        return [
            'available' => true,
            'can_apply' => $summary[self::STATE_BLOCKED] === 0,
            'summary' => $summary,
            'items' => $items,
            'meter_segments' => $meterSegments,
            'tariff_periods' => $this->buildTariffPeriods($rows),
            'social_norms' => $this->buildSocialNorms($rows),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{
     *     available: bool,
     *     can_apply: bool,
     *     summary: array{reuse: int, create: int, attention: int, blocked: int, skip: int},
     *     items: list<array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}>,
     *     meter_segments: list<array<string, string|null>>,
     *     tariff_periods: list<array{from: string, to: string|null, social_norm_rate: string, above_norm_rate: string, rows: int}>,
     *     social_norms: list<array{period_start: string, month: int, value: string}>,
     *     warnings: list<string>
     * }
     */
    private function emptyPreview(string $message): array
    {
        return [
            'available' => false,
            'can_apply' => false,
            'summary' => [
                self::STATE_REUSE => 0,
                self::STATE_CREATE => 0,
                self::STATE_ATTENTION => 0,
                self::STATE_BLOCKED => 1,
                self::STATE_SKIP => 0,
            ],
            'items' => [$this->item('Предпросмотр применения', self::STATE_BLOCKED, $message, [])],
            'meter_segments' => [],
            'tariff_periods' => [],
            'social_norms' => [],
            'warnings' => [],
        ];
    }

    /**
     * @param array<mixed> $value
     *
     * @return list<array<string, mixed>>
     */
    private function normalizedRows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (is_array($row) && isset($row['period_start'], $row['reading_value_kwh'])) {
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
     * @return list<array<string, string|null>>
     */
    private function buildMeterSegments(array $rows, array $meterData): array
    {
        if ($rows === []) {
            return [];
        }

        $segments = [];
        $current = [
            'index' => '1',
            'from' => $this->optionalString($rows[0]['period_start'] ?? null),
            'to' => null,
            'start_reading' => $this->optionalString($rows[0]['reading_value_kwh'] ?? null),
            'end_reading' => null,
            'serial_number' => null,
            'installed_on' => null,
            'replacement_source' => null,
            'reason' => 'Начальный сегмент из PDF',
        ];
        $previous = $rows[0];

        foreach (array_slice($rows, 1) as $row) {
            $previousReading = $this->optionalString($previous['reading_value_kwh'] ?? null);
            $currentReading = $this->optionalString($row['reading_value_kwh'] ?? null);

            if ($previousReading !== null && $currentReading !== null && bccomp($currentReading, $previousReading, 3) < 0) {
                $current['to'] = $this->optionalString($previous['period_start'] ?? null);
                $current['end_reading'] = $previousReading;
                $segments[] = $current;

                $current = [
                    'index' => (string) (count($segments) + 1),
                    'from' => $this->optionalString($row['period_start'] ?? null),
                    'to' => null,
                    'start_reading' => $currentReading,
                    'end_reading' => null,
                    'serial_number' => null,
                    'installed_on' => null,
                    'replacement_source' => 'reading_drop',
                    'reason' => sprintf('Показание снизилось с %s до %s, вероятна замена счетчика.', $previousReading, $currentReading),
                ];
            }

            $previous = $row;
        }

        $current['to'] = $this->optionalString($previous['period_start'] ?? null);
        $current['end_reading'] = $this->optionalString($previous['reading_value_kwh'] ?? null);
        $segments[] = $current;

        $lastIndex = count($segments) - 1;
        $segments[$lastIndex]['serial_number'] = $this->optionalString($meterData['serial_number'] ?? null);
        $segments[$lastIndex]['installed_on'] = $this->optionalString($meterData['installed_on'] ?? null);
        $explicitReplacement = $segments[$lastIndex]['serial_number'] !== null || $segments[$lastIndex]['installed_on'] !== null;

        if (count($segments) === 1) {
            $segments[0]['reason'] = 'Замена счетчика по строкам PDF не обнаружена.';
        } elseif ($explicitReplacement) {
            $segments[$lastIndex]['replacement_source'] = 'pdf';
            $segments[$lastIndex]['reason'] = 'Новый счетчик явно указан в PDF.';
        }

        return $segments;
    }

    /**
     * @param list<array<string, string|null>> $meterSegments
     *
     * @return array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}
     */
    private function buildMeterItem(?ElectricityMeter $activeMeter, array $meterSegments, array $meterData, bool $hasAccountNumber): array
    {
        if (!$hasAccountNumber) {
            return $this->item('Электросчетчик', self::STATE_BLOCKED, 'Нельзя сопоставить счетчик без участка.', []);
        }

        if ($meterSegments === []) {
            return $this->item('Электросчетчик', self::STATE_BLOCKED, 'В PDF нет строк показаний.', []);
        }

        $details = array_map(
            static fn (array $segment): string => sprintf(
                'Сегмент %s: %s - %s, показания %s -> %s%s',
                $segment['index'],
                $segment['from'] ?? '—',
                $segment['to'] ?? '—',
                $segment['start_reading'] ?? '—',
                $segment['end_reading'] ?? '—',
                $segment['serial_number'] !== null ? sprintf(', счетчик № %s', $segment['serial_number']) : '',
            ),
            $meterSegments,
        );

        if (count($meterSegments) > 1) {
            $lastSegment = $meterSegments[array_key_last($meterSegments)];
            $isExplicitReplacement = ($lastSegment['replacement_source'] ?? null) === 'pdf';

            return $this->item(
                'Электросчетчик',
                $isExplicitReplacement ? self::STATE_CREATE : self::STATE_ATTENTION,
                $isExplicitReplacement
                    ? sprintf('В PDF указана замена счетчика: %d сегмента; новый счетчик № %s будет импортирован.', count($meterSegments), $lastSegment['serial_number'] ?? '—')
                    : sprintf('Обнаружена вероятная замена счетчика: %d сегмента.', count($meterSegments)),
                $details,
            );
        }

        $serialNumber = $this->optionalString($meterData['serial_number'] ?? null);

        if (!$activeMeter instanceof ElectricityMeter) {
            return $this->item('Электросчетчик', self::STATE_CREATE, 'Будет создан электросчетчик из PDF.', $details);
        }

        $activeSerial = $this->optionalString($activeMeter->getSerialNumber());

        if ($serialNumber === null || $serialNumber === $activeSerial) {
            return $this->item('Электросчетчик', self::STATE_REUSE, 'Найден активный электросчетчик участка.', $details);
        }

        return $this->item(
            'Электросчетчик',
            self::STATE_ATTENTION,
            sprintf('В системе активен счетчик № %s, в PDF счетчик № %s.', $activeSerial ?? '—', $serialNumber),
            $details,
        );
    }

    /**
     * @param list<ElectricityTariffZone> $tariffZones
     *
     * @return array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}
     */
    private function buildTariffZoneItem(array $tariffZones): array
    {
        if ($tariffZones === []) {
            return $this->item('Тарифная зона', self::STATE_CREATE, 'Будет нужна однотарифная зона для импортируемых показаний.', [
                'PDF содержит готовые суммарные показания без внутрисуточных зон.',
            ]);
        }

        if (count($tariffZones) === 1) {
            $zone = $tariffZones[0];

            return $this->item('Тарифная зона', self::STATE_REUSE, sprintf('Будет использована зона %s - %s.', $zone->getCode(), $zone->getName()), []);
        }

        return $this->item('Тарифная зона', self::STATE_ATTENTION, 'В хозяйстве несколько тарифных зон, для PDF нужно выбрать целевую зону.', [
            sprintf('Активных зон: %d.', count($tariffZones)),
        ]);
    }

    /**
     * @return array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}
     */
    private function buildTariffAndBandItem(Workspace $workspace, array $rows): array
    {
        $profiles = $this->electricityTariffProfileRepository->findActiveByWorkspace($workspace);
        $socialBand = $this->electricityConsumptionBandRepository->findOneActiveByWorkspaceAndCode($workspace, 'social_norm');
        $aboveBand = $this->electricityConsumptionBandRepository->findOneActiveByWorkspaceAndCode($workspace, 'above_social_norm');
        $periods = $this->buildTariffPeriods($rows);
        $details = [
            sprintf('Уникальных непрерывных наборов ставок: %d.', count($periods)),
        ];

        if ($socialBand === null) {
            $details[] = 'Нет активного диапазона social_norm.';
        }

        if ($aboveBand === null) {
            $details[] = 'Нет активного диапазона above_social_norm.';
        }

        if ($profiles === [] || $socialBand === null || $aboveBand === null) {
            return $this->item('Тарифы', self::STATE_CREATE, 'Ставки из PDF будут сопоставляться по уникальным наборам и не должны дублироваться.', $details);
        }

        return $this->item('Тарифы', self::STATE_REUSE, 'Базовые справочники тарифов найдены; ставки будут сопоставляться по периоду и значениям.', $details);
    }

    /**
     * @return array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}
     */
    private function buildSocialNormItem(Workspace $workspace, array $rows): array
    {
        $profiles = $this->electricityTariffProfileRepository->findActiveByWorkspace($workspace);
        $norms = $this->buildSocialNorms($rows);
        $details = [
            sprintf('Расчетных периодов с нормами в PDF: %d.', count($norms)),
            'Нормы сопоставляются по расчетному периоду: год и месяц. Разные значения для одного месяца в разные годы допустимы.',
        ];

        if ($profiles === []) {
            return $this->item('Соцнормы', self::STATE_CREATE, 'Для соцнорм потребуется тарифный профиль; значения будут периодизованы по году и месяцу.', $details);
        }

        return $this->item('Соцнормы', self::STATE_REUSE, 'Нормы готовы к сопоставлению с периодизованными правилами потребления.', $details);
    }

    /**
     * @return array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}
     */
    private function buildReadingsItem(
        Workspace $workspace,
        array $rows,
        ?ElectricityMeter $activeMeter,
        ?ElectricityTariffZone $singleTariffZone,
        int $meterSegmentCount,
    ): array {
        if ($rows === []) {
            return $this->item('Показания', self::STATE_BLOCKED, 'В PDF нет строк показаний.', []);
        }

        if (!$activeMeter instanceof ElectricityMeter || !$singleTariffZone instanceof ElectricityTariffZone || $meterSegmentCount > 1) {
            return $this->item('Показания', self::STATE_ATTENTION, sprintf('К импорту подготовлено строк показаний: %d.', count($rows)), [
                'Точная проверка дублей будет возможна после сопоставления счетчика и тарифной зоны.',
            ]);
        }

        $duplicates = 0;
        $conflicts = 0;

        foreach ($rows as $row) {
            $takenOn = $this->readingTakenOnOrNull($row);
            $readingValue = $this->optionalString($row['reading_value_kwh'] ?? null);

            if (!$takenOn instanceof DateTimeImmutable || $readingValue === null) {
                continue;
            }

            $existingReading = $this->electricityMeterReadingRepository->findOneActiveByMeterZoneAndTakenOn(
                $workspace,
                $activeMeter,
                $singleTariffZone,
                $takenOn,
            );

            if ($existingReading === null) {
                continue;
            }

            if (bccomp($existingReading->getReadingValue(), $readingValue, 3) === 0) {
                ++$duplicates;
            } else {
                ++$conflicts;
            }
        }

        $toCreate = count($rows) - $duplicates - $conflicts;
        $state = $conflicts > 0 ? self::STATE_ATTENTION : ($toCreate > 0 ? self::STATE_CREATE : self::STATE_REUSE);

        return $this->item('Показания', $state, sprintf('Строк: %d, новых: %d, дублей: %d, конфликтов: %d.', count($rows), $toCreate, $duplicates, $conflicts), []);
    }

    /**
     * @return array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}
     */
    private function buildPaymentsItem(Workspace $workspace, array $rows, ?Account $account): array
    {
        $paymentRows = array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->optionalString($row['paid_on'] ?? null) !== null && $this->optionalString($row['paid_amount'] ?? null) !== null,
        ));

        if ($paymentRows === []) {
            return $this->item('Оплаты', self::STATE_SKIP, 'В PDF нет строк оплат.', []);
        }

        if (!$account instanceof Account) {
            return $this->item('Оплаты', self::STATE_CREATE, sprintf('К импорту подготовлено %d оплат; проверка дублей будет после создания участка.', count($paymentRows)), []);
        }

        $duplicates = 0;

        foreach ($paymentRows as $row) {
            $paidOn = $this->dateOrNull($row['paid_on'] ?? null);
            $amount = $this->optionalString($row['paid_amount'] ?? null);

            if (!$paidOn instanceof DateTimeImmutable || $amount === null) {
                continue;
            }

            if ($this->findMatchingActivePayment($workspace, $account, $paidOn, $amount) instanceof Payment) {
                ++$duplicates;
            }
        }

        $toCreate = count($paymentRows) - $duplicates;

        return $this->item(
            'Оплаты',
            $toCreate > 0 ? self::STATE_CREATE : self::STATE_REUSE,
            sprintf('Строк оплат: %d, новых: %d, возможных дублей: %d.', count($paymentRows), $toCreate, $duplicates),
            ['Дедупликация оплат выполняется по участку, дате оплаты и сумме.'],
        );
    }

    /**
     * @return array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}
     */
    private function buildPaymentRequisitesItem(Workspace $workspace, array $paymentRequisites): array
    {
        $bankAccount = $this->optionalString($paymentRequisites['bank_account'] ?? null);
        $bankBik = $this->optionalString($paymentRequisites['bank_bik'] ?? null);

        if ($bankAccount === null || $bankBik === null) {
            return $this->item('Платежные реквизиты', self::STATE_SKIP, 'В PDF нет полного набора реквизитов для сопоставления.', []);
        }

        foreach ($this->paymentRequisiteProfileRepository->findActiveByWorkspace($workspace) as $profile) {
            if (!$profile instanceof PaymentRequisiteProfile) {
                continue;
            }

            if ($profile->getBankAccount() === $bankAccount && $profile->getBankBik() === $bankBik) {
                return $this->item('Платежные реквизиты', self::STATE_REUSE, sprintf('Найден профиль реквизитов %s.', $profile->getName()), []);
            }
        }

        return $this->item('Платежные реквизиты', self::STATE_CREATE, 'Будет создан или сопоставлен профиль банковских реквизитов.', [
            sprintf('Расчетный счет: %s, БИК: %s.', $bankAccount, $bankBik),
        ]);
    }

    /**
     * @return array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}
     */
    private function buildAccrualsItem(array $rows, array $parsedResult): array
    {
        if ($rows === []) {
            return $this->item('Начисления', self::STATE_BLOCKED, 'В PDF нет строк для исторических начислений.', []);
        }

        $totalAccrued = $this->moneyStringOrNull($parsedResult['totals']['total_accrued'] ?? null);
        $totalPaid = $this->moneyStringOrNull($parsedResult['totals']['total_paid'] ?? null);
        $balance = $this->moneyStringOrNull($parsedResult['totals']['balance'] ?? null);
        $calculatedAccrued = $this->sumMoneyFromRows($rows, 'accrued_amount');
        $calculatedPaid = $this->sumMoneyFromRows($rows, 'paid_amount');
        $calculatedAmountDue = bcsub($calculatedAccrued, $calculatedPaid, 2);
        $details = [
            sprintf('Начислено по строкам импорта: %s.', $calculatedAccrued),
            sprintf('Оплачено по строкам импорта: %s.', $calculatedPaid),
            sprintf('Пересчитанный остаток к оплате по строкам импорта: %s.', $calculatedAmountDue),
            sprintf('Итого начислено по PDF: %s.', $totalAccrued ?? '—'),
            sprintf('Итого оплачено по PDF: %s.', $totalPaid ?? '—'),
            sprintf('Остаток к оплате по PDF: %s.', $balance ?? '—'),
        ];
        $mismatches = [];

        if ($totalAccrued !== null && bccomp($calculatedAccrued, $totalAccrued, 2) !== 0) {
            $mismatches[] = sprintf('начислено: расчет %s, в PDF %s', $calculatedAccrued, $totalAccrued);
        }

        if ($totalPaid !== null && bccomp($calculatedPaid, $totalPaid, 2) !== 0) {
            $mismatches[] = sprintf('оплачено: расчет %s, в PDF %s', $calculatedPaid, $totalPaid);
        }

        if ($balance !== null && bccomp($calculatedAmountDue, $balance, 2) !== 0) {
            $mismatches[] = sprintf('остаток к оплате: расчет %s, в PDF %s', $calculatedAmountDue, $balance);
        }

        if ($mismatches !== []) {
            $details[] = 'Расхождение итогов: '.implode('; ', $mismatches).'.';

            return $this->item('Начисления и остаток к оплате', self::STATE_ATTENTION, 'Итоги по строкам импорта не совпадают с итоговой строкой PDF.', $details);
        }

        return $this->item('Начисления и остаток к оплате', self::STATE_CREATE, sprintf('К импорту подготовлено %d исторических строк начислений, остаток к оплате сходится с PDF.', count($rows)), $details);
    }

    /**
     * @return list<array{from: string, to: string|null, social_norm_rate: string, above_norm_rate: string, rows: int}>
     */
    private function buildTariffPeriods(array $rows): array
    {
        $periods = [];
        $current = null;
        $previousPeriodStart = null;

        foreach ($rows as $row) {
            $periodStart = $this->optionalString($row['period_start'] ?? null);
            $socialNormRate = $this->optionalString($row['social_norm_rate'] ?? null);
            $aboveNormRate = $this->optionalString($row['above_norm_rate'] ?? null);

            if ($periodStart === null || $socialNormRate === null || $aboveNormRate === null) {
                continue;
            }

            $key = $socialNormRate.'|'.$aboveNormRate;

            if ($current === null) {
                $current = [
                    'key' => $key,
                    'from' => $periodStart,
                    'to' => null,
                    'social_norm_rate' => $socialNormRate,
                    'above_norm_rate' => $aboveNormRate,
                    'rows' => 1,
                ];
            } elseif ($current['key'] === $key) {
                ++$current['rows'];
            } else {
                $current['to'] = $previousPeriodStart;
                unset($current['key']);
                $periods[] = $current;
                $current = [
                    'key' => $key,
                    'from' => $periodStart,
                    'to' => null,
                    'social_norm_rate' => $socialNormRate,
                    'above_norm_rate' => $aboveNormRate,
                    'rows' => 1,
                ];
            }

            $previousPeriodStart = $periodStart;
        }

        if ($current !== null) {
            $current['to'] = $previousPeriodStart;
            unset($current['key']);
            $periods[] = $current;
        }

        return $periods;
    }

    /**
     * @return list<array{period_start: string, month: int, value: string}>
     */
    private function buildSocialNorms(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $month = is_numeric($row['month'] ?? null) ? (int) $row['month'] : null;
            $periodStart = $this->optionalString($row['period_start'] ?? null);
            $value = $this->optionalString($row['social_norm_kwh'] ?? null);

            if ($month === null || $month < 1 || $month > 12 || $periodStart === null || $value === null) {
                continue;
            }

            $indexed[$periodStart] = [
                'period_start' => $periodStart,
                'month' => $month,
                'value' => $value,
            ];
        }

        ksort($indexed);

        return array_values($indexed);
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
     * @param list<array{state: string}> $items
     *
     * @return array{reuse: int, create: int, attention: int, blocked: int, skip: int}
     */
    private function summarize(array $items): array
    {
        $summary = [
            self::STATE_REUSE => 0,
            self::STATE_CREATE => 0,
            self::STATE_ATTENTION => 0,
            self::STATE_BLOCKED => 0,
            self::STATE_SKIP => 0,
        ];

        foreach ($items as $item) {
            $state = $item['state'];

            if (array_key_exists($state, $summary)) {
                ++$summary[$state];
            }
        }

        return $summary;
    }

    /**
     * @return array{title: string, state: string, state_label: string, badge_class: string, message: string, details: list<string>}
     */
    private function item(string $title, string $state, string $message, array $details): array
    {
        return [
            'title' => $title,
            'state' => $state,
            'state_label' => match ($state) {
                self::STATE_REUSE => 'Найдено',
                self::STATE_CREATE => 'Создать',
                self::STATE_ATTENTION => 'Проверить',
                self::STATE_BLOCKED => 'Блокер',
                self::STATE_SKIP => 'Пропуск',
                default => '—',
            },
            'badge_class' => match ($state) {
                self::STATE_REUSE => 'text-bg-success',
                self::STATE_CREATE => 'text-bg-primary',
                self::STATE_ATTENTION => 'text-bg-warning',
                self::STATE_BLOCKED => 'text-bg-danger',
                self::STATE_SKIP => 'text-bg-secondary',
                default => 'text-bg-secondary',
            },
            'message' => $message,
            'details' => $this->stringList($details),
        ];
    }

    /**
     * @return array{last_name: string, first_name: string, second_name: string|null}|null
     */
    private function parseSubscriberName(?string $fullName): ?array
    {
        $fullName = ZavetyMichurinaPersonNameNormalizer::normalizeFullName($fullName);

        if ($fullName === null) {
            return null;
        }

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

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function moneyStringOrNull(mixed $value): ?string
    {
        $value = $this->optionalString($value);

        if ($value === null) {
            return null;
        }

        $value = str_replace(',', '.', str_replace(' ', '', $value));

        if (!preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        return bcadd($value, '0', 2);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function sumMoneyFromRows(array $rows, string $field): string
    {
        $sum = '0.00';

        foreach ($rows as $row) {
            $amount = $this->moneyStringOrNull($row[$field] ?? null);

            if ($amount !== null) {
                $sum = bcadd($sum, $amount, 2);
            }
        }

        return $sum;
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

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            $item = $this->optionalString($item);

            if ($item !== null) {
                $strings[] = $item;
            }
        }

        return $strings;
    }
}
