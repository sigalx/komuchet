<?php

namespace App\Enum;

enum BillingRunAccountIssueType: string
{
    case MissingReading = 'missing_reading';
    case StaleReading = 'stale_reading';
    case InvalidReading = 'invalid_reading';
    case MissingTariff = 'missing_tariff';
    case MissingConsumptionBandRule = 'missing_consumption_band_rule';
    case CalculationError = 'calculation_error';

    public function label(): string
    {
        return match ($this) {
            self::MissingReading => 'Нет показаний',
            self::StaleReading => 'Устаревшие показания',
            self::InvalidReading => 'Некорректные показания',
            self::MissingTariff => 'Нет тарифа',
            self::MissingConsumptionBandRule => 'Нет правила нормы',
            self::CalculationError => 'Ошибка расчета',
        };
    }
}
