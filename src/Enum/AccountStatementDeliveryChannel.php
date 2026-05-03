<?php

namespace App\Enum;

enum AccountStatementDeliveryChannel: string
{
    case Email = 'email';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
        };
    }
}
