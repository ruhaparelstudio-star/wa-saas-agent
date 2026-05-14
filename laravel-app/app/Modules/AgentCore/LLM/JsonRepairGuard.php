<?php

namespace App\Modules\AgentCore\LLM;

use App\Modules\AgentCore\LLM\Exceptions\LlmJsonParseException;
use Illuminate\Support\Facades\Log;

class JsonRepairGuard
{
    public static function isValidJson(string $raw): bool
    {
        json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public static function repair(string $rawResponse): array
    {
        // Attempt 1: plain decode
        $decoded = json_decode($rawResponse, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Attempt 2: strip markdown code block (```json ... ``` or ``` ... ```)
        $stripped = preg_replace('/^```(?:json)?\s*/i', '', trim($rawResponse));
        $stripped = preg_replace('/\s*```$/', '', $stripped);
        $decoded  = json_decode($stripped, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            Log::warning('JsonRepairGuard: repaired by stripping markdown code block');
            return $decoded;
        }

        // Attempt 3: extract substring from first { to last }
        $start = strpos($rawResponse, '{');
        $end   = strrpos($rawResponse, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $substring = substr($rawResponse, $start, $end - $start + 1);
            $decoded   = json_decode($substring, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                Log::warning('JsonRepairGuard: repaired by substring extraction');
                return $decoded;
            }
        }

        throw new LlmJsonParseException('Cannot repair JSON: ' . substr($rawResponse, 0, 200));
    }
}
