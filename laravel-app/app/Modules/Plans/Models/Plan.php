<?php

namespace App\Modules\Plans\Models;

use App\Modules\Shared\Models\BaseModel;

class Plan extends BaseModel
{
    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function features()
    {
        return $this->hasMany(PlanFeature::class, 'plan_id');
    }

    public function subscriptions()
    {
        return $this->hasMany(TenantSubscription::class, 'plan_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
