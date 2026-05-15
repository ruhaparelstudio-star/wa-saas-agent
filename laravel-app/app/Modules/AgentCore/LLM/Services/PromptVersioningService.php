<?php

namespace App\Modules\AgentCore\LLM\Services;

use App\Modules\AgentCore\LLM\Models\PromptTemplate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PromptVersioningService
{
    private const CACHE_TTL_SECONDS = 600; // 10 minutes

    private array $hardcodedFallbacks = [];

    public function registerFallback(string $name, string $template): void
    {
        $this->hardcodedFallbacks[$name] = $template;
    }

    public function getActiveTemplate(string $name): string
    {
        $cacheKey = "prompt_template:{$name}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($name) {
            $row = PromptTemplate::where('name', $name)
                ->where('is_active', true)
                ->first();

            if ($row) {
                return $row->template;
            }

            if (isset($this->hardcodedFallbacks[$name])) {
                return $this->hardcodedFallbacks[$name];
            }

            return '';
        });
    }

    public function recordAccuracy(string $name, string $version, float $score, int $testCount): void
    {
        $row = PromptTemplate::where('name', $name)->where('is_active', true)->first();
        if (! $row) {
            return;
        }

        $history = $row->accuracy_history ?? [];

        $previousScore = collect($history)->last()['score'] ?? null;

        $history[] = [
            'version'    => $version,
            'score'      => $score,
            'date'       => now()->toIso8601String(),
            'test_count' => $testCount,
        ];

        $row->accuracy_history = $history;
        $row->save();

        if ($previousScore !== null && $score < ($previousScore - 0.05)) {
            Log::warning("Prompt accuracy regression detected for {$name}", [
                'previous_score' => $previousScore,
                'new_score'      => $score,
                'version'        => $version,
            ]);
        }
    }

    public function rollback(string $name): void
    {
        $row = PromptTemplate::where('name', $name)->where('is_active', true)->first();
        if (! $row) {
            return;
        }

        $history = $row->accuracy_history ?? [];

        Log::info("Prompt rollback triggered for {$name}", [
            'current_version' => $row->version,
            'history_count'   => count($history),
        ]);

        Cache::forget("prompt_template:{$name}");
    }
}
