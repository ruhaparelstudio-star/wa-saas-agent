<?php

namespace App\Modules\Shared\Services;

use InvalidArgumentException;

class PhoneNormalizer
{
    public static function normalize(string $raw): string
    {
        $s = trim($raw);

        $s = preg_replace('/^(lid|wid|s\.whatsapp\.net):/i', '', $s) ?? $s;
        $s = preg_replace('/@(lid|s\.whatsapp\.net|c\.us|g\.us|broadcast)$/i', '', $s) ?? $s;

        $digits = preg_replace('/\D/', '', $s);

        if ($digits === null || $digits === '') {
            throw new InvalidArgumentException('Phone has no digits: ' . $raw);
        }

        return '+' . $digits;
    }

    public static function isMalformed(?string $stored): bool
    {
        if ($stored === null || $stored === '') {
            return true;
        }

        if (preg_match('/^(lid|wid|s\.whatsapp\.net):/i', $stored)) {
            return true;
        }

        if (preg_match('/@(lid|s\.whatsapp\.net|c\.us|g\.us|broadcast)$/i', $stored)) {
            return true;
        }

        return !preg_match('/^\+?\d{8,15}$/', $stored);
    }
}
