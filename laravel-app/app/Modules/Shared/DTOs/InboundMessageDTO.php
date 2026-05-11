<?php

namespace App\Modules\Shared\DTOs;

readonly class InboundMessageDTO
{
    public function __construct(
        public string $wa_account_id,
        public string $provider_message_id,
        public string $from_phone,
        public string $message_type,
        public string $body,
        public ?string $media_url,
        public array $raw_payload,
        public string $received_at,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            wa_account_id: $data['wa_account_id'] ?? '',
            provider_message_id: $data['provider_message_id'] ?? '',
            from_phone: $data['from_phone'] ?? '',
            message_type: $data['message_type'] ?? 'text',
            body: $data['body'] ?? '',
            media_url: $data['media_url'] ?? null,
            raw_payload: $data['raw_payload'] ?? [],
            received_at: $data['received_at'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'wa_account_id' => $this->wa_account_id,
            'provider_message_id' => $this->provider_message_id,
            'from_phone' => $this->from_phone,
            'message_type' => $this->message_type,
            'body' => $this->body,
            'media_url' => $this->media_url,
            'raw_payload' => $this->raw_payload,
            'received_at' => $this->received_at,
        ];
    }
}
