<?php

namespace App\Modules\Shared\DTOs;

use Carbon\Carbon;

readonly class AnalyticsPeriodDTO
{
    public function __construct(
        public Carbon $start_date,
        public Carbon $end_date,
        public string $label,
    ) {}
}
