<?php

namespace App\Modules\Shared\DTOs;

readonly class LeadFunnelDTO
{
    public function __construct(
        public string $stage,
        public int    $count,
        public float  $percentage,
    ) {}
}
