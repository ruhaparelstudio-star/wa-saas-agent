<?php

namespace App\Modules\Shared\Enums;

enum PolicyKey: string
{
    case PRICELIST_MODE = 'pricelist_mode';
    case PRICELIST_MIN_REQUIREMENT = 'pricelist_min_requirement';
    case LEAD_LIMIT_FALLBACK = 'lead_limit_fallback';
    case AFTER_HOURS_BEHAVIOR = 'after_hours_behavior';
    case INVOICE_MAX_RESEND = 'invoice_max_resend';
    case CONCURRENT_BOOKING_LOCK = 'concurrent_booking_lock';
    case CLASSIFIER_CONTEXT_WINDOW = 'classifier_context_window';
    case COMPOSER_CONTEXT_WINDOW = 'composer_context_window';
    case CONTEXT_SUMMARY_THRESHOLD = 'context_summary_threshold';
    case CONTEXT_SUMMARY_KEEP_RECENT = 'context_summary_keep_recent';

    public function label(): string
    {
        return match($this) {
            self::PRICELIST_MODE => 'Pricelist Mode',
            self::PRICELIST_MIN_REQUIREMENT => 'Pricelist Min Requirement',
            self::LEAD_LIMIT_FALLBACK => 'Lead Limit Fallback',
            self::AFTER_HOURS_BEHAVIOR => 'After Hours Behavior',
            self::INVOICE_MAX_RESEND => 'Invoice Max Resend',
            self::CONCURRENT_BOOKING_LOCK => 'Concurrent Booking Lock',
            self::CLASSIFIER_CONTEXT_WINDOW => 'Classifier/Extractor Context Window',
            self::COMPOSER_CONTEXT_WINDOW => 'Composer Context Window',
            self::CONTEXT_SUMMARY_THRESHOLD => 'Context Summary Threshold',
            self::CONTEXT_SUMMARY_KEEP_RECENT => 'Context Summary Keep Recent',
        };
    }
}
