<?php

namespace App\Modules\AgentCore\Providers;

use App\Modules\AgentCore\Classification\Services\IntentClassifierService;
use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\AgentCore\LLM\Adapters\OpenAiAdapter;
use App\Modules\AgentCore\LLM\Services\TokenUsageLogger;
use App\Modules\Shared\Contracts\IntentClassifierInterface;
use App\Modules\Shared\Contracts\LlmClientInterface;
use Illuminate\Support\ServiceProvider;

class AgentCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LlmClientInterface::class, function () {
            return match (config('llm.provider', 'openai')) {
                'mock'  => new MockLlmAdapter(),
                default => new OpenAiAdapter(),
            };
        });

        $this->app->singleton(TokenUsageLogger::class);

        $this->app->bind(IntentClassifierInterface::class, IntentClassifierService::class);
    }

    public function boot(): void {}
}
