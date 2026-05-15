<?php

namespace App\Modules\Handoff\Services;

use App\Modules\Conversation\Models\Conversation;
use App\Modules\Handoff\Models\HandoffRecord;
use App\Modules\Handoff\Repositories\HandoffRepository;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\Enums\AgentMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class HandoffService
{
    public function __construct(
        private readonly HandoffRepository $repository,
    ) {}

    public function triggerHandoff(Conversation $conversation, DecisionDTO $decision): HandoffRecord
    {
        $record = HandoffRecord::create([
            'tenant_id'       => $conversation->tenant_id,
            'conversation_id' => $conversation->id,
            'wa_account_id'   => $conversation->wa_account_id,
            'status'          => 'pending',
            'priority'        => $decision->handoff_priority->value,
            'reason'          => $decision->handoff_reason,
            'trigger_intent'  => $decision->active_goal ?: null,
        ]);

        $conversation->update(['agent_mode' => AgentMode::HANDOFF->value]);

        Log::info('HandoffService: handoff triggered.', [
            'handoff_record_id' => $record->id,
            'conversation_id'   => $conversation->id,
            'priority'          => $decision->handoff_priority->value,
        ]);

        return $record;
    }

    public function resolveHandoff(HandoffRecord $record, string $resolutionNotes, bool $resumeAI = false): void
    {
        $record->resolve($resolutionNotes);

        $conversation = $record->conversation;
        if ($conversation !== null) {
            $newMode = $resumeAI ? AgentMode::ACTIVE->value : AgentMode::LIMITED->value;
            $conversation->update(['agent_mode' => $newMode]);

            Log::info('HandoffService: handoff resolved.', [
                'handoff_record_id' => $record->id,
                'resume_ai'         => $resumeAI,
                'new_mode'          => $newMode,
            ]);
        }
    }

    public function getActiveHandoffs(string $tenantId): Collection
    {
        return $this->repository->getActiveByTenant($tenantId);
    }

    public function assignHandoff(HandoffRecord $record, string $adminUserId): void
    {
        $record->assign($adminUserId);

        Log::info('HandoffService: handoff assigned.', [
            'handoff_record_id' => $record->id,
            'assigned_to'       => $adminUserId,
        ]);
    }
}
