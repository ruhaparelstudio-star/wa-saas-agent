<?php

namespace App\Modules\TenantConfig\Support;

use App\Modules\Shared\Enums\PolicyKey;

class PolicyDefaults
{
    public const DEFAULTS = [
        'pricelist_mode'              => 'text',
        'pricelist_min_requirement'   => 'require_customer_name',
        'lead_limit_fallback'         => 'queue',
        'after_hours_behavior'        => 'queue',
        'invoice_max_resend'          => '3',
        'concurrent_booking_lock'     => 'true',
        'classifier_context_window'   => '10',
        'composer_context_window'     => '20',
        'context_summary_threshold'   => '40',
        'context_summary_keep_recent' => '20',
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
