<?php

namespace App\Modules\Plans\Models;

use App\Modules\Shared\Models\BaseModel;
use Carbon\Carbon;

class TenantSubscription extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
    ];

    protected function casts(): array
    {
        return [
            'starts_at'            => 'datetime',
            'ends_at'              => 'datetime',
            'trial_ends_at'        => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end'   => 'datetime',
            'created_at'           => 'datetime',
            'updated_at'           => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        if ($this->status === 'expired') {
            return true;
        }

        if ($this->ends_at !== null && $this->ends_at->isPast()) {
            return true;
        }

        return false;
    }

    public function isTrial(): bool
    {
        return $this->status === 'trial';
    }

    public function isInCurrentPeriod(Carbon $date): bool
    {
        if ($this->current_period_start === null || $this->current_period_end === null) {
            return false;
        }

        return $date->between($this->current_period_start, $this->current_period_end);
    }
}
