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
use App\Modules\Shared\Services\PhoneNormalizer;

class MalformedCustomerPhoneRule implements QualityRule
{
    public function code(): QualityIssueCode
    {
        return QualityIssueCode::MALFORMED_CUSTOMER_PHONE;
    }

    public function evaluate(
        TurnContextDTO $context,
        DecisionDTO $decision,
        ComposedReplyDTO $reply,
    ): ?QualityViolationDTO {
        $phone = $context->conversation->from_phone ?? null;
        if (!PhoneNormalizer::isMalformed($phone)) {
            return null;
        }

        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::HIGH,
            message: 'conversations.customer_phone is in malformed JID format.',
            evidence: ['customer_phone' => $phone],
            suggested_action: 'Re-run PhoneNormalizer backfill migration.',
        );
    }

    public function evaluateAgainstTrace(object $trace): ?QualityViolationDTO
    {
        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->find($trace->conversation_id);
        if (!$conversation || !PhoneNormalizer::isMalformed($conversation->customer_phone)) {
            return null;
        }
        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::HIGH,
            message: 'Conversation customer_phone is malformed.',
            evidence: ['customer_phone' => $conversation->customer_phone],
        );
    }
}
