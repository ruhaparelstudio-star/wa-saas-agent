<?php

namespace App\Modules\Shared\Enums;

enum AssetType: string
{
    case PRICELIST = 'pricelist';
    case BROCHURE = 'brochure';
    case PORTFOLIO = 'portfolio';
    case OTHER = 'other';

    public function label(): string
    {
        return match($this) {
            self::PRICELIST => 'Pricelist',
            self::BROCHURE => 'Brochure',
            self::PORTFOLIO => 'Portfolio',
            self::OTHER => 'Other',
        };
    }
}
