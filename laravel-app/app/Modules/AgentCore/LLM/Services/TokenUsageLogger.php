<?php

namespace App\Modules\AgentCore\LLM\Services;

use App\Modules\Shared\DTOs\LlmResponseDTO;
use Illuminate\Support\Facades\Redis;

class TokenUsageLogger
{
    private const TTL_SECONDS = 90 * 24 * 3600; // 90 days

    public function log(string $tenantId, string $purpose, LlmResponseDTO $response): void
    {
        $monthKey    = date('Y-m');
        $baseKey     = "token_usage:{$tenantId}:{$monthKey}";
        $purposeKey  = "{$baseKey}:purpose:{$purpose}";

        Redis::pipeline(function ($pipe) use ($baseKey, $purposeKey, $response) {
            // Aggregate totals
            $pipe->hincrby($baseKey, 'prompt_tokens', $response->prompt_tokens);
            $pipe->hincrby($baseKey, 'completion_tokens', $response->completion_tokens);
            $pipe->hincrby($baseKey, 'total_tokens', $response->total_tokens);
            $pipe->expire($baseKey, self::TTL_SECONDS);

            // Per-purpose breakdown
            $pipe->hincrby($purposeKey, 'prompt_tokens', $response->prompt_tokens);
            $pipe->hincrby($purposeKey, 'completion_tokens', $response->completion_tokens);
            $pipe->hincrby($purposeKey, 'total_tokens', $response->total_tokens);
            $pipe->expire($purposeKey, self::TTL_SECONDS);
        });
    }

    public function getMonthlyUsage(string $tenantId, string $yearMonth): array
    {
        $baseKey = "token_usage:{$tenantId}:{$yearMonth}";
        $totals  = Redis::hgetall($baseKey);

        $purposes = [];
        $pattern  = "{$baseKey}:purpose:*";
        $keys     = Redis::keys($pattern);

        foreach ($keys as $key) {
            $purposeName          = substr($key, strlen("{$baseKey}:purpose:"));
            $data                 = Redis::hgetall($key);
            $purposes[$purposeName] = [
                'prompt_tokens'     => (int) ($data['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($data['completion_tokens'] ?? 0),
                'total_tokens'      => (int) ($data['total_tokens'] ?? 0),
            ];
        }

        return [
            'prompt_tokens'     => (int) ($totals['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($totals['completion_tokens'] ?? 0),
            'total_tokens'      => (int) ($totals['total_tokens'] ?? 0),
            'by_purpose'        => $purposes,
        ];
    }
}
