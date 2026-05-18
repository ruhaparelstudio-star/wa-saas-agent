<?php

namespace App\Modules\Conversation\Repositories;

use App\Modules\Conversation\Models\Conversation;
use App\Modules\Lead\Models\Lead;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class ConversationRepository
{
    public function findOrCreateByPhone(string $tenantId, string $phone, ?string $waAccountId = null): Conversation
    {
        // Guard against non-UUID strings (e.g. test stubs like 'acc-acc-001')
        // wa_account_id is a uuid column — passing a non-UUID crashes the insert.
        $safeWaAccountId = ($waAccountId !== null && Str::isUuid($waAccountId))
            ? $waAccountId
            : null;

        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('customer_phone', $phone)
            ->whereNotIn('stage', [ConversationStage::CLOSED->value])
            ->where('agent_mode', '!=', AgentMode::HANDOFF->value)
            ->first();

        if ($conversation) {
            // Keep wa_account_id in sync when the same phone contacts from a new WA session
            if ($safeWaAccountId !== null && $conversation->wa_account_id !== $safeWaAccountId) {
                $conversation->update(['wa_account_id' => $safeWaAccountId]);
            }
            return $conversation;
        }

        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->create([
                'tenant_id'      => $tenantId,
                'customer_phone' => $phone,
                'wa_account_id'  => $safeWaAccountId,
                'stage'          => ConversationStage::NEW_LEAD->value,
                'agent_mode'     => AgentMode::ACTIVE->value,
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
