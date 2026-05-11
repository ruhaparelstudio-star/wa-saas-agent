<?php

namespace App\Modules\Shared\Enums;

enum MemoryMode: string
{
    case ACTIVE = 'active';
    case DORMANT = 'dormant';

    public function label(): string
    {
        return match($this) {
            self::ACTIVE => 'Active',
            self::DORMANT => 'Dormant',
        };
    }
}
