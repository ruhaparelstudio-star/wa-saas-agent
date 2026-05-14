<?php

namespace App\Modules\AgentCore\Security\Services;

use App\Modules\Shared\DTOs\SanitizedInputDTO;
use App\Modules\Shared\Enums\NotificationType;
use Illuminate\Support\Facades\Log;

class InputSanitizerService
{
    private const MAX_LENGTH = 2000;

    private const INJECTION_PATTERNS = [
        'ignore previous instructions',
        'you are now',
        'disregard',
        'forget everything',
        'new instructions:',
        'system prompt',
        'act as',
        'pretend you are',
        '[INST]',
        '<<SYS>>',
        'jangan ikuti instruksi sebelumnya',
        'abaikan instruksi',
        'lupakan semua',
        'instruksi baru:',
        'kamu sekarang',
    ];

    public function sanitize(string $input, string $tenantId, string $conversationId): SanitizedInputDTO
    {
        $originalLength = mb_strlen($input);
        $wasTruncated = false;

        if ($originalLength > self::MAX_LENGTH) {
            $input = mb_substr($input, 0, self::MAX_LENGTH);
            $wasTruncated = true;
        }

        $input = str_replace(chr(0), '', $input);
        $input = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $input);

        $injectionDetected = false;
        $patternsFound = [];

        foreach (self::INJECTION_PATTERNS as $pattern) {
            if (stripos($input, $pattern) !== false) {
                $injectionDetected = true;
                $patternsFound[] = $pattern;
                $input = str_ireplace($pattern, '', $input);

                Log::warning('Injection attempt detected', [
                    'pattern'         => $pattern,
                    'conversation_id' => $conversationId,
                    'tenant_id'       => $tenantId,
                    'notification'    => NotificationType::INJECTION_ATTEMPT_DETECTED->value,
                ]);
            }
        }

        $input = trim($input);

        return SanitizedInputDTO::from([
            'sanitized_text'    => $input,
            'original_length'   => $originalLength,
            'was_truncated'     => $wasTruncated,
            'injection_detected' => $injectionDetected,
            'patterns_found'    => $patternsFound,
        ]);
    }

    public function maskPhone(string $phone): string
    {
        // +628121234567 → +6281****567 (show +62 + 2 digits, mask middle, show last 3)
        if (preg_match('/^(\+62\d{2})(\d+)(\d{3})$/', $phone, $m)) {
            return $m[1] . str_repeat('*', mb_strlen($m[2])) . $m[3];
        }

        // 628121234567 → 6281****567 (show 62 + 2 digits, mask middle, show last 3)
        if (preg_match('/^(62\d{2})(\d+)(\d{3})$/', $phone, $m)) {
            return $m[1] . str_repeat('*', mb_strlen($m[2])) . $m[3];
        }

        // 08121234567 → 0812****567
        if (preg_match('/^(0\d{3})(\d+)(\d{3})$/', $phone, $m)) {
            return $m[1] . str_repeat('*', mb_strlen($m[2])) . $m[3];
        }

        return $phone;
    }
}
