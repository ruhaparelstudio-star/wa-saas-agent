<?php

namespace App\Modules\Shared\DTOs;

use App\Modules\Shared\Enums\LeadTemperature;

readonly class LeadProfileDTO
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $phone,
        public ?string $name,
        public LeadTemperature $temperature,
        public array $entities,
        public int $conversation_count,
        public ?string $last_seen_at,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            id: $data['id'] ?? '',
            tenant_id: $data['tenant_id'] ?? '',
            phone: $data['phone'] ?? '',
            name: $data['name'] ?? null,
            temperature: ($data['temperature'] ?? null) instanceof LeadTemperature
                ? $data['temperature']
                : LeadTemperature::from($data['temperature'] ?? 'cold'),
            entities: $data['entities'] ?? [],
            conversation_count: (int) ($data['conversation_count'] ?? 0),
            last_seen_at: $data['last_seen_at'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'phone' => $this->phone,
            'name' => $this->name,
            'temperature' => $this->temperature->value,
            'entities' => $this->entities,
            'conversation_count' => $this->conversation_count,
            'last_seen_at' => $this->last_seen_at,
        ];
    }
}
