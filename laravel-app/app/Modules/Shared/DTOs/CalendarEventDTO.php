<?php

namespace App\Modules\Shared\DTOs;

readonly class CalendarEventDTO
{
    public function __construct(
        public ?string $id,
        public string $tenant_id,
        public string $title,
        public string $description,
        public string $start_at,
        public string $end_at,
        public ?string $location,
        public array $attendees,
        public array $metadata,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            id:          $data['id'] ?? null,
            tenant_id:   $data['tenant_id'] ?? '',
            title:       $data['title'] ?? '',
            description: $data['description'] ?? '',
            start_at:    $data['start_at'] ?? '',
            end_at:      $data['end_at'] ?? '',
            location:    $data['location'] ?? null,
            attendees:   $data['attendees'] ?? [],
            metadata:    $data['metadata'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'tenant_id'   => $this->tenant_id,
            'title'       => $this->title,
            'description' => $this->description,
            'start_at'    => $this->start_at,
            'end_at'      => $this->end_at,
            'location'    => $this->location,
            'attendees'   => $this->attendees,
            'metadata'    => $this->metadata,
        ];
    }
}
