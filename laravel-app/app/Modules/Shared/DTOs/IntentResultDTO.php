<?php

namespace App\Modules\Shared\DTOs;

readonly class IntentResultDTO
{
    public function __construct(
        public string $intent,
        public float $confidence,
        public string $reason,
        public string $raw_response,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            intent: $data['intent'] ?? '',
            confidence: (float) ($data['confidence'] ?? 0.0),
            reason: $data['reason'] ?? '',
            raw_response: $data['raw_response'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'intent' => $this->intent,
            'confidence' => $this->confidence,
            'reason' => $this->reason,
            'raw_response' => $this->raw_response,
        ];
    }
}
