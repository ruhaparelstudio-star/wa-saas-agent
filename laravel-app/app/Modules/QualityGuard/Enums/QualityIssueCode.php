<?php

namespace App\Modules\QualityGuard\Enums;

enum QualityIssueCode: string
{
    case AVAILABILITY_HALLUCINATION = 'availability_hallucination';
    case PRICE_HALLUCINATION = 'price_hallucination';
    case STAGE_CLOSED_WITHOUT_PAYMENT = 'stage_closed_without_payment';
    case HANDOFF_PROMISE_WITHOUT_RECORD = 'handoff_promise_without_record';
    case MALFORMED_CUSTOMER_PHONE = 'malformed_customer_phone';
    case CUSTOMER_NAME_NOT_SYNCED = 'customer_name_not_synced';
    case REDUNDANT_PRICELIST = 'redundant_pricelist';
    case STAGE_STUCK = 'stage_stuck';
    case LLM_GRADER_LOW_SCORE = 'llm_grader_low_score';

    public function label(): string
    {
        return match ($this) {
            self::AVAILABILITY_HALLUCINATION => 'Availability hallucination',
            self::PRICE_HALLUCINATION => 'Price hallucination',
            self::STAGE_CLOSED_WITHOUT_PAYMENT => 'Stage closed without payment',
            self::HANDOFF_PROMISE_WITHOUT_RECORD => 'Handoff promised but no record',
            self::MALFORMED_CUSTOMER_PHONE => 'Malformed customer phone',
            self::CUSTOMER_NAME_NOT_SYNCED => 'Customer name not synced',
            self::REDUNDANT_PRICELIST => 'Redundant pricelist sent',
            self::STAGE_STUCK => 'Stage stuck',
            self::LLM_GRADER_LOW_SCORE => 'LLM grader low score',
        };
    }
}
