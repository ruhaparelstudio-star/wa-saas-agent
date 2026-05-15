<?php

namespace App\Modules\AgentCore\Pipeline\Services;

use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\AgentMode;
use Illuminate\Support\Facades\Log;

class ActionDispatcher
{
    public function __construct(
        private readonly ?ChannelGatewayInterface $gateway,
        private readonly ConversationRepository $conversations,
    ) {}

    /**
     * Dispatch reply and execute non-blocked actions from the decision.
     *
     * @return array<string> Names of actions successfully dispatched.
     */
    public function dispatch(
        TurnContextDTO $context,
        ComposedReplyDTO $reply,
        DecisionDTO $decision,
    ): array {
        $dispatched = [];

        if ($reply->reply_text !== '') {
            $sent = $this->sendReply($context, $reply);
            if ($sent) {
                $dispatched[] = 'send_reply';
            }

            $this->conversations->findById($context->conversation->id)?->addMessage([
                'direction'    => 'outbound',
                'message_type' => $reply->reply_type === 'text' ? 'text' : $reply->reply_type,
                'body'         => $reply->reply_text,
            ]);
        }

        $blockedNames = array_map(
            fn($b) => is_array($b) ? ($b['action'] ?? '') : $b->action,
            $decision->blocked_actions,
        );

        foreach ($decision->desired_actions as $action) {
            if (in_array($action, $blockedNames, true)) {
                continue;
            }

            match ($action) {
                'update_stage'           => $this->updateConversationStage(
                    $context,
                    $decision->stage_transition ?? $context->conversation->stage->value,
                ),
                'update_lead'            => $this->updateLead($context),
                'flag_handoff'           => $this->flagHandoff($context),
                'increment_message_count' => null, // handled by addMessage above
                default                  => null,
            };

            if (!in_array($action, ['update_stage', 'update_lead', 'flag_handoff', 'increment_message_count'], true)) {
                $dispatched[] = $action;
            } else {
                $dispatched[] = $action;
            }
        }

        if ($decision->stage_transition !== null) {
            $this->updateConversationStage($context, $decision->stage_transition);
            if (!in_array('update_stage', $dispatched, true)) {
                $dispatched[] = 'update_stage';
            }
        }

        $this->conversations->findById($context->conversation->id)
            ?->update(['last_message_at' => now()]);

        return array_values(array_unique($dispatched));
    }

    public function sendReply(TurnContextDTO $context, ComposedReplyDTO $reply): bool
    {
        $payload = [
            'wa_account_id' => $context->conversation->wa_account_id,
            'to_phone'      => $context->conversation->from_phone,
            'message_type'  => 'text',
            'body'          => $reply->reply_text,
        ];

        if ($this->gateway === null) {
            Log::info('ActionDispatcher: gateway not configured, skipping send.', $payload);
            return false;
        }

        try {
            return $this->gateway->sendText(
                $payload['wa_account_id'],
                $payload['to_phone'],
                $payload['body'],
            );
        } catch (\Throwable $e) {
            Log::warning('ActionDispatcher: gateway send failed.', [
                'error'         => $e->getMessage(),
                'wa_account_id' => $payload['wa_account_id'],
            ]);
            return false;
        }
    }

    public function updateConversationStage(TurnContextDTO $context, string $newStage): void
    {
        $conversation = $this->conversations->findById($context->conversation->id);
        if ($conversation === null) {
            return;
        }

        $old = $conversation->stage->value;
        $conversation->update(['stage' => $newStage]);

        Log::info('ActionDispatcher: stage transition.', [
            'conversation_id' => $context->conversation->id,
            'from'            => $old,
            'to'              => $newStage,
        ]);
    }

    private function updateLead(TurnContextDTO $context): void
    {
        $conversation = $this->conversations->findById($context->conversation->id);
        $lead = $conversation?->lead;
        if ($lead === null) {
            return;
        }

        $entities = $context->entities->entities ?? [];
        if (!empty($entities)) {
            $lead->updateFromEntities($entities);
        }
    }

    private function flagHandoff(TurnContextDTO $context): void
    {
        $conversation = $this->conversations->findById($context->conversation->id);
        if ($conversation === null) {
            return;
        }

        $conversation->update(['agent_mode' => AgentMode::HANDOFF->value]);

        Log::info('ActionDispatcher: conversation flagged for handoff.', [
            'conversation_id' => $context->conversation->id,
        ]);
    }
}
