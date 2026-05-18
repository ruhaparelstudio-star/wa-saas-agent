<?php

namespace App\Modules\QualityGuard\Rules;

use App\Modules\QualityGuard\Contracts\QualityRule;
use App\Modules\QualityGuard\DTOs\QualityViolationDTO;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;

class PriceHallucinationRule implements QualityRule
{
    private const PRICE_PATTERN = '/Rp[\s.]?\d{4,}/i';

    public function code(): QualityIssueCode
    {
        return QualityIssueCode::PRICE_HALLUCINATION;
    }

    public function evaluate(
        TurnContextDTO $context,
        DecisionDTO $decision,
        ComposedReplyDTO $reply,
    ): ?QualityViolationDTO {
        $structured = $context->knowledge->structured_data ?? [];
        $hasPriceData = !empty($structured['packages']) || !empty($structured['prices']);

        if ($hasPriceData) {
            return null;
        }
        if (!preg_match(self::PRICE_PATTERN, $reply->reply_text)) {
            return null;
        }

        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::CRITICAL,
            message: 'Reply mentions Rp amount but no packages/prices in grounded data.',
            evidence: ['reply' => $reply->reply_text],
            suggested_action: 'Block reply and redirect to pricelist or handoff.',
        );
    }

    public function evaluateAgainstTrace(object $trace): ?QualityViolationDTO
    {
        $reply = (string) ($trace->final_reply ?? '');
        if (!preg_match(self::PRICE_PATTERN, $reply)) {
            return null;
        }
        $refs = $trace->grounding_refs ?? [];
        $hasPackage = false;
        foreach ($refs as $ref) {
            $source = is_array($ref) ? ($ref['source'] ?? '') : '';
            if ($source === 'packages' || $source === 'prices') {
                $hasPackage = true;
                break;
            }
        }
        if ($hasPackage) {
            return null;
        }

        return new QualityViolationDTO(
            code: $this->code(),
            severity: QualitySeverity::CRITICAL,
            message: 'Trace reply mentions Rp amount but no package/price grounding ref.',
            evidence: ['reply' => $reply],
        );
    }
}
