<?php

namespace App\Modules\Plans\Http\Middleware;

use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Enums\FeatureKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeatureEnabled
{
    public function __construct(
        private FeatureGateService $featureGateService
    ) {}

    public function handle(Request $request, Closure $next, string $featureKey): Response
    {
        $user = $request->user();

        if ($user === null || $user->tenant_id === null) {
            return response()->json([
                'message' => 'Unauthorized.',
            ], 403);
        }

        $feature = FeatureKey::from($featureKey);

        if (!$this->featureGateService->check($user->tenant_id, $feature)) {
            return response()->json([
                'message' => "Fitur '{$feature->label()}' tidak tersedia di plan Anda.",
                'feature' => $featureKey,
            ], 403);
        }

        return $next($request);
    }
}
