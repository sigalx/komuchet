<?php

namespace App\Service;

use App\Entity\Account;
use App\Entity\BillingRun;
use App\Entity\BillingRunAccountIssue;
use App\Entity\ElectricityConsumptionBand;
use App\Entity\ElectricityConsumptionBandRuleRange;
use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterRegister;
use App\Entity\ElectricityTariffPeriod;
use App\Entity\ElectricityTariffRate;
use App\Entity\ElectricityTariffZone;
use App\Entity\User;
use App\Entity\Workspace;
use App\Enum\BillingRunAccountIssueCloseReason;
use App\Enum\BillingRunAccountIssueType;
use App\Enum\BillingRunKind;
use App\Repository\AccountElectricityTariffProfileAssignmentRepository;
use App\Repository\AccountRepository;
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

final readonly class BillingRunIssueGenerator
{
    private const DEFAULT_INVOICE_GENERATION_DAY = 5;
    private const DEFAULT_READING_FRESHNESS_WINDOW_DAYS = 15;

    public function __construct(
        private AccountRepository $accountRepository,
        private BillingSettingsRepository $billingSettingsRepository,
        private BillingRunAccountIssueRepository $issueRepository,
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

    public function generateForDraft(BillingRun $billingRun, ?User $createdBy = null): BillingRunIssueGenerationResult
    {
        if (!$billingRun->isDraft()) {
            throw new \LogicException('Billing run issues can be generated only for draft billing runs.');
        }

        if ($billingRun->getKind() !== BillingRunKind::Electricity) {
            return new BillingRunIssueGenerationResult();
        }

        $workspace = $billingRun->getWorkspace();

        if ($workspace === null) {
            throw new \LogicException('Billing run must be bound to a workspace.');
        }

        $settings = $this->billingSettingsRepository->findOneByWorkspace($workspace);
        $invoiceGenerationDay = $settings?->getInvoiceGenerationDay() ?? self::DEFAULT_INVOICE_GENERATION_DAY;
        $freshnessWindowDays = $settings?->getReadingFreshnessWindowDays() ?? self::DEFAULT_READING_FRESHNESS_WINDOW_DAYS;
        $invoiceGenerationDate = $this->invoiceGenerationDate($billingRun->getPeriodEnd(), $invoiceGenerationDay);
        $freshnessStartDate = $invoiceGenerationDate->modify(sprintf('-%d days', $freshnessWindowDays));
        $calculationDate = $billingRun->getPeriodStart();
        $calculationMonth = (int) $calculationDate->format('n');
        $result = new BillingRunIssueGenerationResult();
        $actualIssueKeys = [];

        foreach ($this->accountRepository->findActiveByWorkspace($workspace) as $account) {
            /** @var array<string, list<string>> $messagesByType */
            $messagesByType = [];
            $meter = $this->meterRepository->findOneActiveByWorkspaceAndAccount($workspace, $account);
            $registers = [];

            if (!$meter instanceof ElectricityMeter) {
                $this->addMessage(
                    $messagesByType,
                    BillingRunAccountIssueType::MissingReading,
                    'У участка нет активного электросчетчика.'
                );
            } else {
                $registers = $this->meterRegisterRepository->findByMeter($workspace, $meter);
                $this->collectReadingIssues(
                    $messagesByType,
                    $workspace,
                    $meter,
                    $registers,
                    $billingRun->getPeriodStart(),
                    $invoiceGenerationDate,
                    $freshnessStartDate
                );
            }

            $assignment = $this->tariffProfileAssignmentRepository->findOneEffectiveByAccountAt($workspace, $account, $calculationDate);
            $tariffProfile = $assignment?->getTariffProfile();
            $tariffPeriod = null;
            $ranges = [];

            if ($tariffProfile === null) {
                $this->addMessage(
                    $messagesByType,
                    BillingRunAccountIssueType::MissingTariff,
                    sprintf('На %s у участка нет действующего тарифного профиля.', $calculationDate->format('d.m.Y'))
                );
            } else {
                $tariffPeriod = $this->tariffPeriodRepository->findOneActiveByProfileAt($workspace, $tariffProfile, $calculationDate);

                if (!$tariffPeriod instanceof ElectricityTariffPeriod) {
                    $this->addMessage(
                        $messagesByType,
                        BillingRunAccountIssueType::MissingTariff,
                        sprintf(
                            'Для тарифного профиля "%s" нет действующего тарифного периода на %s.',
                            $tariffProfile->getName(),
                            $calculationDate->format('d.m.Y')
                        )
                    );
                }

                $rule = $this->consumptionBandRuleRepository->findOneActiveAllScopeByProfileMonthAt(
                    $workspace,
                    $tariffProfile,
                    $calculationMonth,
                    $calculationDate
                );

                if ($rule === null) {
                    $this->addMessage(
                        $messagesByType,
                        BillingRunAccountIssueType::MissingConsumptionBandRule,
                        sprintf(
                            'Для тарифного профиля "%s" нет действующего правила нормы на месяц %02d.',
                            $tariffProfile->getName(),
                            $calculationMonth
                        )
                    );
                } else {
                    $ranges = $this->consumptionBandRuleRangeRepository->findByRule($workspace, $rule);

                    if ($ranges === []) {
                        $this->addMessage(
                            $messagesByType,
                            BillingRunAccountIssueType::MissingConsumptionBandRule,
                            sprintf(
                                'Действующее правило нормы для профиля "%s" на месяц %02d не содержит диапазонов.',
                                $tariffProfile->getName(),
                                $calculationMonth
                            )
                        );
                    }
                }
            }

            if ($tariffPeriod instanceof ElectricityTariffPeriod && $registers !== [] && $ranges !== []) {
                $this->collectMissingRateIssues($messagesByType, $workspace, $tariffPeriod, $registers, $ranges);
            }

            foreach ($messagesByType as $issueTypeValue => $messages) {
                $issueType = BillingRunAccountIssueType::from($issueTypeValue);
                $actualIssueKeys[$this->issueKey($account, $issueType)] = true;
                $message = implode("\n", array_unique($messages));
                $issue = $this->issueRepository->findOneOpenByBillingRunAccountAndType($workspace, $billingRun, $account, $issueType);

                if ($issue instanceof BillingRunAccountIssue) {
                    if ($issue->getMessage() !== $message) {
                        $issue->setMessage($message, $createdBy);
                        ++$result->updated;
                    }

                    continue;
                }

                if ($this->issueRepository->hasClosedIgnoredIssue($workspace, $billingRun, $account, $issueType)) {
                    ++$result->ignored;

                    continue;
                }

                $this->entityManager->persist(new BillingRunAccountIssue(
                    $workspace,
                    $billingRun,
                    $account,
                    $issueType,
                    $message,
                    $createdBy
                ));
                ++$result->created;
            }
        }

        foreach ($this->issueRepository->findOpenByBillingRun($workspace, $billingRun) as $issue) {
            $account = $issue->getAccount();

            if ($account === null) {
                continue;
            }

            if ($issue->getIssueType() === BillingRunAccountIssueType::CalculationError) {
                continue;
            }

            if (isset($actualIssueKeys[$this->issueKey($account, $issue->getIssueType())])) {
                continue;
            }

            $issue->close(
                BillingRunAccountIssueCloseReason::Resolved,
                'Автоматически закрыто повторной проверкой: проблема больше не воспроизводится.',
                $createdBy
            );
            ++$result->closed;
        }

        return $result;
    }

    /**
     * @param array<string, list<string>> $messagesByType
     * @param list<ElectricityMeterRegister> $registers
     */
    private function collectReadingIssues(
        array &$messagesByType,
        Workspace $workspace,
        ElectricityMeter $meter,
        array $registers,
        DateTimeImmutable $periodStart,
        DateTimeImmutable $invoiceGenerationDate,
        DateTimeImmutable $freshnessStartDate,
    ): void {
        if ($registers === []) {
            $this->addMessage(
                $messagesByType,
                BillingRunAccountIssueType::MissingReading,
                'У активного электросчетчика нет тарифных зон.'
            );

            return;
        }

        foreach ($registers as $register) {
            $tariffZone = $register->getTariffZone();

            if (!$tariffZone instanceof ElectricityTariffZone) {
                continue;
            }

            $previousReading = $this->meterReadingRepository->findLatestActiveBeforeOrOn(
                $workspace,
                $meter,
                $tariffZone,
                $periodStart
            );
            $latestReading = $this->meterReadingRepository->findLatestActiveBeforeOrOn(
                $workspace,
                $meter,
                $tariffZone,
                $invoiceGenerationDate
            );
            $zoneLabel = $this->tariffZoneLabel($tariffZone);

            if ($previousReading === null) {
                $this->addMessage(
                    $messagesByType,
                    BillingRunAccountIssueType::MissingReading,
                    sprintf('Нет предыдущего активного показания по зоне "%s" на начало периода %s.', $zoneLabel, $periodStart->format('d.m.Y'))
                );
            }

            if ($latestReading === null) {
                $this->addMessage(
                    $messagesByType,
                    BillingRunAccountIssueType::MissingReading,
                    sprintf('Нет активного показания по зоне "%s" на дату формирования %s.', $zoneLabel, $invoiceGenerationDate->format('d.m.Y'))
                );

                continue;
            }

            if ($latestReading->getTakenOn() < $freshnessStartDate) {
                $this->addMessage(
                    $messagesByType,
                    BillingRunAccountIssueType::StaleReading,
                    sprintf(
                        'Последнее показание по зоне "%s" снято %s, раньше допустимой даты %s.',
                        $zoneLabel,
                        $latestReading->getTakenOn()->format('d.m.Y'),
                        $freshnessStartDate->format('d.m.Y')
                    )
                );
            }
        }
    }

    /**
     * @param array<string, list<string>> $messagesByType
     * @param list<ElectricityMeterRegister> $registers
     * @param list<ElectricityConsumptionBandRuleRange> $ranges
     */
    private function collectMissingRateIssues(
        array &$messagesByType,
        Workspace $workspace,
        ElectricityTariffPeriod $tariffPeriod,
        array $registers,
        array $ranges,
    ): void {
        $rateKeys = [];

        foreach ($this->tariffRateRepository->findByPeriod($workspace, $tariffPeriod) as $rate) {
            if (!$rate instanceof ElectricityTariffRate || $rate->getTariffZone() === null || $rate->getConsumptionBand() === null) {
                continue;
            }

            $rateKeys[$this->rateKey($rate->getTariffZone(), $rate->getConsumptionBand())] = true;
        }

        foreach ($registers as $register) {
            $tariffZone = $register->getTariffZone();

            if (!$tariffZone instanceof ElectricityTariffZone) {
                continue;
            }

            foreach ($ranges as $range) {
                $consumptionBand = $range->getConsumptionBand();

                if ($consumptionBand === null) {
                    continue;
                }

                $rateKey = $this->rateKey($tariffZone, $consumptionBand);

                if (isset($rateKeys[$rateKey])) {
                    continue;
                }

                $this->addMessage(
                    $messagesByType,
                    BillingRunAccountIssueType::MissingTariff,
                    sprintf(
                        'Нет ставки тарифа для зоны "%s" и диапазона "%s".',
                        $this->tariffZoneLabel($tariffZone),
                        $consumptionBand->getName()
                    )
                );
            }
        }
    }

    /**
     * @param array<string, list<string>> $messagesByType
     */
    private function addMessage(array &$messagesByType, BillingRunAccountIssueType $issueType, string $message): void
    {
        $messagesByType[$issueType->value][] = $message;
    }

    private function invoiceGenerationDate(DateTimeImmutable $periodEnd, int $invoiceGenerationDay): DateTimeImmutable
    {
        return $periodEnd
            ->setDate((int) $periodEnd->format('Y'), (int) $periodEnd->format('m'), $invoiceGenerationDay)
            ->setTime(0, 0);
    }

    private function tariffZoneLabel(ElectricityTariffZone $tariffZone): string
    {
        return sprintf('%s (%s)', $tariffZone->getName(), $tariffZone->getCode());
    }

    private function rateKey(ElectricityTariffZone $tariffZone, ElectricityConsumptionBand $consumptionBand): string
    {
        return sprintf('%s:%s', $tariffZone->getUuid()->toRfc4122(), $consumptionBand->getUuid()->toRfc4122());
    }

    private function issueKey(Account $account, BillingRunAccountIssueType $issueType): string
    {
        return sprintf('%s:%s', $account->getUuid()->toRfc4122(), $issueType->value);
    }
}
