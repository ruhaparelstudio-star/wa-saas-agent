<?php

namespace App\Modules\Plans\Models;

use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Models\BaseModel;

class PlanFeature extends BaseModel
{
    protected $fillable = [
        'plan_id',
        'feature_key',
        'feature_value',
    ];

    protected function casts(): array
    {
        return [
            'feature_key' => FeatureKey::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }
}
