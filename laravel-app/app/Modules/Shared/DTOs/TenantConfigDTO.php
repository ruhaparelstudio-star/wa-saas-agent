<?php

namespace App\Modules\Shared\DTOs;

use App\Modules\Shared\Enums\TenantTone;

readonly class TenantConfigDTO
{
    public function __construct(
        public string $tenant_id,
        public TenantTone $tone,
        public string $timezone,
        public string $business_hours_start,
        public string $business_hours_end,
        public array $policies,
        public array $features,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            tenant_id: $data['tenant_id'] ?? '',
            tone: ($data['tone'] ?? null) instanceof TenantTone
                ? $data['tone']
                : TenantTone::from($data['tone'] ?? 'semi_formal'),
            timezone: $data['timezone'] ?? 'Asia/Jakarta',
            business_hours_start: $data['business_hours_start'] ?? '08:00',
            business_hours_end: $data['business_hours_end'] ?? '21:00',
            policies: $data['policies'] ?? [],
            features: $data['features'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenant_id,
            'tone' => $this->tone->value,
            'timezone' => $this->timezone,
            'business_hours_start' => $this->business_hours_start,
            'business_hours_end' => $this->business_hours_end,
            'policies' => $this->policies,
            'features' => $this->features,
        ];
    }
}
