<?php

namespace App\Modules\Shared\DTOs;

readonly class EntityResultDTO
{
    public function __construct(
        public array $entities,
        public array $corrections,
        public array $previous_references,
        public float $confidence,
        public array $needs_clarification,
        public string $detected_language,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            entities: $data['entities'] ?? [],
            corrections: $data['corrections'] ?? [],
            previous_references: $data['previous_references'] ?? [],
            confidence: (float) ($data['confidence'] ?? 0.0),
            needs_clarification: $data['needs_clarification'] ?? [],
            detected_language: $data['detected_language'] ?? 'id',
        );
    }

    public function toArray(): array
    {
        return [
            'entities' => $this->entities,
            'corrections' => $this->corrections,
            'previous_references' => $this->previous_references,
            'confidence' => $this->confidence,
            'needs_clarification' => $this->needs_clarification,
            'detected_language' => $this->detected_language,
        ];
    }
}
