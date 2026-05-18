<?php

namespace App\Modules\QualityGuard\Rules;

use App\Modules\QualityGuard\Contracts\QualityRule;
use App\Modules\QualityGuard\DTOs\QualityViolationDTO;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;

class AvailabilityHallucinationRule implements QualityRule
{
    private const AVAILABILITY_PATTERN = '/\b(tersedia|available|kosong|bisa di(?:reserve|book|booking)|free|open)\b/i';
    private const DATE_CONTEXT_PATTERN = '/\b(tanggal|tgl|hari|date|\d{1,2}[\/\-\s][a-zA-Z\d]{2,9}[\/\-\s]?\d{0,4})\b/i';

    public function code(): QualityIssueCode
    {
        return QualityIssueCode::AVAILABILITY_HALLUCINATION;
    }

    private function isDateAvailabilityClaim(string $text): bool
    {
        return preg_match(self::AVAILABILITY_PATTERN, $text)
            && preg_match(self::DATE_CONTEXT_PATTERN, $text);
    }

    public function evaluate(
        TurnContextDTO $context,
        DecisionDTO $decision,
        ComposedReplyDTO $reply,
    ): ?QualityViolationDTO {
        $structured = $context->knowledge->structured_data ?? [];
        $hasAvailabilityData = !empty($structured['availability']);

        if (!$this->isDateAvailabilityClaim($reply->reply_text)) {
            return null;
        }
        if ($hasAvailabilityData) {
            return null;
        }

        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::CRITICAL,
            message: 'Reply claims a date is tersedia but knowledge data has no availability lookup.',
            evidence: [
                'reply' => $reply->reply_text,
                'has_availability_data' => false,
                'event_date' => $context->entities->entities['event_date'] ?? null,
            ],
            suggested_action: 'Replace with: "boleh saya cek ketersediaannya dulu ya Kak" or flag handoff.',
        );
    }

    public function evaluateAgainstTrace(object $trace): ?QualityViolationDTO
    {
        $reply = (string) ($trace->final_reply ?? '');
        if (!$this->isDateAvailabilityClaim($reply)) {
            return null;
        }

        $refs = $trace->grounding_refs ?? [];
        $hasAvailabilityRef = false;
        foreach ($refs as $ref) {
            $source = is_array($ref) ? ($ref['source'] ?? '') : '';
            $id     = is_array($ref) ? ($ref['id'] ?? '') : '';
            if ($source === 'bookings' && str_starts_with((string) $id, 'avail:')) {
                $hasAvailabilityRef = true;
                break;
            }
        }
        if ($hasAvailabilityRef) {
            return null;
        }

        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::CRITICAL,
            message: 'Trace reply claims date availability but no availability grounding_ref present.',
            evidence: ['reply' => $reply],
        );
    }
}
