<?php

namespace App\Enum;

enum WorkspaceUserRoleCode: string
{
    case Admin = 'admin';
    case Operator = 'operator';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Администратор хозяйства',
            self::Operator => 'Оператор хозяйства',
        };
    }
}
