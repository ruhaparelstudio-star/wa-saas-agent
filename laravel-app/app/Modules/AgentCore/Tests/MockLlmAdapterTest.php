<?php

namespace App\Modules\AgentCore\Tests;

use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\Shared\Contracts\LlmClientInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * PRINSIP 9 — Verifikasi MockLlmAdapter tidak call real API.
 * php artisan test --filter=Unit → tidak ada call ke OpenAI.
 */
class MockLlmAdapterTest extends TestCase
{
    private MockLlmAdapter $mock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockLlmAdapter();
    }

    public function test_mock_implements_llm_client_interface(): void
    {
        $this->assertInstanceOf(LlmClientInterface::class, $this->mock);
    }

    public function test_complete_returns_queued_response_without_real_api_call(): void
    {
        $this->mock->setNextResponse('{"intent":"greeting","confidence":0.95}');

        $result = $this->mock->complete('Halo kak mau tanya paket');

        $this->assertEquals('{"intent":"greeting","confidence":0.95}', $result->content);
        $this->assertEquals('mock', $result->model);
        $this->assertEquals(1, $this->mock->getCallCount());
    }

    public function test_complete_json_parses_queued_json_response(): void
    {
        $this->mock->setNextResponse('{"intent":"ask_price","confidence":0.88,"reason":"user asks about price"}');

        $result = $this->mock->completeJson('Berapa harga paketnya?');

        $this->assertEquals('ask_price', $result['intent']);
        $this->assertEquals(0.88, $result['confidence']);
        $this->assertEquals(1, $this->mock->getCallCount());
    }

    public function test_embed_returns_mock_vector_without_real_api_call(): void
    {
        $result = $this->mock->embed('paket foto wedding outdoor');

        $this->assertCount(1536, $result->embedding);
        $this->assertEquals('mock', $result->model);
        $this->assertEquals(1, $this->mock->getCallCount());
    }

    public function test_get_last_prompt_returns_most_recent_prompt(): void
    {
        $this->mock->setNextResponse('{}');
        $this->mock->complete('Prompt pertama');

        $this->assertEquals('Prompt pertama', $this->mock->getLastPrompt());
    }

    public function test_set_next_responses_queues_multiple_in_order(): void
    {
        $this->mock->setNextResponses([
            '{"intent":"greeting"}',
            '{"intent":"ask_price"}',
            '{"intent":"booking_intent"}',
        ]);

        $r1 = $this->mock->complete('turn 1');
        $r2 = $this->mock->complete('turn 2');
        $r3 = $this->mock->complete('turn 3');

        $this->assertStringContainsString('greeting', $r1->content);
        $this->assertStringContainsString('ask_price', $r2->content);
        $this->assertStringContainsString('booking_intent', $r3->content);
        $this->assertEquals(3, $this->mock->getCallCount());
    }

    public function test_reset_clears_all_state(): void
    {
        $this->mock->setNextResponse('{"intent":"test"}');
        $this->mock->complete('some prompt');

        $this->mock->reset();

        $this->assertEquals(0, $this->mock->getCallCount());
        $this->assertEquals('', $this->mock->getLastPrompt());
    }

    public function test_throws_runtime_exception_when_no_response_queued(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no response queued/');

        $this->mock->complete('prompt without queued response');
    }

    public function test_throws_exception_when_response_is_not_valid_json(): void
    {
        $this->mock->setNextResponse('this is not json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        $this->mock->completeJson('some prompt');
    }

    public function test_call_count_tracks_both_complete_and_embed(): void
    {
        $this->mock->setNextResponse('{"intent":"test"}');
        $this->mock->complete('prompt 1');
        $this->mock->embed('embedding text');

        $this->assertEquals(2, $this->mock->getCallCount());
    }
}
