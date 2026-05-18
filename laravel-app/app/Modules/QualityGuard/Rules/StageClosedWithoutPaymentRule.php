<?php

namespace App\Modules\QualityGuard\Rules;

use App\Modules\Booking\Models\Booking;
use App\Modules\QualityGuard\Contracts\QualityRule;
use App\Modules\QualityGuard\DTOs\QualityViolationDTO;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Scopes\TenantScope;

class StageClosedWithoutPaymentRule implements QualityRule
{
    public function code(): QualityIssueCode
    {
        return QualityIssueCode::STAGE_CLOSED_WITHOUT_PAYMENT;
    }

    public function evaluate(
        TurnContextDTO $context,
        DecisionDTO $decision,
        ComposedReplyDTO $reply,
    ): ?QualityViolationDTO {
        $target = $decision->stage_transition;
        if ($target !== ConversationStage::CLOSED->value) {
            return null;
        }
        if ($this->hasPaidBooking($context->conversation->id)) {
            return null;
        }

        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::CRITICAL,
            message: 'Stage transition to CLOSED without a paid booking. CLOSED is reserved for fully-paid leads.',
            evidence: [
                'conversation_id' => $context->conversation->id,
                'stage_before'    => $context->state->stage->value,
                'stage_target'    => $target,
            ],
            suggested_action: 'Revert stage to WAITING_BOOKING or HANDOFF.',
        );
    }

    public function evaluateAgainstTrace(object $trace): ?QualityViolationDTO
    {
        if (($trace->stage_after ?? null) !== ConversationStage::CLOSED->value) {
            return null;
        }
        if ($this->hasPaidBooking($trace->conversation_id)) {
            return null;
        }

        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::CRITICAL,
            message: 'Trace closed conversation without paid booking.',
            evidence: ['conversation_id' => $trace->conversation_id],
        );
    }

    private function hasPaidBooking(string $conversationId): bool
    {
        return Booking::withoutGlobalScope(TenantScope::class)
            ->where('conversation_id', $conversationId)
            ->where('status', 'paid')
            ->exists();
    }
}
