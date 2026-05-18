<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Models\Package;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class PackageResolver
{
    public function getActivePackages(string $tenantId): Collection
    {
        $cacheKey = "packages:active:{$tenantId}";

        $fresh = fn () => Package::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->active()
            ->with('activePrices')
            ->get();

        // Defensive: stale Redis entries from a previous deploy can deserialize as
        // __PHP_Incomplete_Class when a Package class moves namespace or property
        // shape changes. Detect that and rebuild rather than crashing the pipeline.
        $cached = Cache::get($cacheKey);
        if ($cached instanceof Collection && $cached->every(fn ($p) => $p instanceof Package)) {
            return $cached;
        }
        if ($cached !== null) {
            Cache::forget($cacheKey);
        }

        $packages = $fresh();
        Cache::put($cacheKey, $packages, 600);
        return $packages;
    }

    public function getPackageDetail(string $tenantId, string $slug): ?Package
    {
        return Package::withoutGlobalScopes()
            ->where("tenant_id", $tenantId)
            ->where("slug", $slug)
            ->active()
            ->with("activePrices")
            ->first();
    }

    public function matchByName(string $tenantId, string $rawName): ?Package
    {
        $cacheKey = "packages:match:id:{$tenantId}:" . md5($rawName);

        $cachedId = Cache::get($cacheKey);

        if (is_string($cachedId)) {
            return Package::withoutGlobalScopes()
                ->where('id', $cachedId)
                ->active()
                ->with('activePrices')
                ->first();
        }

        $exact = Package::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->active()
            ->where(function ($q) use ($rawName) {
                $q->whereRaw('LOWER(name) = LOWER(?)', [$rawName])
                    ->orWhereRaw('LOWER(slug) = LOWER(?)', [$rawName]);
            })
            ->first();

        $result = $exact ?? Package::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->active()
            ->where(function ($q) use ($rawName) {
                $q->where('name', 'ILIKE', "%{$rawName}%")
                    ->orWhere('slug', 'ILIKE', "%{$rawName}%");
            })
            ->first();

        if ($result !== null) {
            Cache::put($cacheKey, $result->id, 300);
        }

        return $result;
    }

    public function invalidateCache(string $tenantId): void
    {
        Cache::forget("packages:active:{$tenantId}");
    }
}
