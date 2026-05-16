<?php

namespace App\Modules\Shared\Services;

use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Adapters\EmailGatewayAdapter;
use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\WhatsApp\Adapters\WhatsAppGatewayAdapter;

class ChannelRegistry
{
    public function __construct(
        private readonly FeatureGateService $featureGateService,
    ) {}

    public function getAdapter(string $channel, string $tenantId): ChannelGatewayInterface
    {
        if (!$this->featureGateService->check($tenantId, FeatureKey::MULTI_CHANNEL)) {
            return app(WhatsAppGatewayAdapter::class);
        }

        return match($channel) {
            'email' => app(EmailGatewayAdapter::class),
            default => app(WhatsAppGatewayAdapter::class),
        };
    }
}
