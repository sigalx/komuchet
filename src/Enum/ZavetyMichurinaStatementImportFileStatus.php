<?php

namespace App\Enum;

enum ZavetyMichurinaStatementImportFileStatus: string
{
    case Pending = 'pending';
    case Parsed = 'parsed';
    case Failed = 'failed';
    case Applied = 'applied';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Ожидает',
            self::Parsed => 'Распознана',
            self::Failed => 'Ошибка',
            self::Applied => 'Применена',
            self::Cancelled => 'Отменена',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'text-bg-secondary',
            self::Parsed => 'text-bg-success',
            self::Failed => 'text-bg-danger',
            self::Applied => 'text-bg-primary',
            self::Cancelled => 'text-bg-warning',
        };
    }
}
