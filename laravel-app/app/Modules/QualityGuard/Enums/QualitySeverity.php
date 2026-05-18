<?php

namespace App\Modules\QualityGuard\Enums;

enum QualitySeverity: string
{
    case CRITICAL = 'critical';
    case HIGH = 'high';
    case LOW = 'low';

    public function label(): string
    {
        return match ($this) {
            self::CRITICAL => 'Critical',
            self::HIGH => 'High',
            self::LOW => 'Low',
        };
    }
}
