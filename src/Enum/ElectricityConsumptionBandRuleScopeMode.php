<?php

namespace App\Enum;

enum ElectricityConsumptionBandRuleScopeMode: string
{
    case Include = 'include';
    case Exclude = 'exclude';
}
