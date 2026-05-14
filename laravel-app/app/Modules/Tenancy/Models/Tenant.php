<?php

namespace App\Modules\Tenancy\Models;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Models\BaseModel;
use App\Modules\TenantConfig\Models\TenantPolicy;
use App\Modules\TenantConfig\Models\TenantSetting;

class Tenant extends BaseModel
{
    protected $fillable = [
        'name',
        'slug',
        'status',
        'industry',
        'contact_email',
        'contact_phone',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'tenant_users', 'tenant_id', 'user_id')
            ->withPivot('role', 'is_primary')
            ->withTimestamps();
    }

    public function tenantUsers()
    {
        return $this->hasMany(TenantUser::class, 'tenant_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function settings()
    {
        return $this->hasOne(TenantSetting::class);
    }

    public function policies()
    {
        return $this->hasMany(TenantPolicy::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', TenantStatus::ACTIVE->value);
    }

    public function scopeTrial($query)
    {
        return $query->where('status', TenantStatus::TRIAL->value);
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::ACTIVE;
    }

    public function canAutomate(): bool
    {
        return in_array($this->status, [TenantStatus::ACTIVE, TenantStatus::TRIAL]);
    }
}
