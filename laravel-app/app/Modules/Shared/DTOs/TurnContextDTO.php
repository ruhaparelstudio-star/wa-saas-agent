<?php

namespace App\Modules\Shared\DTOs;

readonly class TurnContextDTO
{
    public function __construct(
        public TenantDTO $tenant,
        public ConversationDTO $conversation,
        public ConversationStateDTO $state,
        public LeadProfileDTO $lead,
        public IntentResultDTO $intent,
        public EntityResultDTO $entities,
        public GroundedKnowledgeDTO $knowledge,
        public TenantConfigDTO $config,
        public InboundMessageDTO $inbound_message,
        public bool $is_sanitized,
        public bool $injection_detected,
        public array $recent_messages = [],
    ) {}

    public static function from(array $data): static
    {
        return new static(
            tenant: $data['tenant'] instanceof TenantDTO
                ? $data['tenant']
                : TenantDTO::from($data['tenant'] ?? []),
            conversation: $data['conversation'] instanceof ConversationDTO
                ? $data['conversation']
                : ConversationDTO::from($data['conversation'] ?? []),
            state: $data['state'] instanceof ConversationStateDTO
                ? $data['state']
                : ConversationStateDTO::from($data['state'] ?? []),
            lead: $data['lead'] instanceof LeadProfileDTO
                ? $data['lead']
                : LeadProfileDTO::from($data['lead'] ?? []),
            intent: $data['intent'] instanceof IntentResultDTO
                ? $data['intent']
                : IntentResultDTO::from($data['intent'] ?? []),
            entities: $data['entities'] instanceof EntityResultDTO
                ? $data['entities']
                : EntityResultDTO::from($data['entities'] ?? []),
            knowledge: $data['knowledge'] instanceof GroundedKnowledgeDTO
                ? $data['knowledge']
                : GroundedKnowledgeDTO::from($data['knowledge'] ?? []),
            config: $data['config'] instanceof TenantConfigDTO
                ? $data['config']
                : TenantConfigDTO::from($data['config'] ?? []),
            inbound_message: $data['inbound_message'] instanceof InboundMessageDTO
                ? $data['inbound_message']
                : InboundMessageDTO::from($data['inbound_message'] ?? []),
            is_sanitized: (bool) ($data['is_sanitized'] ?? false),
            injection_detected: (bool) ($data['injection_detected'] ?? false),
            recent_messages: (array) ($data['recent_messages'] ?? []),
        );
    }

    public function toArray(): array
    {
        return [
            'tenant' => $this->tenant->toArray(),
            'conversation' => $this->conversation->toArray(),
            'state' => $this->state->toArray(),
            'lead' => $this->lead->toArray(),
            'intent' => $this->intent->toArray(),
            'entities' => $this->entities->toArray(),
            'knowledge' => $this->knowledge->toArray(),
            'config' => $this->config->toArray(),
            'inbound_message' => $this->inbound_message->toArray(),
            'is_sanitized' => $this->is_sanitized,
            'injection_detected' => $this->injection_detected,
            'recent_messages' => $this->recent_messages,
        ];
    }
}
