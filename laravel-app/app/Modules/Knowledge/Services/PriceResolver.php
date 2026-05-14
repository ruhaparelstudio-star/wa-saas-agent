<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Models\Package;
use App\Modules\Knowledge\Models\PackagePrice;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class PriceResolver
{
    public function getActivePrice(string $packageId, Carbon $date): ?PackagePrice
    {
        $cacheKey = "price:active:{$packageId}:{$date->toDateString()}";

        return Cache::remember($cacheKey, 1800, function () use ($packageId, $date) {
            return PackagePrice::withoutGlobalScopes()
                ->where('package_id', $packageId)
                ->where('is_active', true)
                ->where('valid_from', '<=', $date->toDateString())
                ->where(function ($q) use ($date) {
                    $q->whereNull('valid_until')
                        ->orWhere('valid_until', '>=', $date->toDateString());
                })
                ->orderByDesc('valid_from')
                ->first();
        });
    }

    public function getLowestCurrentPrice(string $tenantId): ?PackagePrice
    {
        $today = Carbon::today()->toDateString();

        return PackagePrice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('valid_from', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $today);
            })
            ->orderBy('price_idr')
            ->first();
    }

    public function getPriceRange(string $tenantId): array
    {
        $today = Carbon::today();
        $todayStr = $today->toDateString();

        $base = PackagePrice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where('valid_from', '<=', $todayStr)
            ->where(function ($q) use ($todayStr) {
                $q->whereNull('valid_until')
                    ->orWhere('valid_until', '>=', $todayStr);
            });

        return [
            'min' => (clone $base)->orderBy('price_idr')->first(),
            'max' => (clone $base)->orderByDesc('price_idr')->first(),
            'date' => $today,
        ];
    }
}