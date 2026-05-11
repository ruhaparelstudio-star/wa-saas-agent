<?php

namespace App\Modules\Plans\Http\Controllers;

use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class PlanController extends Controller
{
    public function __construct(
        private FeatureGateService $featureGateService
    ) {}

    public function assignPlan(Request $request, string $tenantId): JsonResponse
    {
        $validated = $request->validate([
            'plan_code' => 'required|string|exists:plans,code',
            'trial_days' => 'nullable|integer|min:0',
        ]);

        $tenant = Tenant::findOrFail($tenantId);
        $plan = Plan::where('code', $validated['plan_code'])->firstOrFail();

        $trialDays = $validated['trial_days'] ?? 0;
        $startsAt = now();
        $trialEndsAt = $trialDays > 0 ? now()->addDays($trialDays) : null;
        $status = $trialDays > 0 ? 'trial' : 'active';

        $subscription = TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'id' => Str::uuid()->toString(),
                'plan_id' => $plan->id,
                'status' => $status,
                'starts_at' => $startsAt,
                'ends_at' => null,
                'trial_ends_at' => $trialEndsAt,
            ]
        );

        $this->featureGateService->clearCache($tenant->id);

        return response()->json([
            'message' => "Plan '{$plan->name}' berhasil di-assign ke tenant '{$tenant->name}'.",
            'subscription' => [
                'id' => $subscription->id,
                'tenant_id' => $subscription->tenant_id,
                'plan_code' => $plan->code,
                'plan_name' => $plan->name,
                'status' => $subscription->status,
                'starts_at' => $subscription->starts_at,
                'trial_ends_at' => $subscription->trial_ends_at,
            ],
        ]);
    }
}
