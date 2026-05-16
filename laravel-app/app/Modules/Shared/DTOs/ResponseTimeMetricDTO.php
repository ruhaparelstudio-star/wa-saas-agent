<?php

namespace App\Modules\Shared\DTOs;

readonly class ResponseTimeMetricDTO
{
    public function __construct(
        public ?float $avg_first_response_minutes,
        public ?float $avg_handling_minutes,
        public int    $sample_count,
    ) {}
}
