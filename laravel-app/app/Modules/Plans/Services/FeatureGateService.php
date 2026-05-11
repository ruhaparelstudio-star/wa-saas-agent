<?php

namespace App\Modules\Plans\Services;

use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Shared\Enums\FeatureKey;
use Illuminate\Support\Facades\Cache;

class FeatureGateService
{
    private const CACHE_TTL = 300; // 5 minutes

    public function check(string $tenantId, FeatureKey $feature): bool
    {
        $value = $this->getValue($tenantId, $feature);

        if ($value === null) {
            return false;
        }

        // Boolean features
        if (in_array($feature, [
            FeatureKey::GOOGLE_CALENDAR_ENABLED,
            FeatureKey::FOLLOW_UP_AUTOMATION,
            FeatureKey::ANALYTICS_ADVANCED,
            FeatureKey::MULTI_CHANNEL,
        ])) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        // Numeric features: positive value = enabled, -1 = unlimited
        return (int) $value > 0 || (int) $value === -1;
    }

    public function getValue(string $tenantId, FeatureKey $feature): mixed
    {
        $features = $this->getFeaturesForTenant($tenantId);

        return $features[$feature->value] ?? null;
    }

    public function canAddWaAgent(string $tenantId, int $currentCount): bool
    {
        $limit = (int) $this->getValue($tenantId, FeatureKey::MAX_WA_AGENTS);

        if ($limit === -1) {
            return true;
        }

        return $currentCount < $limit;
    }

    public function isLeadLimitUnlimited(string $tenantId): bool
    {
        return (int) $this->getValue($tenantId, FeatureKey::MONTHLY_LEAD_LIMIT) === -1;
    }

    public function getLeadLimit(string $tenantId): int
    {
        return (int) $this->getValue($tenantId, FeatureKey::MONTHLY_LEAD_LIMIT);
    }

    public function isCalendarEnabled(string $tenantId): bool
    {
        return $this->check($tenantId, FeatureKey::GOOGLE_CALENDAR_ENABLED);
    }

    public function isFollowUpEnabled(string $tenantId): bool
    {
        return $this->check($tenantId, FeatureKey::FOLLOW_UP_AUTOMATION);
    }

    public function clearCache(string $tenantId): void
    {
        Cache::forget($this->cacheKey($tenantId));
    }

    private function getFeaturesForTenant(string $tenantId): array
    {
        return Cache::remember(
            $this->cacheKey($tenantId),
            self::CACHE_TTL,
            fn () => $this->loadFeaturesFromDb($tenantId)
        );
    }

    private function loadFeaturesFromDb(string $tenantId): array
    {
        $subscription = TenantSubscription::with('plan.features')
            ->where('tenant_id', $tenantId)
            ->first();

        if ($subscription === null || $subscription->plan === null) {
            return [];
        }

        $features = [];
        foreach ($subscription->plan->features as $planFeature) {
            $features[$planFeature->feature_key->value] = $planFeature->feature_value;
        }

        return $features;
    }

    private function cacheKey(string $tenantId): string
    {
        return "tenant_features:{$tenantId}";
    }
}
