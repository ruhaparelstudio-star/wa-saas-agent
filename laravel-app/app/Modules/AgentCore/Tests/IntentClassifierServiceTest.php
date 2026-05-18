<?php

namespace App\Modules\AgentCore\Tests;

use App\Modules\AgentCore\Classification\Services\IntentClassifierService;
use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\AgentCore\LLM\Services\PromptVersioningService;
use App\Modules\AgentCore\LLM\Services\TokenUsageLogger;
use App\Modules\Shared\Contracts\LlmClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IntentClassifierServiceTest extends TestCase
{
    use RefreshDatabase;

    private MockLlmAdapter $mock;
    private IntentClassifierService $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock = new MockLlmAdapter();
        $this->app->instance(LlmClientInterface::class, $this->mock);

        $this->classifier = new IntentClassifierService(
            $this->mock,
            $this->app->make(TokenUsageLogger::class),
            new PromptVersioningService(),
        );
    }

    public function test_classify_greeting(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'greeting',
            'confidence' => 0.95,
            'reason'     => 'Customer said hello',
        ]));

        $result = $this->classifier->classify('halo kak', 'tenant-1');

        $this->assertSame('greeting', $result->intent);
        $this->assertEqualsWithDelta(0.95, $result->confidence, 0.001);
        $this->assertSame(1, $this->mock->getCallCount());
    }

    public function test_classify_ask_price(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'ask_price',
            'confidence' => 0.92,
            'reason'     => 'Asking about price',
        ]));

        $result = $this->classifier->classify('berapa harga paket foto wedding?', 'tenant-1');

        $this->assertSame('ask_price', $result->intent);
    }

    public function test_classify_ask_availability(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'ask_availability',
            'confidence' => 0.90,
            'reason'     => 'Asking about date availability',
        ]));

        $result = $this->classifier->classify('ada slot bulan juni?', 'tenant-1');

        $this->assertSame('ask_availability', $result->intent);
    }

    public function test_classify_confirm_booking(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'confirm_booking',
            'confidence' => 0.98,
            'reason'     => 'Customer wants to book now',
        ]));

        $result = $this->classifier->classify('mau booking sekarang', 'tenant-1');

        $this->assertSame('confirm_booking', $result->intent);
    }

    public function test_unknown_intent_falls_back_to_unclear_message(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'fly_to_moon',
            'confidence' => 0.9,
            'reason'     => 'Unknown intent',
        ]));

        $result = $this->classifier->classify('some message', 'tenant-1');

        $this->assertSame('unclear_message', $result->intent);
        $this->assertEqualsWithDelta(0.0, $result->confidence, 0.001);
    }

    public function test_invalid_json_falls_back_to_unclear_message(): void
    {
        // MockLlmAdapter will throw if JSON is not valid when completeJson is called,
        // but classify() calls complete() directly and handles parse failure itself.
        // We simulate broken JSON that JsonRepairGuard also cannot fix.
        $this->mock->setNextResponse('THIS IS NOT JSON AT ALL )))');

        $result = $this->classifier->classify('some message', 'tenant-1');

        $this->assertSame('unclear_message', $result->intent);
        $this->assertEqualsWithDelta(0.0, $result->confidence, 0.001);
    }

    public function test_call_count_is_one_per_classify(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'greeting',
            'confidence' => 0.9,
            'reason'     => '',
        ]));

        $this->classifier->classify('halo', 'tenant-1');

        $this->assertSame(1, $this->mock->getCallCount());
    }

    public function test_prompt_contains_the_message(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'ask_price',
            'confidence' => 0.9,
            'reason'     => '',
        ]));

        $message = 'berapa harga paket ultimate?';
        $this->classifier->classify($message, 'tenant-1');

        $this->assertStringContainsString($message, $this->mock->getLastPrompt());
    }

    public function test_prompt_contains_valid_intent_list(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'greeting',
            'confidence' => 0.9,
            'reason'     => '',
        ]));

        $this->classifier->classify('halo', 'tenant-1');

        $prompt = $this->mock->getLastPrompt();
        $this->assertStringContainsString('wedding vendor', $prompt);
        $this->assertStringContainsString('greeting', $prompt);
        $this->assertStringContainsString('ask_price', $prompt);
        $this->assertStringContainsString('confirm_booking', $prompt);
    }

    public function test_conversation_context_injected_into_prompt(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'ask_price',
            'confidence' => 0.9,
            'reason'     => '',
        ]));

        $context = [
            ['direction' => 'in',  'body' => 'halo kak'],
            ['direction' => 'out', 'body' => 'Halo Kak! Ada yang bisa dibantu?'],
            ['direction' => 'in',  'body' => 'mau tanya soal harga'],
        ];

        $this->classifier->classify('berapa harga paket?', 'tenant-1', $context);

        $prompt = $this->mock->getLastPrompt();
        $this->assertStringContainsString('halo kak', $prompt);
        $this->assertStringContainsString('mau tanya soal harga', $prompt);
    }

    public function test_token_usage_logger_called_once_after_classify(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'greeting',
            'confidence' => 0.9,
            'reason'     => '',
        ]));

        $loggerMock = $this->getMockBuilder(TokenUsageLogger::class)
            ->disableOriginalConstructor()
            ->getMock();

        $loggerMock->expects($this->once())
            ->method('log')
            ->with('tenant-1', 'intent_classify', $this->anything());

        $classifier = new IntentClassifierService($this->mock, $loggerMock, new PromptVersioningService());
        $classifier->classify('halo', 'tenant-1');
    }

    public function test_classifier_includes_all_passed_context(): void
    {
        // Sizing is now decided upstream (pipeline reads tenant policy).
        // The classifier must faithfully include every message it receives.
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'greeting',
            'confidence' => 0.9,
            'reason'     => '',
        ]));

        $context = [];
        for ($i = 1; $i <= 10; $i++) {
            $context[] = ['direction' => 'in', 'body' => "message {$i}"];
        }

        $this->classifier->classify('halo', 'tenant-1', $context);

        $prompt = $this->mock->getLastPrompt();
        $this->assertStringContainsString('message 1', $prompt);
        $this->assertStringContainsString('message 10', $prompt);
        $this->assertStringContainsString('last 10 messages', $prompt);
    }

    public function test_classifier_injects_context_summary_when_provided(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'ask_price',
            'confidence' => 0.9,
            'reason'     => '',
        ]));

        $summary = 'Customer Budi, akad+resepsi 15 Juni 2026 Jakarta, budget 30jt, pricelist sudah dikirim.';

        $this->classifier->classify('paket silver gimana?', 'tenant-1', [], $summary);

        $prompt = $this->mock->getLastPrompt();
        $this->assertStringContainsString('Earlier conversation summary', $prompt);
        $this->assertStringContainsString('Customer Budi', $prompt);
    }

    public function test_all_valid_intents_are_defined(): void
    {
        $this->assertCount(22, IntentClassifierService::VALID_INTENTS);
        $this->assertContains('greeting', IntentClassifierService::VALID_INTENTS);
        $this->assertContains('request_booking', IntentClassifierService::VALID_INTENTS);
        $this->assertContains('handoff_request', IntentClassifierService::VALID_INTENTS);
        $this->assertContains('invoice_inquiry', IntentClassifierService::VALID_INTENTS);
        $this->assertContains('acknowledge', IntentClassifierService::VALID_INTENTS);
    }

    public function test_classifies_acknowledge_short_reply(): void
    {
        $this->mock->setNextResponse(json_encode([
            'intent'     => 'acknowledge',
            'confidence' => 0.9,
            'reason'     => 'short ack',
        ]));

        $result = $this->classifier->classify('siap ka', 'tenant-1');

        $this->assertSame('acknowledge', $result->intent);

        $prompt = $this->mock->getLastPrompt();
        $this->assertStringContainsString('acknowledge', $prompt);
        $this->assertStringContainsString('"siap ka" → acknowledge', $prompt);
    }
}
