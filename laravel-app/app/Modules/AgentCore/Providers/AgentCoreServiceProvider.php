<?php

namespace App\Modules\AgentCore\Providers;

use App\Modules\AgentCore\Classification\Services\IntentClassifierService;
use App\Modules\AgentCore\Composer\Services\ResponseComposerService;
use App\Modules\AgentCore\Decision\Services\DecisionEngineService;
use App\Modules\AgentCore\Extraction\Services\EntityExtractionService;
use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\AgentCore\LLM\Adapters\OpenAiAdapter;
use App\Modules\AgentCore\LLM\Services\PromptVersioningService;
use App\Modules\AgentCore\LLM\Services\TokenUsageLogger;
use App\Modules\AgentCore\Pipeline\Services\ActionDispatcher;
use App\Modules\AgentCore\Pipeline\Services\DecisionTraceLogger;
use App\Modules\Knowledge\Services\PricelistService;
use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\Contracts\DecisionEngineInterface;
use App\Modules\Shared\Contracts\EntityExtractorInterface;
use App\Modules\Shared\Contracts\IntentClassifierInterface;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\Contracts\ResponseComposerInterface;
use App\Modules\WhatsApp\Adapters\WhatsAppGatewayAdapter;
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

        $this->app->singleton(PromptVersioningService::class);

        $this->app->singleton(DecisionTraceLogger::class);

        $this->app->bind(IntentClassifierInterface::class, IntentClassifierService::class);

        $this->app->bind(EntityExtractorInterface::class, EntityExtractionService::class);

        $this->app->bind(DecisionEngineInterface::class, DecisionEngineService::class);

        $this->app->bind(ResponseComposerInterface::class, ResponseComposerService::class);

        $this->app->bind(ChannelGatewayInterface::class, WhatsAppGatewayAdapter::class);

        $this->app->bind(ActionDispatcher::class, function ($app) {
            return new ActionDispatcher(
                $app->make(ChannelGatewayInterface::class),
                $app->make(\App\Modules\Conversation\Repositories\ConversationRepository::class),
                $app->make(\App\Modules\Handoff\Services\HandoffService::class),
                $app->make(PricelistService::class),
            );
        });
    }

    public function boot(): void {}
}
