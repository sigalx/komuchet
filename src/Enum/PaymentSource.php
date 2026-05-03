<?php

namespace App\Enum;

enum PaymentSource: string
{
    case Manual = 'manual';
    case Import = 'import';
}
