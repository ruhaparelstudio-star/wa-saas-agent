<?php

namespace App\Modules\TenantConfig\Models;

use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\Tenancy\Models\Tenant;

class TenantPolicy extends BaseModel
{
    protected $table = 'tenant_policies';

    protected $fillable = [
        'tenant_id',
        'policy_key',
        'policy_value',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'policy_key' => PolicyKey::class,
        ]);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
