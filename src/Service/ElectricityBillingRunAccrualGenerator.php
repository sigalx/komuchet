<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\Accrual;
use App\Entity\BillingRun;
use App\Entity\BillingRunAccountIssue;
use App\Entity\ElectricityAccrualContext;
use App\Entity\ElectricityAccrualLine;
use App\Entity\ElectricityAccrualRegister;
use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\AccrualType;
use App\Enum\BillingRunAccountIssueType;
use App\Enum\BillingRunKind;
use App\Enum\ElectricityConsumptionBandAllocationMethod;
use App\Repository\AccountElectricityTariffProfileAssignmentRepository;
use App\Repository\AccountRepository;
use App\Repository\AccrualRepository;
use App\Repository\BillingRunAccountIssueRepository;
use App\Repository\BillingSettingsRepository;
use App\Repository\ElectricityConsumptionBandRuleRangeRepository;
use App\Repository\ElectricityConsumptionBandRuleRepository;
use App\Repository\ElectricityMeterReadingRepository;
use App\Repository\ElectricityMeterRegisterRepository;
use App\Repository\ElectricityMeterRepository;
use App\Repository\ElectricityTariffPeriodRepository;
use App\Repository\ElectricityTariffRateRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

final readonly class ElectricityBillingRunAccrualGenerator
{
    private const DEFAULT_INVOICE_GENERATION_DAY = 5;
    private const CALCULATION_VERSION = 'electricity_mvp_v1';

    public function __construct(
        private AccountRepository $accountRepository,
        private AccrualRepository $accrualRepository,
        private BillingRunAccountIssueRepository $issueRepository,
        private BillingSettingsRepository $billingSettingsRepository,
        private ElectricityMeterRepository $meterRepository,
        private ElectricityMeterRegisterRepository $meterRegisterRepository,
        private ElectricityMeterReadingRepository $meterReadingRepository,
        private AccountElectricityTariffProfileAssignmentRepository $tariffProfileAssignmentRepository,
        private ElectricityTariffPeriodRepository $tariffPeriodRepository,
        private ElectricityConsumptionBandRuleRepository $consumptionBandRuleRepository,
        private ElectricityConsumptionBandRuleRangeRepository $consumptionBandRuleRangeRepository,
        private ElectricityTariffRateRepository $tariffRateRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function generateForDraft(BillingRun $billingRun, ?User $createdBy = null): ElectricityBillingRunAccrualGenerationResult
    {
        if (!$billingRun->isDraft()) {
            throw new \LogicException('Electricity accruals can be generated only for draft billing runs.');
        }

        if ($billingRun->getKind() !== BillingRunKind::Electricity) {
            return new ElectricityBillingRunAccrualGenerationResult();
        }

        $workspace = $billingRun->getWorkspace();

        if (!$workspace instanceof Workspace) {
            throw new \LogicException('Billing run must be bound to a workspace.');
        }

        $result = new ElectricityBillingRunAccrualGenerationResult();

        foreach ($this->accountRepository->findActiveByWorkspace($workspace) as $account) {
            if ($this->issueRepository->hasOpenByBillingRunAccount($workspace, $billingRun, $account)) {
                ++$result->skippedOpenIssues;

                continue;
            }

            if ($this->accrualRepository->findOneByBillingRunAndAccount($workspace, $billingRun, $account) instanceof Accrual) {
                ++$result->skippedExisting;

                continue;
            }

            $existingPostedAccrual = $this->accrualRepository->findOneActivePostedByAccountTypeAndPeriod(
                $workspace,
                $account,
                AccrualType::Electricity,
                $billingRun->getPeriodStart(),
                $billingRun->getPeriodEnd(),
            );

            if ($existingPostedAccrual instanceof Accrual) {
                $existingBillingRun = $existingPostedAccrual->getBillingRun();

                if ($existingBillingRun instanceof BillingRun && !$existingBillingRun->getUuid()->equals($billingRun->getUuid())) {
                    if ($this->createCalculationErrorIssue($workspace, $billingRun, $account, 'Уже есть проведенное начисление за этот период в другом расчете.', $createdBy)) {
                        ++$result->failed;
                    } else {
                        ++$result->skippedIgnoredCalculationErrors;
                    }

                    continue;
                }

                if (!$existingBillingRun instanceof BillingRun) {
                    $existingPostedAccrual
                        ->setBillingRun($billingRun)
                        ->touch($createdBy);
                }

                ++$result->reusedPosted;

                continue;
            }

            try {
                $this->persistAccrual($workspace, $billingRun, $account, $createdBy);
                ++$result->created;
            } catch (\RuntimeException $exception) {
                if ($this->createCalculationErrorIssue($workspace, $billingRun, $account, $exception->getMessage(), $createdBy)) {
                    ++$result->failed;
                } else {
                    ++$result->skippedIgnoredCalculationErrors;
                }
            }
        }

        return $result;
    }

    private function persistAccrual(Workspace $workspace, BillingRun $billingRun, Account $account, ?User $createdBy): void
    {
        $periodStart = $billingRun->getPeriodStart();
        $settings = $this->billingSettingsRepository->findOneByWorkspace($workspace);
        $invoiceGenerationDate = $this->invoiceGenerationDate($billingRun->getPeriodEnd(), $settings?->getInvoiceGenerationDay() ?? self::DEFAULT_INVOICE_GENERATION_DAY);
        $meter = $this->meterRepository->findOneActiveByWorkspaceAndAccount($workspace, $account);

        if (!$meter instanceof ElectricityMeter) {
            throw new \RuntimeException('У участка нет активного электросчетчика.');
        }

        $registers = $this->meterRegisterRepository->findByMeter($workspace, $meter);

        if ($registers === []) {
            throw new \RuntimeException('У активного электросчетчика нет тарифных зон.');
        }

        $assignment = $this->tariffProfileAssignmentRepository->findOneEffectiveByAccountAt($workspace, $account, $periodStart);
        $tariffProfile = $assignment?->getTariffProfile();

        if ($tariffProfile === null) {
            throw new \RuntimeException('У участка нет действующего тарифного профиля.');
        }

        $tariffPeriod = $this->tariffPeriodRepository->findOneActiveByProfileAt($workspace, $tariffProfile, $periodStart);

        if (!$tariffPeriod instanceof ElectricityTariffPeriod) {
            throw new \RuntimeException('Для тарифного профиля нет действующего тарифного периода.');
        }

        $rule = $this->consumptionBandRuleRepository->findOneActiveAllScopeByProfileMonthAt(
            $workspace,
            $tariffProfile,
            (int) $periodStart->format('n'),
            $periodStart
        );

        if ($rule === null) {
            throw new \RuntimeException('Нет действующего глобального правила нормы для расчетного месяца.');
        }

        $ranges = $this->consumptionBandRuleRangeRepository->findByRule($workspace, $rule);

        if ($ranges === []) {
            throw new \RuntimeException('Действующее правило нормы не содержит диапазонов.');
        }

        $registerReadings = [];
        $zoneConsumptions = [];

        foreach ($registers as $register) {
            $registerData = $this->buildRegisterData($workspace, $meter, $register, $periodStart, $invoiceGenerationDate);
            $registerReadings[] = $registerData;
            $zoneConsumptions[] = [
                'tariffZone' => $registerData['tariffZone'],
                'consumptionMilliKwh' => $registerData['consumptionMilliKwh'],
            ];
        }

        $lineConsumptions = $this->allocateLineConsumptions($zoneConsumptions, $ranges, $rule->getAllocationMethod());
        $rateMap = $this->buildRateMap($workspace, $tariffPeriod);
        $lineData = [];
        $totalCents = 0;

        foreach ($lineConsumptions as $lineConsumption) {
            $rate = $rateMap[$this->rateKey($lineConsumption['tariffZone'], $lineConsumption['consumptionBand'])] ?? null;

            if (!$rate instanceof ElectricityTariffRate) {
                throw new \RuntimeException(sprintf(
                    'Нет ставки тарифа для зоны "%s" и диапазона "%s".',
                    $lineConsumption['tariffZone']->getName(),
                    $lineConsumption['consumptionBand']->getName()
                ));
            }

            $lineAmountCents = $this->calculateAmountCents($lineConsumption['consumptionMilliKwh'], $this->decimalToScaledInt($rate->getRate(), 6));
            $totalCents += $lineAmountCents;
            $lineData[] = [
                'tariffZone' => $lineConsumption['tariffZone'],
                'consumptionBand' => $lineConsumption['consumptionBand'],
                'consumptionKwh' => $this->formatScaledInt($lineConsumption['consumptionMilliKwh'], 3),
                'rate' => $rate->getRate(),
                'amount' => $this->formatScaledInt($lineAmountCents, 2),
            ];
        }

        $accrual = (new Accrual(
            $workspace,
            $account,
            AccrualType::Electricity,
            $billingRun->getPeriodStart(),
            $billingRun->getPeriodEnd(),
            $this->formatScaledInt($totalCents, 2),
            $createdBy
        ))
            ->setBillingRun($billingRun)
            ->setCalculationVersion(self::CALCULATION_VERSION);

        $this->entityManager->persist($accrual);
        $this->entityManager->persist(new ElectricityAccrualContext(
            $workspace,
            $accrual,
            $meter,
            $tariffProfile,
            $tariffPeriod,
            $rule
        ));

        foreach ($registerReadings as $registerData) {
            $this->entityManager->persist(new ElectricityAccrualRegister(
                $workspace,
                $accrual,
                $meter,
                $registerData['tariffZone'],
                $registerData['currentReading'],
                $registerData['previousReading']
            ));
        }

        foreach ($lineData as $line) {
            $this->entityManager->persist(new ElectricityAccrualLine(
                $workspace,
                $accrual,
                $line['tariffZone'],
                $line['consumptionBand'],
                $line['consumptionKwh'],
                $line['rate'],
                $line['amount']
            ));
        }
    }

    /**
     * @return array{tariffZone: ElectricityTariffZone, previousReading: ElectricityMeterReading, currentReading: ElectricityMeterReading, consumptionMilliKwh: int}
     */
    private function buildRegisterData(
        Workspace $workspace,
        ElectricityMeter $meter,
        ElectricityMeterRegister $register,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $invoiceGenerationDate,
    ): array {
        $tariffZone = $register->getTariffZone();

        if (!$tariffZone instanceof ElectricityTariffZone) {
            throw new \RuntimeException('У регистра электросчетчика не задана тарифная зона.');
        }

        $previousReading = $this->meterReadingRepository->findLatestActiveBeforeOrOn($workspace, $meter, $tariffZone, $periodStart);
        $currentReading = $this->meterReadingRepository->findLatestActiveBeforeOrOn($workspace, $meter, $tariffZone, $invoiceGenerationDate);

        if (!$previousReading instanceof ElectricityMeterReading) {
            throw new \RuntimeException(sprintf('Нет предыдущего показания по зоне "%s".', $tariffZone->getName()));
        }

        if (!$currentReading instanceof ElectricityMeterReading) {
            throw new \RuntimeException(sprintf('Нет текущего показания по зоне "%s".', $tariffZone->getName()));
        }

        if ($previousReading->getUuid()->equals($currentReading->getUuid())) {
            throw new \RuntimeException(sprintf('Нет отдельного текущего показания по зоне "%s".', $tariffZone->getName()));
        }

        $consumptionMilliKwh = $this->decimalToScaledInt($currentReading->getReadingValue(), 3)
            - $this->decimalToScaledInt($previousReading->getReadingValue(), 3);

        if (0 > $consumptionMilliKwh) {
            throw new \RuntimeException(sprintf('Текущее показание по зоне "%s" меньше предыдущего.', $tariffZone->getName()));
        }

        return [
            'tariffZone' => $tariffZone,
            'previousReading' => $previousReading,
            'currentReading' => $currentReading,
            'consumptionMilliKwh' => $consumptionMilliKwh,
        ];
    }

    /**
     * @param list<array{tariffZone: ElectricityTariffZone, consumptionMilliKwh: int}> $zoneConsumptions
     * @param list<ElectricityConsumptionBandRuleRange> $ranges
     *
     * @return list<array{tariffZone: ElectricityTariffZone, consumptionBand: ElectricityConsumptionBand, consumptionMilliKwh: int}>
     */
    private function allocateLineConsumptions(array $zoneConsumptions, array $ranges, ElectricityConsumptionBandAllocationMethod $allocationMethod): array
    {
        return match ($allocationMethod) {
            ElectricityConsumptionBandAllocationMethod::PerTariffZone => $this->allocatePerTariffZone($zoneConsumptions, $ranges),
            ElectricityConsumptionBandAllocationMethod::TotalProportional => $this->allocateTotalProportional($zoneConsumptions, $ranges),
        };
    }

    /**
     * @param list<array{tariffZone: ElectricityTariffZone, consumptionMilliKwh: int}> $zoneConsumptions
     * @param list<ElectricityConsumptionBandRuleRange> $ranges
     *
     * @return list<array{tariffZone: ElectricityTariffZone, consumptionBand: ElectricityConsumptionBand, consumptionMilliKwh: int}>
     */
    private function allocatePerTariffZone(array $zoneConsumptions, array $ranges): array
    {
        $lines = [];

        foreach ($zoneConsumptions as $zoneConsumption) {
            foreach ($ranges as $range) {
                $consumptionBand = $range->getConsumptionBand();

                if (!$consumptionBand instanceof ElectricityConsumptionBand) {
                    continue;
                }

                $consumptionMilliKwh = $this->bandConsumption($zoneConsumption['consumptionMilliKwh'], $range);

                if (0 >= $consumptionMilliKwh) {
                    continue;
                }

                $lines[] = [
                    'tariffZone' => $zoneConsumption['tariffZone'],
                    'consumptionBand' => $consumptionBand,
                    'consumptionMilliKwh' => $consumptionMilliKwh,
                ];
            }
        }

        return $lines;
    }

    /**
     * @param list<array{tariffZone: ElectricityTariffZone, consumptionMilliKwh: int}> $zoneConsumptions
     * @param list<ElectricityConsumptionBandRuleRange> $ranges
     *
     * @return list<array{tariffZone: ElectricityTariffZone, consumptionBand: ElectricityConsumptionBand, consumptionMilliKwh: int}>
     */
    private function allocateTotalProportional(array $zoneConsumptions, array $ranges): array
    {
        $totalConsumptionMilliKwh = array_sum(array_column($zoneConsumptions, 'consumptionMilliKwh'));

        if (0 >= $totalConsumptionMilliKwh) {
            return [];
        }

        $positiveZoneConsumptions = array_values(array_filter(
            $zoneConsumptions,
            static fn (array $zoneConsumption): bool => 0 < $zoneConsumption['consumptionMilliKwh']
        ));
        $lines = [];

        foreach ($ranges as $range) {
            $consumptionBand = $range->getConsumptionBand();

            if (!$consumptionBand instanceof ElectricityConsumptionBand) {
                continue;
            }

            $bandConsumptionMilliKwh = $this->bandConsumption($totalConsumptionMilliKwh, $range);

            if (0 >= $bandConsumptionMilliKwh) {
                continue;
            }

            $remainingBandConsumption = $bandConsumptionMilliKwh;
            $lastIndex = array_key_last($positiveZoneConsumptions);

            foreach ($positiveZoneConsumptions as $index => $zoneConsumption) {
                $lineConsumptionMilliKwh = $index === $lastIndex
                    ? $remainingBandConsumption
                    : intdiv($bandConsumptionMilliKwh * $zoneConsumption['consumptionMilliKwh'], $totalConsumptionMilliKwh);
                $remainingBandConsumption -= $lineConsumptionMilliKwh;

                if (0 >= $lineConsumptionMilliKwh) {
                    continue;
                }

                $lines[] = [
                    'tariffZone' => $zoneConsumption['tariffZone'],
                    'consumptionBand' => $consumptionBand,
                    'consumptionMilliKwh' => $lineConsumptionMilliKwh,
                ];
            }
        }

        return $lines;
    }

    private function bandConsumption(int $baseConsumptionMilliKwh, ElectricityConsumptionBandRuleRange $range): int
    {
        $lowerBound = $this->decimalToScaledInt($range->getLowerBoundKwh(), 3);
        $upperBound = $range->getUpperBoundKwh() === null
            ? PHP_INT_MAX
            : $this->decimalToScaledInt($range->getUpperBoundKwh(), 3);

        return max(0, min($baseConsumptionMilliKwh, $upperBound) - $lowerBound);
    }

    /**
     * @return array<string, ElectricityTariffRate>
     */
    private function buildRateMap(Workspace $workspace, ElectricityTariffPeriod $tariffPeriod): array
    {
        $rateMap = [];

        foreach ($this->tariffRateRepository->findByPeriod($workspace, $tariffPeriod) as $rate) {
            if (!$rate instanceof ElectricityTariffRate || $rate->getTariffZone() === null || $rate->getConsumptionBand() === null) {
                continue;
            }

            $rateMap[$this->rateKey($rate->getTariffZone(), $rate->getConsumptionBand())] = $rate;
        }

        return $rateMap;
    }

    private function createCalculationErrorIssue(
        Workspace $workspace,
        BillingRun $billingRun,
        Account $account,
        string $message,
        ?User $createdBy,
    ): bool {
        $issue = $this->issueRepository->findOneOpenByBillingRunAccountAndType(
            $workspace,
            $billingRun,
            $account,
            BillingRunAccountIssueType::CalculationError
        );

        if ($issue instanceof BillingRunAccountIssue) {
            $issue->setMessage($message, $createdBy);

            return true;
        }

        if ($this->issueRepository->hasClosedIgnoredIssue($workspace, $billingRun, $account, BillingRunAccountIssueType::CalculationError)) {
            return false;
        }

        $this->entityManager->persist(new BillingRunAccountIssue(
            $workspace,
            $billingRun,
            $account,
            BillingRunAccountIssueType::CalculationError,
            $message,
            $createdBy
        ));

        return true;
    }

    private function invoiceGenerationDate(DateTimeImmutable $periodEnd, int $invoiceGenerationDay): DateTimeImmutable
    {
        return $periodEnd
            ->setDate((int) $periodEnd->format('Y'), (int) $periodEnd->format('m'), $invoiceGenerationDay)
            ->setTime(0, 0);
    }

    private function calculateAmountCents(int $consumptionMilliKwh, int $rateMicrounits): int
    {
        return intdiv($consumptionMilliKwh * $rateMicrounits + 5_000_000, 10_000_000);
    }

    private function decimalToScaledInt(string $value, int $scale): int
    {
        $value = trim(str_replace(',', '.', $value));
        $negative = str_starts_with($value, '-');

        if ($negative) {
            $value = substr($value, 1);
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $result = ((int) $whole * (10 ** $scale)) + (int) $fraction;

        return $negative ? -$result : $result;
    }

    private function formatScaledInt(int $value, int $scale): string
    {
        $negative = 0 > $value;
        $value = abs($value);
        $factor = 10 ** $scale;
        $whole = intdiv($value, $factor);
        $fraction = $value % $factor;

        return sprintf('%s%d.%s', $negative ? '-' : '', $whole, str_pad((string) $fraction, $scale, '0', STR_PAD_LEFT));
    }

    private function rateKey(ElectricityTariffZone $tariffZone, ElectricityConsumptionBand $consumptionBand): string
    {
        return sprintf('%s:%s', $tariffZone->getUuid()->toRfc4122(), $consumptionBand->getUuid()->toRfc4122());
    }
}
