<?php

namespace App\Enum;

enum AuditLogSource: string
{
    case App = 'app';
    case Db = 'db';
    case Import = 'import';
    case System = 'system';
}
