<?php

namespace App\Modules\Shared\DTOs;

readonly class LlmResponseDTO
{
    public function __construct(
        public string $content,
        public string $model,
        public int $prompt_tokens,
        public int $completion_tokens,
        public int $total_tokens,
        public string $finish_reason,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            content: $data['content'] ?? '',
            model: $data['model'] ?? '',
            prompt_tokens: (int) ($data['prompt_tokens'] ?? 0),
            completion_tokens: (int) ($data['completion_tokens'] ?? 0),
            total_tokens: (int) ($data['total_tokens'] ?? 0),
            finish_reason: $data['finish_reason'] ?? 'stop',
        );
    }

    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'model' => $this->model,
            'prompt_tokens' => $this->prompt_tokens,
            'completion_tokens' => $this->completion_tokens,
            'total_tokens' => $this->total_tokens,
            'finish_reason' => $this->finish_reason,
        ];
    }
}
