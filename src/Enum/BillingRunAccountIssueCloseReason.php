<?php

namespace App\Enum;

enum BillingRunAccountIssueCloseReason: string
{
    case Resolved = 'resolved';
    case Ignored = 'ignored';
    case CancelledRun = 'cancelled_run';
    case Obsolete = 'obsolete';

    public function label(): string
    {
        return match ($this) {
            self::Resolved => 'Исправлено',
            self::Ignored => 'Проигнорировано',
            self::CancelledRun => 'Расчет отменен',
            self::Obsolete => 'Устарело',
        };
    }
}
