<?php

namespace App\Modules\Shared\Enums;

enum UserRole: string
{
    case SUPERADMIN = 'superadmin';
    case TENANT_ADMIN = 'tenant_admin';

    public function label(): string
    {
        return match($this) {
            self::SUPERADMIN => 'Super Admin',
            self::TENANT_ADMIN => 'Tenant Admin',
        };
    }
}
