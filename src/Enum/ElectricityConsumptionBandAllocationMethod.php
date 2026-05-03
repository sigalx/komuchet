<?php

namespace App\Enum;

enum ElectricityConsumptionBandAllocationMethod: string
{
    case TotalProportional = 'total_proportional';
    case PerTariffZone = 'per_tariff_zone';
}
