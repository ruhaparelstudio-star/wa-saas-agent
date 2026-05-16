<?php

namespace App\Modules\Shared\DTOs;

readonly class TenantAnalyticsSummaryDTO
{
    public function __construct(
        public AnalyticsPeriodDTO    $period,
        public array                 $lead_funnel,
        public RevenueMetricDTO      $revenue,
        public ConversionMetricDTO   $conversion,
        public ResponseTimeMetricDTO $response_time,
        public array                 $top_packages,
        public bool                  $is_advanced,
    ) {}
}
