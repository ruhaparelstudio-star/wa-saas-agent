<?php

namespace App\Modules\Shared\DTOs;

readonly class FollowUpCandidateDTO
{
    public function __construct(
        public string  $reason,
        public string  $tenant_id,
        public ?string $conversation_id,
        public ?string $booking_id,
        public ?string $invoice_id,
        public string  $to_phone,
        public string  $wa_account_id,
        public array   $context_data = [],
    ) {}

    public static function from(array $data): static
    {
        return new static(
            reason:          $data['reason'] ?? '',
            tenant_id:       $data['tenant_id'] ?? '',
            conversation_id: $data['conversation_id'] ?? null,
            booking_id:      $data['booking_id'] ?? null,
            invoice_id:      $data['invoice_id'] ?? null,
            to_phone:        $data['to_phone'] ?? '',
            wa_account_id:   $data['wa_account_id'] ?? '',
            context_data:    $data['context_data'] ?? [],
        );
    }
}
