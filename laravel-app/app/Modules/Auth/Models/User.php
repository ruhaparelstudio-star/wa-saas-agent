<?php

namespace App\Modules\Auth\Models;

use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Models\BaseModel;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\Authenticatable;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends BaseModel implements AuthenticatableContract, AuthorizableContract, FilamentUser
{
    use HasFactory, Notifiable, HasApiTokens, Authenticatable, Authorizable;

    protected $hidden = ['password', 'remember_token'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'tenant_id',
        'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if (!$this->is_active) {
            return false;
        }

        return match ($panel->getId()) {
            'superadmin' => $this->isSuperadmin(),
            'tenant' => $this->isTenantAdmin(),
            default => false,
        };
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isSuperadmin(): bool
    {
        return $this->role === UserRole::SUPERADMIN;
    }

    public function isTenantAdmin(): bool
    {
        return $this->role === UserRole::TENANT_ADMIN;
    }
}
