<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Models\Package;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PackageResolver
{
    public function getActivePackages(string $tenantId): Collection
    {
        return Cache::remember(
            "packages:active:{$tenantId}",
            600,
            fn () => Package::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->active()
                ->with('activePrices')
                ->get()
        );
    }

    public function getPackageDetail(string $tenantId, string $slug): ?Package
    {
        return Package::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->active()
            ->with('activePrices')
            ->first();
    }

    public function matchByName(string $tenantId, string $rawName): ?Package
    {
        $cacheKey = "packages:match:{$tenantId}:" . md5($rawName);

        return Cache::remember($cacheKey, 300, function () use ($tenantId, $rawName) {
            // exact match first
            $exact = Package::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->active()
                ->where(function ($q) use ($rawName) {
                    $q->whereRaw('LOWER(name) = LOWER(?)', [$rawName])
                        ->orWhereRaw('LOWER(slug) = LOWER(?)', [$rawName]);
                })
                ->first();

            if ($exact) {
                return $exact;
            }

            // ILIKE fallback
            return Package::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->active()
                ->where(function ($q) use ($rawName) {
                    $q->where('name', 'ILIKE', "%{$rawName}%")
                        ->orWhere('slug', 'ILIKE', "%{$rawName}%");
                })
                ->first();
        });
    }

    public function invalidateCache(string $tenantId): void
    {
        Cache::forget("packages:active:{$tenantId}");
    }
}