<?php

namespace App\Modules\TenantConfig\Services;

use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\TenantConfig\Models\TenantPolicy;
use App\Modules\TenantConfig\Support\PolicyDefaults;
use Illuminate\Support\Str;

class TenantPolicyService
{
    public function getPolicy(string $tenantId, PolicyKey $key): string
    {
        $policy = TenantPolicy::where('tenant_id', $tenantId)
            ->where('policy_key', $key->value)
            ->first();

        return $policy?->policy_value ?? PolicyDefaults::getDefault($key);
    }

    public function getPolicies(string $tenantId): array
    {
        $stored = TenantPolicy::where('tenant_id', $tenantId)
            ->get()
            ->keyBy(fn ($p) => $p->policy_key instanceof PolicyKey
                ? $p->policy_key->value
                : $p->policy_key
            )
            ->map(fn ($p) => $p->policy_value)
            ->toArray();

        // Merge defaults for keys not yet set
        return array_merge(PolicyDefaults::all(), $stored);
    }

    public function setPolicy(string $tenantId, PolicyKey $key, string $value): void
    {
        TenantPolicy::updateOrCreate(
            ['tenant_id' => $tenantId, 'policy_key' => $key->value],
            ['policy_value' => $value]
        );
    }
}
