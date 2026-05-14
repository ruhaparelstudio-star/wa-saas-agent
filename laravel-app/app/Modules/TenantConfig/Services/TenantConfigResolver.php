<?php

namespace App\Modules\TenantConfig\Services;

use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\DTOs\TenantConfigDTO;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\TenantTone;
use App\Modules\TenantConfig\Models\TenantSetting;
use App\Modules\TenantConfig\Support\PolicyDefaults;
use Illuminate\Support\Facades\Cache;

class TenantConfigResolver
{
    private const CACHE_TTL = 300; // 5 minutes

    public function __construct(
        private readonly TenantPolicyService $policyService,
        private readonly FeatureGateService $featureGateService,
    ) {}

    public function resolve(string $tenantId): TenantConfigDTO
    {
        $data = Cache::remember(
            $this->cacheKey($tenantId),
            self::CACHE_TTL,
            fn () => $this->buildDto($tenantId)->toArray()
        );

        return TenantConfigDTO::from($data);
    }

    public function get(string $tenantId, string $key, mixed $default = null): mixed
    {
        $dto = $this->resolve($tenantId);

        // Check DTO properties first
        if (property_exists($dto, $key)) {
            $value = $dto->$key;
            return $value instanceof \BackedEnum ? $value->value : $value;
        }

        // Check policies
        return $dto->policies[$key] ?? $default;
    }

    public function invalidateCache(string $tenantId): void
    {
        Cache::forget($this->cacheKey($tenantId));
    }

    private function buildDto(string $tenantId): TenantConfigDTO
    {
        $setting = TenantSetting::where('tenant_id', $tenantId)->first();

        $policies = $this->policyService->getPolicies($tenantId);

        $features = $this->buildFeatures($tenantId);

        return TenantConfigDTO::from([
            'tenant_id'            => $tenantId,
            'tone'                 => $setting?->tone ?? TenantTone::SEMI_FORMAL,
            'timezone'             => $setting?->timezone ?? 'Asia/Jakarta',
            'business_hours_start' => $setting?->business_hours_start ?? '08:00',
            'business_hours_end'   => $setting?->business_hours_end ?? '21:00',
            'policies'             => $policies,
            'features'             => $features,
        ]);
    }

    private function buildFeatures(string $tenantId): array
    {
        $features = [];

        foreach (FeatureKey::cases() as $key) {
            $features[$key->value] = $this->featureGateService->getValue($tenantId, $key);
        }

        return $features;
    }

    private function cacheKey(string $tenantId): string
    {
        return "tenant_config:{$tenantId}";
    }
}
