<?php

namespace App\Modules\Shared\DTOs;

class WaAccountStatusDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenant_id,
        public readonly ?string $phone_number,
        public readonly ?string $display_name,
        public readonly string $status,
        public readonly ?string $connected_at,
        public readonly bool $is_qr_expired,
    ) {}

    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'tenant_id'    => $this->tenant_id,
            'phone_number' => $this->phone_number,
            'display_name' => $this->display_name,
            'status'       => $this->status,
            'connected_at' => $this->connected_at,
            'is_qr_expired' => $this->is_qr_expired,
        ];
    }
}
