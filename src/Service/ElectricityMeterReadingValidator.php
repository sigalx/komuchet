<?php

namespace App\Service;

use App\Entity\ElectricityMeter;
use App\Entity\ElectricityMeterReading;
use App\Entity\ElectricityTariffZone;
use App\Entity\Workspace;
use App\Repository\ElectricityMeterReadingRepository;
use App\Repository\ElectricityMeterRegisterRepository;
use DateTimeImmutable;

final readonly class ElectricityMeterReadingValidator
{
    public const CODE_TARIFF_ZONE_REQUIRED = 'tariff_zone_required';
    public const CODE_METER_REGISTER_MISSING = 'meter_register_missing';
    public const CODE_TAKEN_ON_IN_FUTURE = 'taken_on_in_future';
    public const CODE_TAKEN_ON_BEFORE_INSTALLATION = 'taken_on_before_installation';
    public const CODE_TAKEN_ON_AFTER_REMOVAL = 'taken_on_after_removal';
    public const CODE_READING_BELOW_PREVIOUS = 'reading_below_previous';
    public const CODE_READING_ABOVE_NEXT = 'reading_above_next';

    public function __construct(
        private ElectricityMeterRegisterRepository $registerRepository,
        private ElectricityMeterReadingRepository $readingRepository,
    ) {
    }

    /**
     * @return list<ElectricityMeterReadingValidationViolation>
     */
    public function validate(
        Workspace $workspace,
        ElectricityMeter $electricityMeter,
        ?ElectricityTariffZone $tariffZone,
        DateTimeImmutable $takenOn,
        string $readingValue,
        bool $requireMeterRegister = true,
        bool $forbidFuture = false,
        ?DateTimeImmutable $today = null,
    ): array {
        $violations = [];

        if (!$tariffZone instanceof ElectricityTariffZone) {
            $violations[] = new ElectricityMeterReadingValidationViolation(
                self::CODE_TARIFF_ZONE_REQUIRED,
                'Выберите тарифную зону.',
            );
        } elseif (
            $requireMeterRegister
            && !$this->registerRepository->findOneByMeterAndTariffZone($workspace, $electricityMeter, $tariffZone)
        ) {
            $violations[] = new ElectricityMeterReadingValidationViolation(
                self::CODE_METER_REGISTER_MISSING,
                'У счетчика нет регистра для выбранной тарифной зоны.',
            );
        }

        if ($forbidFuture && $this->isDateAfter($takenOn, $today ?? new DateTimeImmutable('today'))) {
            $violations[] = new ElectricityMeterReadingValidationViolation(
                self::CODE_TAKEN_ON_IN_FUTURE,
                'Дата снятия показания не может быть в будущем.',
            );
        }

        if ($this->isDateBefore($takenOn, $electricityMeter->getInstalledOn())) {
            $violations[] = new ElectricityMeterReadingValidationViolation(
                self::CODE_TAKEN_ON_BEFORE_INSTALLATION,
                'Дата снятия показания не может быть раньше даты установки счетчика.',
            );
        }

        if (
            $electricityMeter->getRemovedOn() !== null
            && $this->isDateAfter($takenOn, $electricityMeter->getRemovedOn())
        ) {
            $violations[] = new ElectricityMeterReadingValidationViolation(
                self::CODE_TAKEN_ON_AFTER_REMOVAL,
                'Дата снятия показания не может быть позже даты снятия счетчика.',
            );
        }

        if ($tariffZone instanceof ElectricityTariffZone) {
            $previousReading = $this->readingRepository->findLatestActiveBeforeOrOn(
                $workspace,
                $electricityMeter,
                $tariffZone,
                $takenOn,
            );

            if (
                $previousReading instanceof ElectricityMeterReading
                && $this->compareDecimal($readingValue, $previousReading->getReadingValue()) < 0
            ) {
                $violations[] = new ElectricityMeterReadingValidationViolation(
                    self::CODE_READING_BELOW_PREVIOUS,
                    'Показание не может быть меньше предыдущего активного показания этой зоны.',
                );
            }

            $nextReading = $this->readingRepository->findEarliestActiveAfter(
                $workspace,
                $electricityMeter,
                $tariffZone,
                $takenOn,
            );

            if (
                $nextReading instanceof ElectricityMeterReading
                && $this->compareDecimal($readingValue, $nextReading->getReadingValue()) > 0
            ) {
                $violations[] = new ElectricityMeterReadingValidationViolation(
                    self::CODE_READING_ABOVE_NEXT,
                    'Показание не может быть больше следующего активного показания этой зоны.',
                );
            }
        }

        return $violations;
    }

    private function isDateAfter(DateTimeImmutable $left, DateTimeImmutable $right): bool
    {
        return $left->format('Y-m-d') > $right->format('Y-m-d');
    }

    private function isDateBefore(DateTimeImmutable $left, DateTimeImmutable $right): bool
    {
        return $left->format('Y-m-d') < $right->format('Y-m-d');
    }

    private function compareDecimal(string $left, string $right): int
    {
        $left = $this->decimalToScaledIntString($left);
        $right = $this->decimalToScaledIntString($right);

        if (strlen($left) !== strlen($right)) {
            return strlen($left) <=> strlen($right);
        }

        return $left <=> $right;
    }

    private function decimalToScaledIntString(string $value, int $scale = 3): string
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        [$whole, $fraction] = array_pad(explode('.', $normalized, 2), 2, '');
        $whole = ltrim($whole, '0');
        $fraction = str_pad(substr($fraction, 0, $scale), $scale, '0');
        $scaled = ltrim($whole.$fraction, '0');

        return $scaled === '' ? '0' : $scaled;
    }
}
