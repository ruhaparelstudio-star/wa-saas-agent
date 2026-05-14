<?php

namespace App\Modules\TenantConfig\Support;

use App\Modules\Shared\Enums\PolicyKey;

class PolicyDefaults
{
    public const DEFAULTS = [
        'pricelist_mode'             => 'public',
        'pricelist_min_requirement'  => '0',
        'lead_limit_fallback'        => 'queue',
        'after_hours_behavior'       => 'queue',
        'invoice_max_resend'         => '3',
        'concurrent_booking_lock'    => 'true',
    ];

    public static function getDefault(PolicyKey $key): string
    {
        return self::DEFAULTS[$key->value] ?? '';
    }

    public static function all(): array
    {
        return self::DEFAULTS;
    }
}
