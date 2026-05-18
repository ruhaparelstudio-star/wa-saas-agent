<?php

namespace App\Modules\Shared\Enums;

enum NotificationType: string
{
    case HANDOFF_REQUIRED = 'handoff_required';
    case MESSAGE_WHILE_PAUSED = 'message_while_paused';
    case WA_DISCONNECTED = 'wa_disconnected';
    case CALENDAR_ERROR = 'calendar_error';
    case INVOICE_ACTION = 'invoice_action';
    case BOOKING_ACTION = 'booking_action';
    case INJECTION_ATTEMPT_DETECTED = 'injection_attempt_detected';
    case QUALITY_ISSUE_DETECTED = 'quality_issue_detected';

    public function label(): string
    {
        return match($this) {
            self::HANDOFF_REQUIRED => 'Handoff Required',
            self::MESSAGE_WHILE_PAUSED => 'Message While Paused',
            self::WA_DISCONNECTED => 'WA Disconnected',
            self::CALENDAR_ERROR => 'Calendar Error',
            self::INVOICE_ACTION => 'Invoice Action',
            self::BOOKING_ACTION => 'Booking Action',
            self::INJECTION_ATTEMPT_DETECTED => 'Injection Attempt Detected',
            self::QUALITY_ISSUE_DETECTED => 'Quality Issue Detected',
        };
    }
}
