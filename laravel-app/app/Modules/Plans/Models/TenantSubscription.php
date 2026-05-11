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
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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
}
