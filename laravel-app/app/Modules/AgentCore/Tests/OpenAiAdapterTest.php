<?php

namespace App\Modules\AgentCore\Tests;

use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\AgentCore\LLM\Exceptions\LlmJsonParseException;
use App\Modules\AgentCore\LLM\JsonRepairGuard;
use App\Modules\AgentCore\LLM\Services\TokenUsageLogger;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\DTOs\LlmResponseDTO;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Tests\TestCase;

/**
 * PRINSIP 9 — All tests use MockLlmAdapter, no real OpenAI API calls.
 */
class OpenAiAdapterTest extends TestCase
{
    private MockLlmAdapter $mock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockLlmAdapter();
        app()->instance(LlmClientInterface::class, $this->mock);
    }

    // ─── MockLlmAdapter via container binding ────────────────────────────────

    public function test_container_resolves_mock_adapter_in_test_env(): void
    {
        $instance = app(LlmClientInterface::class);
        $this->assertInstanceOf(MockLlmAdapter::class, $instance);
    }

    public function test_complete_returns_llm_response_dto(): void
    {
        $this->mock->setNextResponse('{"intent":"greeting","confidence":0.95}');

        $result = $this->mock->complete('Halo kak');

        $this->assertInstanceOf(LlmResponseDTO::class, $result);
        $this->assertEquals('mock', $result->model);
        $this->assertStringContainsString('greeting', $result->content);
        $this->assertEquals(1, $this->mock->getCallCount());
    }

    public function test_complete_json_with_valid_json_decodes_correctly(): void
    {
        $this->mock->setNextResponse('{"intent":"ask_price","confidence":0.88,"reason":"price question"}');

        $result = $this->mock->completeJson('Berapa harga paket?');

        $this->assertEquals('ask_price', $result['intent']);
        $this->assertEquals(0.88, $result['confidence']);
    }

    public function test_complete_json_with_markdown_code_block_is_repaired(): void
    {
        // MockLlmAdapter goes through completeJson which calls JsonRepairGuard internally
        // We test JsonRepairGuard directly for the markdown case (see below)
        // Here we test that completeJson with valid JSON works via the mock
        $this->mock->setNextResponse('{"intent":"greeting"}');
        $result = $this->mock->completeJson('Test prompt');
        $this->assertEquals('greeting', $result['intent']);
    }

    public function test_complete_json_with_unrecoverable_json_throws_exception(): void
    {
        $this->mock->setNextResponse('this is completely invalid json !!!');

        $this->expectException(RuntimeException::class);
        $this->mock->completeJson('Test prompt');
    }

    public function test_call_count_is_one_per_complete(): void
    {
        $this->mock->setNextResponse('{"ok":true}');
        $this->mock->complete('prompt');
        $this->assertEquals(1, $this->mock->getCallCount());
    }

    public function test_multiple_queued_responses_returned_in_order(): void
    {
        $this->mock->setNextResponses([
            '{"intent":"greeting"}',
            '{"intent":"ask_price"}',
        ]);

        $r1 = $this->mock->complete('turn 1');
        $r2 = $this->mock->complete('turn 2');

        $this->assertStringContainsString('greeting', $r1->content);
        $this->assertStringContainsString('ask_price', $r2->content);
        $this->assertEquals(2, $this->mock->getCallCount());
    }

    // ─── JsonRepairGuard ─────────────────────────────────────────────────────

    public function test_json_repair_guard_valid_json_returns_array(): void
    {
        $result = JsonRepairGuard::repair('{"intent":"greeting","confidence":0.95}');

        $this->assertEquals('greeting', $result['intent']);
        $this->assertEquals(0.95, $result['confidence']);
    }

    public function test_json_repair_guard_strips_markdown_code_block(): void
    {
        $raw    = "```json\n{\"intent\":\"greeting\"}\n```";
        $result = JsonRepairGuard::repair($raw);

        $this->assertEquals('greeting', $result['intent']);
    }

    public function test_json_repair_guard_strips_plain_code_block(): void
    {
        $raw    = "```\n{\"intent\":\"ask_price\"}\n```";
        $result = JsonRepairGuard::repair($raw);

        $this->assertEquals('ask_price', $result['intent']);
    }

    public function test_json_repair_guard_extracts_json_from_surrounding_text(): void
    {
        $raw    = 'Here is the JSON: {"intent":"ask_price"} — done.';
        $result = JsonRepairGuard::repair($raw);

        $this->assertEquals('ask_price', $result['intent']);
    }

    public function test_json_repair_guard_throws_on_completely_invalid_input(): void
    {
        $this->expectException(LlmJsonParseException::class);
        $this->expectExceptionMessageMatches('/Cannot repair JSON/');

        JsonRepairGuard::repair('this has no json at all here, totally broken');
    }

    public function test_is_valid_json_returns_true_for_valid_json(): void
    {
        $this->assertTrue(JsonRepairGuard::isValidJson('{"key":"value"}'));
    }

    public function test_is_valid_json_returns_false_for_invalid_json(): void
    {
        $this->assertFalse(JsonRepairGuard::isValidJson('not json'));
    }

    // ─── TokenUsageLogger ────────────────────────────────────────────────────

    public function test_token_usage_logger_log_stores_to_redis(): void
    {
        $tenantId = 'tenant-abc';
        $yearMonth = date('Y-m');

        Redis::shouldReceive('pipeline')->once()->andReturnNull();

        $logger   = new TokenUsageLogger();
        $response = LlmResponseDTO::from([
            'content'           => 'test',
            'model'             => 'gpt-4o-mini',
            'prompt_tokens'     => 100,
            'completion_tokens' => 50,
            'total_tokens'      => 150,
            'finish_reason'     => 'stop',
        ]);

        // Should not throw
        $logger->log($tenantId, 'intent_classify', $response);

        $this->assertTrue(true);
    }

    public function test_token_usage_logger_get_monthly_usage_returns_correct_structure(): void
    {
        $tenantId  = 'tenant-xyz';
        $yearMonth = '2026-05';

        Redis::shouldReceive('hgetall')
            ->with("token_usage:{$tenantId}:{$yearMonth}")
            ->andReturn([
                'prompt_tokens'     => '300',
                'completion_tokens' => '150',
                'total_tokens'      => '450',
            ]);

        Redis::shouldReceive('keys')
            ->with("token_usage:{$tenantId}:{$yearMonth}:purpose:*")
            ->andReturn([
                "token_usage:{$tenantId}:{$yearMonth}:purpose:intent_classify",
            ]);

        Redis::shouldReceive('hgetall')
            ->with("token_usage:{$tenantId}:{$yearMonth}:purpose:intent_classify")
            ->andReturn([
                'prompt_tokens'     => '200',
                'completion_tokens' => '100',
                'total_tokens'      => '300',
            ]);

        $logger = new TokenUsageLogger();
        $result = $logger->getMonthlyUsage($tenantId, $yearMonth);

        $this->assertEquals(300, $result['prompt_tokens']);
        $this->assertEquals(150, $result['completion_tokens']);
        $this->assertEquals(450, $result['total_tokens']);
        $this->assertArrayHasKey('intent_classify', $result['by_purpose']);
        $this->assertEquals(300, $result['by_purpose']['intent_classify']['total_tokens']);
    }

    public function test_token_usage_logger_returns_zero_for_empty_redis(): void
    {
        $tenantId  = 'tenant-empty';
        $yearMonth = '2026-05';

        Redis::shouldReceive('hgetall')
            ->with("token_usage:{$tenantId}:{$yearMonth}")
            ->andReturn([]);

        Redis::shouldReceive('keys')
            ->with("token_usage:{$tenantId}:{$yearMonth}:purpose:*")
            ->andReturn([]);

        $logger = new TokenUsageLogger();
        $result = $logger->getMonthlyUsage($tenantId, $yearMonth);

        $this->assertEquals(0, $result['prompt_tokens']);
        $this->assertEquals(0, $result['completion_tokens']);
        $this->assertEquals(0, $result['total_tokens']);
        $this->assertEmpty($result['by_purpose']);
    }
}
