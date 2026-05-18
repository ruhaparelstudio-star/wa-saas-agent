<?php

namespace App\Modules\QualityGuard\Events;

use App\Modules\QualityGuard\Models\ConversationQualityIssue;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class QualityIssueDetected implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $conversationId,
        public readonly string $code,
        public readonly string $severity,
        public readonly string $message,
        public readonly ?string $issueId = null,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.quality")];
    }

    public function broadcastAs(): string
    {
        return 'quality.issue';
    }

    public function broadcastWith(): array
    {
        return [
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'code'            => $this->code,
            'severity'        => $this->severity,
            'message'         => $this->message,
            'issue_id'        => $this->issueId,
            'at'              => now()->toIso8601String(),
        ];
    }

    public static function fromIssue(ConversationQualityIssue $issue): self
    {
        return new self(
            tenantId: $issue->tenant_id,
            conversationId: $issue->conversation_id,
            code: $issue->code->value,
            severity: $issue->severity->value,
            message: $issue->message,
            issueId: $issue->id,
        );
    }
}
