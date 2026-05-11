<?php

namespace App\Modules\Shared\Scopes;

use App\Modules\Shared\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        if ($user->role === UserRole::SUPERADMIN) {
            return;
        }

        $builder->where($model->getTable() . '.tenant_id', $user->tenant_id);
    }

    public static function withoutTenant(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope(static::class);
    }
}
