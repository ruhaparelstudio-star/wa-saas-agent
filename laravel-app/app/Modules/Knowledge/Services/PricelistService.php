<?php

namespace App\Modules\Knowledge\Services;

use App\Modules\Knowledge\Models\Asset;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\AssetType;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\TenantConfig\Services\TenantPolicyService;

class PricelistService
{
    public const MODE_PDF      = 'pdf';
    public const MODE_TEXT     = 'text';
    public const MODE_HYBRID   = 'hybrid';
    public const MODE_DISABLED = 'disabled';

    private const STAGE_RANK = [
        'new_lead'              => 0,
        'exploration'           => 1,
        'qualification'         => 2,
        'recommendation'        => 3,
        'consideration'         => 4,
        'booking'               => 5,
        'waiting_booking'       => 5,
        'invoice_phase'         => 6,
        'post_invoice_limited'  => 6,
        'closed'                => 7,
        'handoff'               => 0,
        'paused_admin'          => 0,
    ];

    public function __construct(
        private readonly TenantPolicyService $policyService,
        private readonly PackageResolver $packageResolver,
    ) {}

    /**
     * @return array{allowed: bool, reason: ?string, fallback: ?string}
     */
    public function canSendPricelist(TurnContextDTO $context): array
    {
        $mode = $this->getMode($context->tenant->id);

        if ($mode === self::MODE_DISABLED) {
            return [
                'allowed'  => false,
                'reason'   => 'Pricelist sharing is disabled by tenant policy',
                'fallback' => 'manual_quote_request',
            ];
        }

        $requirement = $this->policyService->getPolicy(
            $context->tenant->id,
            PolicyKey::PRICELIST_MIN_REQUIREMENT
        );

        if ($requirement === 'after_qualification') {
            $stageValue = $context->state->stage->value;
            $rank       = self::STAGE_RANK[$stageValue] ?? 0;
            $minRank    = self::STAGE_RANK[ConversationStage::QUALIFICATION->value];

            if ($rank < $minRank) {
                return [
                    'allowed'  => false,
                    'reason'   => 'Pricelist requires conversation to reach qualification stage first',
                    'fallback' => 'ask_qualifying_questions',
                ];
            }
        }

        if ($requirement === 'after_event_date') {
            $entities      = $context->entities->entities ?? [];
            $stateEntities = $context->state->entities ?? [];
            $eventDate     = $entities['event_date'] ?? $stateEntities['event_date'] ?? null;

            if (empty($eventDate)) {
                return [
                    'allowed'  => false,
                    'reason'   => 'Pricelist requires customer to share event date first',
                    'fallback' => 'ask_event_date',
                ];
            }
        }

        return ['allowed' => true, 'reason' => null, 'fallback' => null];
    }

    public function getPricelistAsset(string $tenantId): ?Asset
    {
        return Asset::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->active()
            ->byType(AssetType::PRICELIST)
            ->latest('created_at')
            ->first();
    }

    /**
     * @param  array<int,string>  $filterCategory
     */
    public function buildTextPricelist(string $tenantId, array $filterCategory = []): string
    {
        $packages = $this->packageResolver->getActivePackages($tenantId);

        if (!empty($filterCategory)) {
            $packages = $packages->filter(
                fn (Package $p) => in_array($p->category, $filterCategory, true)
            )->values();
        }

        if ($packages->isEmpty()) {
            return 'Mohon maaf Kak, daftar paket belum tersedia. Tim kami akan menghubungi Kakak segera 🙏';
        }

        $lines = ['📋 Paket Kami:', ''];
        $index = 1;

        foreach ($packages as $pkg) {
            $price     = $pkg->activePrices->sortBy('price_idr')->first();
            $priceText = $price !== null
                ? 'Rp ' . number_format($price->price_idr, 0, ',', '.')
                : 'hubungi kami untuk info harga';

            $lines[] = sprintf('%d. %s — %s', $index, $pkg->name, $priceText);

            if (!empty($pkg->description)) {
                $lines[] = '   • ' . trim($pkg->description);
            }

            $index++;
        }

        $lines[] = '';
        $lines[] = 'Boleh tau Kak, paket mana yang Kakak tertarik? 😊';

        return implode("\n", $lines);
    }

    public function getMode(string $tenantId): string
    {
        $value = $this->policyService->getPolicy($tenantId, PolicyKey::PRICELIST_MODE);

        return match ($value) {
            self::MODE_PDF, self::MODE_HYBRID, self::MODE_DISABLED => $value,
            self::MODE_TEXT                                        => self::MODE_TEXT,
            default                                                => self::MODE_TEXT,
        };
    }
}
