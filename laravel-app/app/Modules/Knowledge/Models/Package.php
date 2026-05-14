<?php

namespace App\Modules\Knowledge\Models;

use App\Modules\Shared\Models\TenantBaseModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Package extends TenantBaseModel
{
    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
        'description',
        'category',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ]);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PackagePrice::class);
    }

    public function activePrices(): HasMany
    {
        return $this->hasMany(PackagePrice::class)->where('is_active', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function getActivePriceForDate(Carbon $date): ?PackagePrice
    {
        return $this->prices()
            ->where('is_active', true)
            ->where('valid_from', '<=', $date->toDateString())
            ->where(function (Builder $q) use ($date) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $date->toDateString());
            })
            ->orderByDesc('valid_from')
            ->first();
    }
}