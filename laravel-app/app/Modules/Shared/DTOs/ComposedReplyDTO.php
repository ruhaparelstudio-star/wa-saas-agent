<?php

namespace App\Modules\Shared\DTOs;

readonly class ComposedReplyDTO
{
    public function __construct(
        public string $reply_text,
        public string $reply_type,
        public array $attachments,
        public array $grounding_refs,
        public bool $detected_hallucination,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            reply_text: $data['reply_text'] ?? '',
            reply_type: $data['reply_type'] ?? 'text',
            attachments: $data['attachments'] ?? [],
            grounding_refs: array_map(
                fn($r) => $r instanceof GroundingRefDTO ? $r : GroundingRefDTO::from($r),
                $data['grounding_refs'] ?? []
            ),
            detected_hallucination: (bool) ($data['detected_hallucination'] ?? false),
        );
    }

    public function toArray(): array
    {
        return [
            'reply_text' => $this->reply_text,
            'reply_type' => $this->reply_type,
            'attachments' => $this->attachments,
            'grounding_refs' => array_map(fn($r) => $r->toArray(), $this->grounding_refs),
            'detected_hallucination' => $this->detected_hallucination,
        ];
    }
}
