<?php

namespace App\Modules\Shared\Enums;

enum BookingStatus: string
{
    case DRAFT       = 'draft';
    case CONFIRMED   = 'confirmed';
    case AWAITING_DP = 'awaiting_dp';
    case PAID        = 'paid';
    case COMPLETED   = 'completed';
    case CANCELLED   = 'cancelled';
    case EXPIRED     = 'expired';

    public function label(): string
    {
        return match($this) {
            self::DRAFT       => 'Draft',
            self::CONFIRMED   => 'Confirmed',
            self::AWAITING_DP => 'Awaiting DP',
            self::PAID        => 'Paid',
            self::COMPLETED   => 'Completed',
            self::CANCELLED   => 'Cancelled',
            self::EXPIRED     => 'Expired',
        };
    }
}
