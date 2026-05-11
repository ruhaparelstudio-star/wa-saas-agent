<?php

namespace App\Modules\Shared\DTOs;

use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\MemoryMode;

readonly class ConversationDTO
{
    public function __construct(
        public string $id,
        public string $tenant_id,
        public string $wa_account_id,
        public string $from_phone,
        public ConversationStage $stage,
        public AgentMode $agent_mode,
        public MemoryMode $memory_mode,
        public ?string $context_summary,
        public string $created_at,
        public string $updated_at,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            id: $data['id'] ?? '',
            tenant_id: $data['tenant_id'] ?? '',
            wa_account_id: $data['wa_account_id'] ?? '',
            from_phone: $data['from_phone'] ?? '',
            stage: ($data['stage'] ?? null) instanceof ConversationStage
                ? $data['stage']
                : ConversationStage::from($data['stage'] ?? 'new_lead'),
            agent_mode: ($data['agent_mode'] ?? null) instanceof AgentMode
                ? $data['agent_mode']
                : AgentMode::from($data['agent_mode'] ?? 'active'),
            memory_mode: ($data['memory_mode'] ?? null) instanceof MemoryMode
                ? $data['memory_mode']
                : MemoryMode::from($data['memory_mode'] ?? 'active'),
            context_summary: $data['context_summary'] ?? null,
            created_at: $data['created_at'] ?? '',
            updated_at: $data['updated_at'] ?? '',
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'wa_account_id' => $this->wa_account_id,
            'from_phone' => $this->from_phone,
            'stage' => $this->stage->value,
            'agent_mode' => $this->agent_mode->value,
            'memory_mode' => $this->memory_mode->value,
            'context_summary' => $this->context_summary,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
