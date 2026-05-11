<?php

namespace App\Modules\Shared\DTOs;

use App\Modules\Shared\Enums\TenantStatus;

readonly class TenantDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public TenantStatus $status,
        public string $industry,
        public string $contact_email,
        public ?string $contact_phone,
        public string $created_at,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            id: $data['id'] ?? '',
            name: $data['name'] ?? '',
            slug: $data['slug'] ?? '',
            status: ($data['status'] ?? null) instanceof TenantStatus
                ? $data['status']
                : TenantStatus::from($data['status'] ?? 'trial'),
            industry: $data['industry'] ?? 'wedding',
            contact_email: $data['contact_email'] ?? '',
            contact_phone: $data['contact_phone'] ?? null,
            created_at: $data['created_at'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'industry' => $this->industry,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'created_at' => $this->created_at,
        ];
    }
}
