<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Models\BaseModel;

class TenantUser extends BaseModel
{
    protected $fillable = [
        'tenant_id',
        'user_id',
        'role',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
