<?php

namespace App\Modules\Shared\Enums;

enum LeadTemperature: string
{
    case COLD = 'cold';
    case WARM = 'warm';
    case HOT = 'hot';

    public function label(): string
    {
        return match($this) {
            self::COLD => 'Cold',
            self::WARM => 'Warm',
            self::HOT => 'Hot',
        };
    }
}
