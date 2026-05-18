<?php

namespace App\Modules\Handoff\Events;

use App\Modules\Handoff\Models\HandoffRecord;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class HandoffRequiredBroadcasted implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $conversationId,
        public readonly string $handoffRecordId,
        public readonly string $priority,
        public readonly ?string $reason,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.handoff")];
    }

    public function broadcastAs(): string
    {
        return 'handoff.required';
    }

    public function broadcastWith(): array
    {
        return [
            'tenant_id'         => $this->tenantId,
            'conversation_id'   => $this->conversationId,
            'handoff_record_id' => $this->handoffRecordId,
            'priority'          => $this->priority,
            'reason'            => $this->reason,
            'at'                => now()->toIso8601String(),
        ];
    }

    public static function fromRecord(HandoffRecord $r): self
    {
        return new self(
            tenantId: $r->tenant_id,
            conversationId: $r->conversation_id,
            handoffRecordId: $r->id,
            priority: $r->priority->value,
            reason: $r->reason,
        );
    }
}
