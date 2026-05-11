<?php

namespace App\Modules\Shared\DTOs;

use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\LeadTemperature;

readonly class ConversationStateDTO
{
    public function __construct(
        public ConversationStage $stage,
        public AgentMode $agent_mode,
        public MemoryMode $memory_mode,
        public LeadTemperature $lead_temperature,
        public array $entities,
        public int $turn_count,
        public ?string $last_intent,
    ) {}

    public static function from(array $data): static
    {
        return new static(
            stage: ($data['stage'] ?? null) instanceof ConversationStage
                ? $data['stage']
                : ConversationStage::from($data['stage'] ?? 'new_lead'),
            agent_mode: ($data['agent_mode'] ?? null) instanceof AgentMode
                ? $data['agent_mode']
                : AgentMode::from($data['agent_mode'] ?? 'active'),
            memory_mode: ($data['memory_mode'] ?? null) instanceof MemoryMode
                ? $data['memory_mode']
                : MemoryMode::from($data['memory_mode'] ?? 'active'),
            lead_temperature: ($data['lead_temperature'] ?? null) instanceof LeadTemperature
                ? $data['lead_temperature']
                : LeadTemperature::from($data['lead_temperature'] ?? 'cold'),
            entities: $data['entities'] ?? [],
            turn_count: (int) ($data['turn_count'] ?? 0),
            last_intent: $data['last_intent'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'stage' => $this->stage->value,
            'agent_mode' => $this->agent_mode->value,
            'memory_mode' => $this->memory_mode->value,
            'lead_temperature' => $this->lead_temperature->value,
            'entities' => $this->entities,
            'turn_count' => $this->turn_count,
            'last_intent' => $this->last_intent,
        ];
    }
}
