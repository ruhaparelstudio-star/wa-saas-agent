<?php

namespace App\Modules\Shared\DTOs;

readonly class LlmEmbeddingDTO
{
    public function __construct(
        public array $embedding,
        public string $model,
        public int $total_tokens,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            embedding: $data['embedding'] ?? [],
            model: $data['model'] ?? '',
            total_tokens: (int) ($data['total_tokens'] ?? 0),
        );
    }

    public function toArray(): array
    {
        return [
            'embedding' => $this->embedding,
            'model' => $this->model,
            'total_tokens' => $this->total_tokens,
        ];
    }
}
