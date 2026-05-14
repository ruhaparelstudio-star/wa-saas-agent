<?php

namespace App\Modules\Shared\DTOs;

readonly class SanitizedInputDTO
{
    public function __construct(
        public string $sanitized_text,
        public int $original_length,
        public bool $was_truncated,
        public bool $injection_detected,
        public array $patterns_found,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            sanitized_text: $data['sanitized_text'] ?? '',
            original_length: (int) ($data['original_length'] ?? 0),
            was_truncated: (bool) ($data['was_truncated'] ?? false),
            injection_detected: (bool) ($data['injection_detected'] ?? false),
            patterns_found: $data['patterns_found'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'sanitized_text'    => $this->sanitized_text,
            'original_length'   => $this->original_length,
            'was_truncated'     => $this->was_truncated,
            'injection_detected' => $this->injection_detected,
            'patterns_found'    => $this->patterns_found,
        ];
    }
}
