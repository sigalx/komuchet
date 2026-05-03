<?php

namespace App\Enum;

enum ElectricityMeterReadingSource: string
{
    case Subscriber = 'subscriber';
    case Admin = 'admin';
    case Import = 'import';
}
