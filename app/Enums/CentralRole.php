<?php

namespace App\Enums;

enum CentralRole: string
{
    case SuperAdmin = 'super_admin';
    case Support = 'support';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super admin',
            self::Support => 'Support',
        };
    }
}
