<?php

namespace App\Modules\Shared\DTOs;

readonly class ConversionMetricDTO
{
    public function __construct(
        public int   $total_leads,
        public int   $leads_to_booking,
        public float $conversion_rate,
        public float $avg_days_to_booking,
    ) {}
}
