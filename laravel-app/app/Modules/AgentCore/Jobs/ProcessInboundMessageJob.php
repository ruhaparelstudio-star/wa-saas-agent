<?php

namespace App\Modules\AgentCore\Jobs;

use App\Modules\AgentCore\Pipeline\Services\TurnPipelineService;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\Enums\NotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int   $timeout = 60;
    public int   $tries   = 3;
    public array $backoff = [5, 30, 60];

    public InboundMessageDTO $message;
    public string $tenantId;

    public function __construct(InboundMessageDTO $message, string $tenantId)
    {
        $this->message  = $message;
        $this->tenantId = $tenantId;
        $this->onQueue('inbound');
    }

    public function handle(TurnPipelineService $pipeline): void
    {
        $pipeline->process($this->message, $this->tenantId);
    }

    public function failed(Throwable $e): void
    {
        Log::error('ProcessInboundMessageJob: all retries exhausted', [
            'notification_type'   => NotificationType::HANDOFF_REQUIRED->value,
            'provider_message_id' => $this->message->provider_message_id,
            'from_phone'          => $this->message->from_phone,
            'tenant_id'           => $this->tenantId,
            'error'               => $e->getMessage(),
        ]);
    }
}
