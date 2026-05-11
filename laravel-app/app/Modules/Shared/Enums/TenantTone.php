<?php

namespace App\Modules\Shared\Enums;

enum TenantTone: string
{
    case FORMAL = 'formal';
    case SEMI_FORMAL = 'semi_formal';
    case FRIENDLY = 'friendly';
    case CASUAL = 'casual';

    public function label(): string
    {
        return match($this) {
            self::FORMAL => 'Formal',
            self::SEMI_FORMAL => 'Semi Formal',
            self::FRIENDLY => 'Friendly',
            self::CASUAL => 'Casual',
        };
    }
}
