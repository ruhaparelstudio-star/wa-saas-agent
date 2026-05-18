<?php

namespace App\Modules\QualityGuard\Rules;

use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use App\Modules\QualityGuard\Contracts\QualityRule;
use App\Modules\QualityGuard\DTOs\QualityViolationDTO;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Scopes\TenantScope;

class RedundantPricelistRule implements QualityRule
{
    public function code(): QualityIssueCode
    {
        return QualityIssueCode::REDUNDANT_PRICELIST;
    }

    public function evaluate(
        TurnContextDTO $context,
        DecisionDTO $decision,
        ComposedReplyDTO $reply,
    ): ?QualityViolationDTO {
        if (!in_array('send_pricelist', $decision->desired_actions, true)) {
            return null;
        }
        $alreadySent = DecisionTrace::withoutGlobalScope(TenantScope::class)
            ->where('conversation_id', $context->conversation->id)
            ->whereJsonContains('actions_dispatched', 'send_pricelist')
            ->exists();
        if (!$alreadySent) {
            return null;
        }
        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::LOW,
            message: 'send_pricelist was already dispatched earlier in this conversation.',
            evidence: ['conversation_id' => $context->conversation->id],
        );
    }

    public function evaluateAgainstTrace(object $trace): ?QualityViolationDTO
    {
        $actions = $trace->actions_dispatched ?? [];
        if (!in_array('send_pricelist', $actions, true)) {
            return null;
        }
        $earlier = DecisionTrace::withoutGlobalScope(TenantScope::class)
            ->where('conversation_id', $trace->conversation_id)
            ->where('id', '!=', $trace->id)
            ->where('created_at', '<', $trace->created_at)
            ->whereJsonContains('actions_dispatched', 'send_pricelist')
            ->exists();
        if (!$earlier) {
            return null;
        }
        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::LOW,
            message: 'Pricelist re-sent within same conversation.',
            evidence: ['conversation_id' => $trace->conversation_id],
        );
    }
}
