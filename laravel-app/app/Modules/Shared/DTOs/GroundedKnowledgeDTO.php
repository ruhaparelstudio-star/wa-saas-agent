<?php

namespace App\Modules\Shared\DTOs;

readonly class GroundedKnowledgeDTO
{
    public function __construct(
        public array $structured_data,
        public array $vector_results,
        public array $grounding_refs,
        public string $search_method,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            structured_data: $data['structured_data'] ?? [],
            vector_results: $data['vector_results'] ?? [],
            grounding_refs: array_map(
                fn($r) => $r instanceof GroundingRefDTO ? $r : GroundingRefDTO::from($r),
                $data['grounding_refs'] ?? []
            ),
            search_method: $data['search_method'] ?? 'tsvector',
        );
    }

    public function toArray(): array
    {
        return [
            'structured_data' => $this->structured_data,
            'vector_results' => $this->vector_results,
            'grounding_refs' => array_map(fn($r) => $r->toArray(), $this->grounding_refs),
            'search_method' => $this->search_method,
        ];
    }
}
