<?php

namespace App\Modules\Conversation\Repositories;

use App\Modules\Conversation\Models\Conversation;
use App\Modules\Lead\Models\Lead;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Collection;

class ConversationRepository
{
    public function findOrCreateByPhone(string $tenantId, string $phone): Conversation
    {
        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('customer_phone', $phone)
            ->whereNotIn('stage', [ConversationStage::CLOSED->value])
            ->where('agent_mode', '!=', AgentMode::HANDOFF->value)
            ->first();

        if ($conversation) {
            return $conversation;
        }

        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->create([
                'tenant_id'     => $tenantId,
                'customer_phone' => $phone,
                'stage'         => ConversationStage::NEW_LEAD->value,
                'agent_mode'    => AgentMode::ACTIVE->value,
            ]);

        Lead::create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'customer_phone'  => $phone,
        ]);

        return $conversation;
    }

    public function findById(string $id): ?Conversation
    {
        return Conversation::withoutGlobalScope(TenantScope::class)->find($id);
    }

    public function updateState(string $id, array $updates): Conversation
    {
        $conversation = $this->findById($id);
        $conversation->update($updates);
        return $conversation->fresh();
    }

    public function getRecentForTenant(string $tenantId, int $limit = 20): Collection
    {
        return Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderBy('last_message_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
