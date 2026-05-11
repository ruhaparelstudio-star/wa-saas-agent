<?php

namespace App\Modules\Shared\DTOs;

readonly class TurnResultDTO
{
    public function __construct(
        public bool $reply_sent,
        public array $actions_dispatched,
        public string $decision_trace_id,
        public string $conversation_id,
        public ConversationStateDTO $new_state,
        public int $processing_time_ms,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            reply_sent: (bool) ($data['reply_sent'] ?? false),
            actions_dispatched: $data['actions_dispatched'] ?? [],
            decision_trace_id: $data['decision_trace_id'] ?? '',
            conversation_id: $data['conversation_id'] ?? '',
            new_state: $data['new_state'] instanceof ConversationStateDTO
                ? $data['new_state']
                : ConversationStateDTO::from($data['new_state'] ?? []),
            processing_time_ms: (int) ($data['processing_time_ms'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'reply_sent' => $this->reply_sent,
            'actions_dispatched' => $this->actions_dispatched,
            'decision_trace_id' => $this->decision_trace_id,
            'conversation_id' => $this->conversation_id,
            'new_state' => $this->new_state->toArray(),
            'processing_time_ms' => $this->processing_time_ms,
        ];
    }
}
