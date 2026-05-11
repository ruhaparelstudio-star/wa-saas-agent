<?php

namespace App\Modules\Shared\DTOs;

readonly class GroundingRefDTO
{
    public function __construct(
        public string $type,
        public string $source,
        public string $id,
        public string $key_data,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            type: $data['type'] ?? '',
            source: $data['source'] ?? '',
            id: $data['id'] ?? '',
            key_data: $data['key_data'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'source' => $this->source,
            'id' => $this->id,
            'key_data' => $this->key_data,
        ];
    }
}
