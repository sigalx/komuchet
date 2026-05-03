<?php

namespace App\Enum;

enum SubscriberAccountAccessRole: string
{
    case Owner = 'owner';
    case Representative = 'representative';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Владелец',
            self::Representative => 'Представитель',
            self::Viewer => 'Наблюдатель',
        };
    }
}
