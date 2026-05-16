<?php

namespace App\Modules\Shared\DTOs;

readonly class RevenueMetricDTO
{
    public function __construct(
        public string $period_label,
        public int    $total_revenue,
        public int    $dp_revenue,
        public int    $pelunasan_revenue,
        public int    $invoice_count,
        public int    $paid_count,
    ) {}
}
