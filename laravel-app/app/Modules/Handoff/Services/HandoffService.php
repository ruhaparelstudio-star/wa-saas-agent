<?php

namespace App\Modules\Handoff\Services;

use App\Modules\Conversation\Models\Conversation;
use App\Modules\Handoff\Events\HandoffRequiredBroadcasted;
use App\Modules\Handoff\Models\HandoffRecord;
use App\Modules\Handoff\Repositories\HandoffRepository;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\Enums\AgentMode;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class HandoffService
{
    public function __construct(
        private readonly HandoffRepository $repository,
        private readonly NotificationService $notificationService,
    ) {}

    public function triggerHandoff(Conversation $conversation, DecisionDTO $decision): HandoffRecord
    {
        // Idempotency — if there is already an active (pending / in_progress)
        // handoff for this conversation, reuse it instead of creating a duplicate.
        // The decision engine can legitimately re-emit a handoff trigger on every
        // turn while the customer is still in BOOKING stage — we should not spam
        // sales with duplicate alerts.
        $existing = $this->repository->findActiveByConversation($conversation->id);
        if ($existing !== null) {
            Log::info('HandoffService: handoff already active, reusing.', [
                'handoff_record_id' => $existing->id,
                'conversation_id'   => $conversation->id,
            ]);
            return $existing;
        }

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

        $this->notificationService->notifyHandoffRequired($conversation, $record);

        try {
            event(HandoffRequiredBroadcasted::fromRecord($record));
        } catch (\Throwable $e) {
            Log::debug('HandoffRequiredBroadcasted dispatch failed', ['error' => $e->getMessage()]);
        }

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
