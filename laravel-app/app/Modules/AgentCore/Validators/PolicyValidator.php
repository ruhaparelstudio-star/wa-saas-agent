<?php

namespace App\Modules\AgentCore\Validators;

use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\FeatureKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PolicyValidator
{
    public function __construct(
        private readonly FeatureGateService $featureGateService,
    ) {}

    /**
     * Returns array of warning strings (non-blocking issues).
     * Mutates $decision->blocked_actions and $decision->allowed_actions via returned array.
     */
    public function validate(TurnContextDTO $context, DecisionDTO $decision): array
    {
        $warnings         = [];
        $blocked          = [];
        $tenantId         = $context->tenant->id;
        $policies         = $context->config->policies;
        $desiredActions   = $decision->desired_actions;

        // ── 1. PRICELIST_MODE ─────────────────────────────────────────────
        $pricelistMode = $policies['pricelist_mode'] ?? 'public';

        if ($pricelistMode === 'on_request' && in_array('send_price_info', $desiredActions, true)) {
            $blocked[] = [
                'action'          => 'send_price_info',
                'reason'          => 'Pricelist mode is on_request — price not sent automatically',
                'can_fallback'    => true,
                'fallback_action' => 'ask_customer_to_request_price',
            ];
            $warnings[] = 'send_price_info blocked: pricelist_mode=on_request';
        }

        // ── 2. LEAD_LIMIT ─────────────────────────────────────────────────
        if (!$this->featureGateService->isLeadLimitUnlimited($tenantId)) {
            $limit    = $this->featureGateService->getLeadLimit($tenantId);
            $fallback = $policies['lead_limit_fallback'] ?? 'queue';

            // Only hit DB when limit is actually set and meaningful
            if ($limit >= 0) {
                $leadCount = $this->currentMonthLeadCount($tenantId);
            } else {
                $leadCount = 0;
            }

            if ($leadCount >= $limit) {
                if ($fallback === 'reject') {
                    $blocked[] = [
                        'action'          => '*',
                        'reason'          => "Monthly lead limit reached ({$limit})",
                        'can_fallback'    => false,
                        'fallback_action' => null,
                    ];
                    $warnings[] = "Lead limit reached ({$leadCount}/{$limit}), fallback=reject: conversation blocked";
                } else {
                    $warnings[] = "Lead limit reached ({$leadCount}/{$limit}), fallback=queue: flagged but allowed";
                }
            }
        }

        // ── 3. CONCURRENT_BOOKING_LOCK ────────────────────────────────────
        $bookingLock = $policies['concurrent_booking_lock'] ?? 'false';

        if (
            filter_var($bookingLock, FILTER_VALIDATE_BOOLEAN)
            && in_array('initiate_booking', $desiredActions, true)
            && $this->hasPendingBooking($tenantId)
        ) {
            $blocked[] = [
                'action'          => 'initiate_booking',
                'reason'          => 'A booking is already pending for this tenant',
                'can_fallback'    => true,
                'fallback_action' => 'inform_booking_pending',
            ];
            $warnings[] = 'initiate_booking blocked: concurrent_booking_lock active';
        }

        if (!empty($blocked)) {
            Log::info('PolicyValidator blocked actions', [
                'tenant_id' => $tenantId,
                'blocked'   => array_column($blocked, 'action'),
            ]);
        }

        return [$blocked, $warnings];
    }

    private function currentMonthLeadCount(string $tenantId): int
    {
        // Count conversations (leads) created this billing period.
        // Simple approximation: current calendar month.
        return (int) DB::table('conversations')
            ->where('tenant_id', $tenantId)
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();
    }

    private function hasPendingBooking(string $tenantId): bool
    {
        // Check if any booking is in BOOKING or WAITING_BOOKING stage for this tenant.
        return DB::table('conversations')
            ->where('tenant_id', $tenantId)
            ->whereIn('stage', ['booking', 'waiting_booking'])
            ->exists();
    }
}
