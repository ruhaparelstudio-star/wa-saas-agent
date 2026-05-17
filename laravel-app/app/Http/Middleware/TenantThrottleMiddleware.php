<?php

namespace App\Http\Middleware;

use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\WhatsApp\Models\WaAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TenantThrottleMiddleware
{
    private const PLAN_LIMITS = [
        'basic' => 60,
        'pro'   => 300,
    ];

    private const DEFAULT_LIMIT = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $accountId = $request->input('wa_account_id');

        if (!$accountId) {
            return $next($request);
        }

        $waAccount = WaAccount::withoutGlobalScopes()
            ->where('id', $accountId)
            ->first();

        if (!$waAccount) {
            return $next($request);
        }

        $tenantId = $waAccount->tenant_id;
        $limit    = $this->getPlanLimit($tenantId);

        $minute = now()->format('Y-m-d-H-i');
        $key    = "tenant_throttle:{$tenantId}:{$minute}";

        $count = (int) Cache::get($key, 0) + 1;
        Cache::put($key, $count, 60);

        if ($count > $limit) {
            return response()->json(['error' => 'Upgrade your plan'], 429);
        }

        return $next($request);
    }

    private function getPlanLimit(string $tenantId): int
    {
        $subscription = TenantSubscription::where('tenant_id', $tenantId)
            ->with('plan')
            ->latest()
            ->first();

        if (!$subscription || !$subscription->plan) {
            return self::DEFAULT_LIMIT;
        }

        $planCode = strtolower($subscription->plan->code ?? '');

        return self::PLAN_LIMITS[$planCode] ?? self::DEFAULT_LIMIT;
    }
}
