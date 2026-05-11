<?php

namespace App\Modules\Shared\DTOs;

readonly class AvailabilityResultDTO
{
    public function __construct(
        public bool $is_available,
        public string $date,
        public string $tenant_id,
        public int $existing_bookings_count,
        public ?string $next_available_date,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            is_available: (bool) ($data['is_available'] ?? false),
            date: $data['date'] ?? '',
            tenant_id: $data['tenant_id'] ?? '',
            existing_bookings_count: (int) ($data['existing_bookings_count'] ?? 0),
            next_available_date: $data['next_available_date'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'is_available' => $this->is_available,
            'date' => $this->date,
            'tenant_id' => $this->tenant_id,
            'existing_bookings_count' => $this->existing_bookings_count,
            'next_available_date' => $this->next_available_date,
        ];
    }
}
