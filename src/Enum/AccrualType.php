<?php

namespace App\Enum;

enum AccrualType: string
{
    case Electricity = 'electricity';
    case MembershipFee = 'membership_fee';
    case Water = 'water';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Electricity => 'Электроэнергия',
            self::MembershipFee => 'Членский взнос',
            self::Water => 'Вода',
            self::Other => 'Прочее',
        };
    }
}
