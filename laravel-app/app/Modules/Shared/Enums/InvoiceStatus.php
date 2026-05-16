<?php

namespace App\Modules\Shared\Enums;

enum InvoiceStatus: string
{
    case ISSUED    = 'issued';
    case SENT      = 'sent';
    case PAID      = 'paid';
    case OVERDUE   = 'overdue';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match($this) {
            self::ISSUED    => 'Issued',
            self::SENT      => 'Sent',
            self::PAID      => 'Paid',
            self::OVERDUE   => 'Overdue',
            self::CANCELLED => 'Cancelled',
        };
    }
}
