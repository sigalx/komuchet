<?php

namespace App\Enum;

enum BillingRunKind: string
{
    case Electricity = 'electricity';

    public function label(): string
    {
        return match ($this) {
            self::Electricity => 'Электроэнергия',
        };
    }
}
