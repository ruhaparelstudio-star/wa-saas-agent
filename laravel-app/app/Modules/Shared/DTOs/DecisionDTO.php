<?php

namespace App\Modules\Shared\DTOs;

use App\Modules\Shared\Enums\HandoffPriority;

readonly class DecisionDTO
{
    public function __construct(
        public string $decision,
        public array $desired_actions,
        public array $allowed_actions,
        public array $blocked_actions,
        public bool $handoff_required,
        public ?string $handoff_reason,
        public HandoffPriority $handoff_priority,
        public bool $notification_required,
        public string $reply_strategy,
        public string $active_goal,
        public ?string $stage_transition,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            decision: $data['decision'] ?? '',
            desired_actions: $data['desired_actions'] ?? [],
            allowed_actions: $data['allowed_actions'] ?? [],
            blocked_actions: array_map(
                fn($b) => $b instanceof BlockedActionDTO ? $b : BlockedActionDTO::from($b),
                $data['blocked_actions'] ?? []
            ),
            handoff_required: (bool) ($data['handoff_required'] ?? false),
            handoff_reason: $data['handoff_reason'] ?? null,
            handoff_priority: $data['handoff_priority'] instanceof HandoffPriority
                ? $data['handoff_priority']
                : HandoffPriority::from($data['handoff_priority'] ?? 'low'),
            notification_required: (bool) ($data['notification_required'] ?? false),
            reply_strategy: $data['reply_strategy'] ?? '',
            active_goal: $data['active_goal'] ?? '',
            stage_transition: $data['stage_transition'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'decision' => $this->decision,
            'desired_actions' => $this->desired_actions,
            'allowed_actions' => $this->allowed_actions,
            'blocked_actions' => array_map(fn($b) => $b->toArray(), $this->blocked_actions),
            'handoff_required' => $this->handoff_required,
            'handoff_reason' => $this->handoff_reason,
            'handoff_priority' => $this->handoff_priority->value,
            'notification_required' => $this->notification_required,
            'reply_strategy' => $this->reply_strategy,
            'active_goal' => $this->active_goal,
            'stage_transition' => $this->stage_transition,
        ];
    }
}
