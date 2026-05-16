<?php

namespace App\Modules\Shared\Enums;

enum InvoiceType: string
{
    case DP        = 'dp';
    case PELUNASAN = 'pelunasan';

    public function label(): string
    {
        return match($this) {
            self::DP        => 'Uang Muka (DP)',
            self::PELUNASAN => 'Pelunasan',
        };
    }
}
