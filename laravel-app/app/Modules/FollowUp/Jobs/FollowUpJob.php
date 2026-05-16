<?php

namespace App\Modules\FollowUp\Jobs;

use App\Modules\FollowUp\Services\FollowUpService;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Enums\FeatureKey;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FollowUpJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $tenantId) {}

    public function handle(FollowUpService $service, FeatureGateService $featureGateService): void
    {
        if (!$featureGateService->check($this->tenantId, FeatureKey::FOLLOW_UP_AUTOMATION)) {
            Log::info('FollowUpJob: feature disabled, skipping.', ['tenant_id' => $this->tenantId]);
            return;
        }

        $candidates = $service->findCandidates($this->tenantId);

        foreach ($candidates as $candidate) {
            $service->sendFollowUp($candidate);
        }

        Log::info('FollowUpJob: done.', [
            'tenant_id'  => $this->tenantId,
            'candidates' => count($candidates),
        ]);
    }

    public function queue(): string
    {
        return 'follow_ups';
    }
}
