<?php

namespace App\Modules\Shared\Enums;

enum ConversationStage: string
{
    case NEW_LEAD = 'new_lead';
    case EXPLORATION = 'exploration';
    case QUALIFICATION = 'qualification';
    case RECOMMENDATION = 'recommendation';
    case CONSIDERATION = 'consideration';
    case BOOKING = 'booking';
    case WAITING_BOOKING = 'waiting_booking';
    case CLOSED = 'closed';
    case INVOICE_PHASE = 'invoice_phase';
    case POST_INVOICE_LIMITED = 'post_invoice_limited';
    case HANDOFF = 'handoff';
    case PAUSED_ADMIN = 'paused_admin';

    public function label(): string
    {
        return match($this) {
            self::NEW_LEAD => 'New Lead',
            self::EXPLORATION => 'Exploration',
            self::QUALIFICATION => 'Qualification',
            self::RECOMMENDATION => 'Recommendation',
            self::CONSIDERATION => 'Consideration',
            self::BOOKING => 'Booking',
            self::WAITING_BOOKING => 'Waiting Booking',
            self::CLOSED => 'Closed',
            self::INVOICE_PHASE => 'Invoice Phase',
            self::POST_INVOICE_LIMITED => 'Post Invoice Limited',
            self::HANDOFF => 'Handoff',
            self::PAUSED_ADMIN => 'Paused (Admin)',
        };
    }
}
