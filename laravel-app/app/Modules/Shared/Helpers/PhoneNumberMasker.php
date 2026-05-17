<?php

namespace App\Modules\Shared\Helpers;

class PhoneNumberMasker
{
    public static function mask(string $phone): string
    {
        if (str_starts_with($phone, '+62')) {
            return '+62***' . substr($phone, -3);
        }

        return '***' . substr($phone, -3);
    }

    public static function maskInText(string $text): string
    {
        // +628xxx... (international Indonesian format)
        $text = preg_replace_callback(
            '/\+62\d{6,13}/',
            fn($m) => '+62***' . substr($m[0], -3),
            $text,
        );

        // 08xxx... (local format)
        $text = preg_replace_callback(
            '/\b08\d{7,11}\b/',
            fn($m) => '***' . substr($m[0], -3),
            $text,
        );

        // 628xxx... (without leading +)
        $text = preg_replace_callback(
            '/\b628\d{6,12}\b/',
            fn($m) => '***' . substr($m[0], -3),
            $text,
        );

        return $text;
    }
}
