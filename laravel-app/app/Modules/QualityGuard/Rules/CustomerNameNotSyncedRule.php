<?php

namespace App\Modules\QualityGuard\Rules;

use App\Modules\Conversation\Models\Conversation;
use App\Modules\QualityGuard\Contracts\QualityRule;
use App\Modules\QualityGuard\DTOs\QualityViolationDTO;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Scopes\TenantScope;

class CustomerNameNotSyncedRule implements QualityRule
{
    public function code(): QualityIssueCode
    {
        return QualityIssueCode::CUSTOMER_NAME_NOT_SYNCED;
    }

    public function evaluate(
        TurnContextDTO $context,
        DecisionDTO $decision,
        ComposedReplyDTO $reply,
    ): ?QualityViolationDTO {
        $extractedName = $context->entities->entities['customer_name']
            ?? $context->state->entities['customer_name']
            ?? null;
        if (empty($extractedName)) {
            return null;
        }
        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->find($context->conversation->id);
        if (!$conversation) {
            return null;
        }
        if (!empty($conversation->customer_name)) {
            return null;
        }
        if (($context->state->message_count ?? 0) < 2) {
            return null;
        }

        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::LOW,
            message: 'Entity customer_name extracted but not synced into conversations.customer_name.',
            evidence: [
                'extracted_name' => $extractedName,
                'message_count'  => $context->state->message_count ?? 0,
            ],
        );
    }

    public function evaluateAgainstTrace(object $trace): ?QualityViolationDTO
    {
        $entities = $trace->extracted_entities ?? [];
        $name = $entities['customer_name'] ?? null;
        if (empty($name)) {
            return null;
        }
        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->find($trace->conversation_id);
        if (!$conversation || !empty($conversation->customer_name)) {
            return null;
        }
        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::LOW,
            message: 'Trace extracted customer_name but conversation.customer_name still NULL.',
            evidence: ['extracted_name' => $name],
        );
    }
}
