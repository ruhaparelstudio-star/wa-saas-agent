<?php

namespace App\Modules\Shared\Enums;

enum FollowUpReason: string
{
    case STALE_LEAD         = 'stale_lead';
    case BOOKING_PENDING_DP = 'booking_pending_dp';
    case INVOICE_OVERDUE    = 'invoice_overdue';
    case EVENT_REMINDER_H7  = 'event_reminder_h7';

    public function label(): string
    {
        return match($this) {
            self::STALE_LEAD         => 'Lead Tidak Aktif',
            self::BOOKING_PENDING_DP => 'Menunggu DP',
            self::INVOICE_OVERDUE    => 'Invoice Overdue',
            self::EVENT_REMINDER_H7  => 'Reminder H-7 Event',
        };
    }
}
