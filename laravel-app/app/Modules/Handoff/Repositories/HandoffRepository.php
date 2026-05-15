<?php

namespace App\Modules\Handoff\Repositories;

use App\Modules\Handoff\Models\HandoffRecord;
use App\Modules\Shared\Enums\HandoffStatus;
use Illuminate\Database\Eloquent\Collection;

class HandoffRepository
{
    public function findById(string $id): ?HandoffRecord
    {
        return HandoffRecord::find($id);
    }

    public function findActiveByConversation(string $conversationId): ?HandoffRecord
    {
        return HandoffRecord::where('conversation_id', $conversationId)
            ->whereIn('status', [HandoffStatus::PENDING->value, HandoffStatus::IN_PROGRESS->value])
            ->latest()
            ->first();
    }

    public function getActiveByTenant(string $tenantId, int $limit = 50): Collection
    {
        return HandoffRecord::where('tenant_id', $tenantId)
            ->whereIn('status', [HandoffStatus::PENDING->value, HandoffStatus::IN_PROGRESS->value])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 ELSE 4 END")
            ->orderBy('created_at', 'asc')
            ->limit($limit)
            ->get();
    }

    public function countActiveByTenant(string $tenantId): int
    {
        return HandoffRecord::where('tenant_id', $tenantId)
            ->whereIn('status', [HandoffStatus::PENDING->value, HandoffStatus::IN_PROGRESS->value])
            ->count();
    }
}
