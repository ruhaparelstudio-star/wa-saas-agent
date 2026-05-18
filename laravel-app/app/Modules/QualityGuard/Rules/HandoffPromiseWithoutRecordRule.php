<?php

namespace App\Modules\QualityGuard\Rules;

use App\Modules\Handoff\Models\HandoffRecord;
use App\Modules\QualityGuard\Contracts\QualityRule;
use App\Modules\QualityGuard\DTOs\QualityViolationDTO;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Scopes\TenantScope;

class HandoffPromiseWithoutRecordRule implements QualityRule
{
    private const HANDOFF_PROMISE_PATTERN = '/(akan (?:di)?hubungi|akan follow ?up|akan kontak|sales akan (?:kontak|hubungi|follow)|teruskan ke (?:tim|sales)|tim sales (?:kami )?akan (?:follow ?up|hubungi|kontak)|saya teruskan)/i';

    public function code(): QualityIssueCode
    {
        return QualityIssueCode::HANDOFF_PROMISE_WITHOUT_RECORD;
    }

    public function evaluate(
        TurnContextDTO $context,
        DecisionDTO $decision,
        ComposedReplyDTO $reply,
    ): ?QualityViolationDTO {
        if (!preg_match(self::HANDOFF_PROMISE_PATTERN, $reply->reply_text)) {
            return null;
        }
        // Already a handoff path? Fine.
        if (in_array('flag_handoff', $decision->desired_actions, true)) {
            return null;
        }
        if ($decision->handoff_required) {
            return null;
        }
        if ($decision->decision === 'handoff') {
            return null;
        }
        // Paused-mode replies use HANDOFF_MESSAGE preset by design — they are
        // already gated outside the AI path; sales is engaging manually.
        if ($context->conversation->agent_mode->value === 'paused') {
            return null;
        }

        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::CRITICAL,
            message: 'Reply promises sales follow-up but no flag_handoff action / handoff_required is set.',
            evidence: [
                'reply'           => $reply->reply_text,
                'desired_actions' => $decision->desired_actions,
            ],
            suggested_action: 'Force flag_handoff into desired_actions; trigger HandoffService.',
        );
    }

    public function evaluateAgainstTrace(object $trace): ?QualityViolationDTO
    {
        $reply = (string) ($trace->final_reply ?? '');
        if (!preg_match(self::HANDOFF_PROMISE_PATTERN, $reply)) {
            return null;
        }
        $hasHandoffRecord = HandoffRecord::withoutGlobalScope(TenantScope::class)
            ->where('conversation_id', $trace->conversation_id)
            ->where('created_at', '<=', $trace->created_at)
            ->exists();
        if ($hasHandoffRecord || $trace->handoff_required) {
            return null;
        }

        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::CRITICAL,
            message: 'Trace reply promised handoff but no HandoffRecord exists for conversation.',
            evidence: ['reply' => $reply, 'conversation_id' => $trace->conversation_id],
        );
    }
}
