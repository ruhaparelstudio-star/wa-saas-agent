<?php

namespace App\Logging;

use App\Modules\Shared\Helpers\PhoneNumberMasker;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

class StripPiiProcessor implements ProcessorInterface
{
    private const SENSITIVE_KEYS = [
        'key', 'secret', 'token', 'password', 'api_key', 'apikey',
        'authorization', 'auth', 'credential', 'private_key',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = PhoneNumberMasker::maskInText($record->message);
        $context = $this->redactContext($record->context);

        return $record->with(message: $message, context: $context);
    }

    private function redactContext(array $context): array
    {
        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if ($this->isSensitiveKey($lowerKey)) {
                $context[$key] = '[REDACTED]';
                continue;
            }

            if (is_string($value)) {
                $context[$key] = PhoneNumberMasker::maskInText($value);
            } elseif (is_array($value)) {
                $context[$key] = $this->redactContext($value);
            }
        }

        return $context;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
