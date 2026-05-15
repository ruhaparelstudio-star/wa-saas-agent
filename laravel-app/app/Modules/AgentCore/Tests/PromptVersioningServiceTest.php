<?php

namespace App\Modules\AgentCore\Tests;

use App\Modules\AgentCore\LLM\Models\PromptTemplate;
use App\Modules\AgentCore\LLM\Services\PromptVersioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PromptVersioningServiceTest extends TestCase
{
    use RefreshDatabase;

    private PromptVersioningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = new PromptVersioningService();
    }

    public function test_get_active_template_returns_from_db(): void
    {
        PromptTemplate::create([
            'name'             => 'intent_classifier',
            'version'          => 'v1.0',
            'template'         => 'You are a classifier. Message: %MESSAGE%',
            'is_active'        => true,
            'accuracy_history' => [],
        ]);

        $result = $this->service->getActiveTemplate('intent_classifier');

        $this->assertStringContainsString('You are a classifier', $result);
    }

    public function test_get_active_template_returns_fallback_when_db_empty(): void
    {
        $this->service->registerFallback('intent_classifier', 'FALLBACK_TEMPLATE');

        $result = $this->service->getActiveTemplate('intent_classifier');

        $this->assertSame('FALLBACK_TEMPLATE', $result);
    }

    public function test_get_active_template_returns_empty_string_when_no_fallback_and_no_db(): void
    {
        $result = $this->service->getActiveTemplate('nonexistent_template');

        $this->assertSame('', $result);
    }

    public function test_get_active_template_cached_on_second_call(): void
    {
        PromptTemplate::create([
            'name'             => 'entity_extractor',
            'version'          => 'v1.0',
            'template'         => 'Entity extractor template',
            'is_active'        => true,
            'accuracy_history' => [],
        ]);

        // First call — hits DB
        $first = $this->service->getActiveTemplate('entity_extractor');

        // Update the DB record (should NOT be seen on second call — cache is active)
        PromptTemplate::where('name', 'entity_extractor')->update(['template' => 'UPDATED']);

        // Second call — should still return cached value
        $second = $this->service->getActiveTemplate('entity_extractor');

        $this->assertSame($first, $second);
        $this->assertSame('Entity extractor template', $second);
    }

    public function test_record_accuracy_appends_to_history(): void
    {
        PromptTemplate::create([
            'name'             => 'intent_classifier',
            'version'          => 'v1.0',
            'template'         => 'template',
            'is_active'        => true,
            'accuracy_history' => [],
        ]);

        $this->service->recordAccuracy('intent_classifier', 'v1.0', 0.98, 100);

        $row = PromptTemplate::where('name', 'intent_classifier')->first();
        $history = $row->accuracy_history;

        $this->assertCount(1, $history);
        $this->assertSame(0.98, $history[0]['score']);
        $this->assertSame('v1.0', $history[0]['version']);
        $this->assertSame(100, $history[0]['test_count']);
    }

    public function test_record_accuracy_regression_logs_warning(): void
    {
        PromptTemplate::create([
            'name'             => 'intent_classifier',
            'version'          => 'v1.0',
            'template'         => 'template',
            'is_active'        => true,
            'accuracy_history' => [
                ['version' => 'v1.0', 'score' => 0.98, 'date' => now()->toIso8601String(), 'test_count' => 100],
            ],
        ]);

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn($msg) => str_contains($msg, 'regression detected for intent_classifier'));

        // Score drops from 0.98 to 0.70 — more than 5% regression
        $this->service->recordAccuracy('intent_classifier', 'v1.0', 0.70, 50);
    }

    public function test_record_accuracy_no_warning_when_score_stays_high(): void
    {
        PromptTemplate::create([
            'name'             => 'intent_classifier',
            'version'          => 'v1.0',
            'template'         => 'template',
            'is_active'        => true,
            'accuracy_history' => [
                ['version' => 'v1.0', 'score' => 0.98, 'date' => now()->toIso8601String(), 'test_count' => 100],
            ],
        ]);

        Log::shouldReceive('warning')->never();

        // Score drops by only 2% — no regression warning
        $this->service->recordAccuracy('intent_classifier', 'v1.0', 0.96, 50);
    }

    public function test_rollback_clears_cache(): void
    {
        PromptTemplate::create([
            'name'             => 'intent_classifier',
            'version'          => 'v1.0',
            'template'         => 'original template',
            'is_active'        => true,
            'accuracy_history' => [],
        ]);

        // Prime the cache
        $this->service->getActiveTemplate('intent_classifier');
        $this->assertTrue(Cache::has('prompt_template:intent_classifier'));

        // Rollback should clear cache
        $this->service->rollback('intent_classifier');

        $this->assertFalse(Cache::has('prompt_template:intent_classifier'));
    }

    public function test_inactive_template_is_not_returned(): void
    {
        PromptTemplate::create([
            'name'             => 'intent_classifier',
            'version'          => 'v1.0',
            'template'         => 'inactive template',
            'is_active'        => false,
            'accuracy_history' => [],
        ]);

        $this->service->registerFallback('intent_classifier', 'FALLBACK');

        $result = $this->service->getActiveTemplate('intent_classifier');

        // Inactive DB record should be skipped → fallback returned
        $this->assertSame('FALLBACK', $result);
    }

    public function test_record_accuracy_does_nothing_when_no_active_template(): void
    {
        // Should not throw — silently no-ops
        $this->service->recordAccuracy('nonexistent', 'v1.0', 0.95, 10);
        $this->assertTrue(true);
    }
}
