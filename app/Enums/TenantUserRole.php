<?php

namespace App\Enums;

enum TenantUserRole: string
{
    case Admin = 'admin';
    case Writer = 'writer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Tenant admin',
            self::Writer => 'Writer',
        };
    }
}
