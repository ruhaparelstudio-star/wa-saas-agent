<?php

namespace App\Modules\Audit\Services;

use App\Modules\Shared\Helpers\PhoneNumberMasker;
use Illuminate\Support\Facades\Log;

class AuditService
{
    public function logInjectionAttempt(string $tenantId, string $fromPhone, string $maskedMessage): void
    {
        Log::warning('Security: injection attempt detected', [
            'tenant_id' => $tenantId,
            'from_phone' => PhoneNumberMasker::mask($fromPhone),
            'message_excerpt' => mb_substr($maskedMessage, 0, 100),
        ]);
    }

    public function logSuspiciousActivity(string $type, array $context): void
    {
        Log::warning("Security: suspicious activity [{$type}]", $context);
    }
}
