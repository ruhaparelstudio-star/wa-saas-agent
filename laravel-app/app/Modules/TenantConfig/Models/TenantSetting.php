<?php

namespace App\Modules\TenantConfig\Models;

use App\Modules\Shared\Enums\TenantTone;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\Carbon;

class TenantSetting extends BaseModel
{
    protected $table = 'tenant_settings';

    protected $fillable = [
        'tenant_id',
        'tone',
        'timezone',
        'business_hours_start',
        'business_hours_end',
        'business_days',
        'after_hours_message',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'tone'          => TenantTone::class,
            'business_days' => 'array',
        ]);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isBusinessDay(Carbon $date): bool
    {
        $businessDays = $this->business_days ?? [1, 2, 3, 4, 5, 6];

        // Carbon dayOfWeek: 0=Sunday, 1=Monday ... 6=Saturday
        // Our system: 1=Monday, 7=Sunday
        $dayOfWeek = $date->dayOfWeek === 0 ? 7 : $date->dayOfWeek;

        return in_array($dayOfWeek, $businessDays);
    }
}
