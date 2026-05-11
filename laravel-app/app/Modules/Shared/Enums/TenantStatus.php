<?php

namespace App\Modules\Shared\Enums;

enum TenantStatus: string
{
    case TRIAL = 'trial';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
    case SUSPENDED = 'suspended';

    public function label(): string
    {
        return match($this) {
            self::TRIAL => 'Trial',
            self::ACTIVE => 'Active',
            self::EXPIRED => 'Expired',
            self::SUSPENDED => 'Suspended',
        };
    }
}
