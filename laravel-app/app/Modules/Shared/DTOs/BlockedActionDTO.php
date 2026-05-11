<?php

namespace App\Modules\Shared\DTOs;

readonly class BlockedActionDTO
{
    public function __construct(
        public string $action,
        public string $reason,
        public bool $can_fallback,
        public ?string $fallback_action,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            action: $data['action'] ?? '',
            reason: $data['reason'] ?? '',
            can_fallback: (bool) ($data['can_fallback'] ?? false),
            fallback_action: $data['fallback_action'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reason' => $this->reason,
            'can_fallback' => $this->can_fallback,
            'fallback_action' => $this->fallback_action,
        ];
    }
}
