<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Models\Asset;
use App\Modules\Shared\Enums\AssetType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class AssetResolver
{
    public function getActivePricelist(string $tenantId): ?Asset
    {
        return Asset::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->active()
            ->byType(AssetType::PRICELIST)
            ->latest()
            ->first();
    }

    public function getAssetsByType(string $tenantId, AssetType $type): Collection
    {
        return Asset::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->active()
            ->byType($type)
            ->get();
    }

    public function getPricelistUrl(string $tenantId): ?string
    {
        $asset = $this->getActivePricelist($tenantId);

        if (!$asset) {
            return null;
        }

        return $asset->file_url ?? Storage::url($asset->file_path);
    }
}
