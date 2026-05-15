<?php

namespace App\Modules\AgentCore\Tests;

use App\Modules\AgentCore\Composer\Services\ResponseComposerService;
use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\AgentCore\LLM\Services\TokenUsageLogger;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\ConversationDTO;
use App\Modules\Shared\DTOs\ConversationStateDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\EntityResultDTO;
use App\Modules\Shared\DTOs\GroundedKnowledgeDTO;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\DTOs\IntentResultDTO;
use App\Modules\Shared\DTOs\LeadProfileDTO;
use App\Modules\Shared\DTOs\TenantConfigDTO;
use App\Modules\Shared\DTOs\TenantDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\DTOs\ValidatorResultDTO;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\Shared\Enums\MemoryMode;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResponseComposerServiceTest extends TestCase
{
    private MockLlmAdapter $mock;
    private ResponseComposerService $composer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock = new MockLlmAdapter();
        app()->instance(LlmClientInterface::class, $this->mock);

        $tokenUsageLogger  = $this->createMock(TokenUsageLogger::class);
        $this->composer    = new ResponseComposerService($this->mock, $tokenUsageLogger);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Normal compose — LLM is called
    // ──────────────────────────────────────────────────────────────────────

    public function test_normal_compose_calls_llm_once_and_returns_valid_reply(): void
    {
        $this->mock->setNextResponse('Halo Kak! Paket kami tersedia ya 😊');

        $result = $this->composer->compose(
            $this->makeContext(),
            $this->makeDecision(),
            $this->makeValidatorResult(),
        );

        $this->assertInstanceOf(ComposedReplyDTO::class, $result);
        $this->assertNotEmpty($result->reply_text);
        $this->assertSame('text', $result->reply_type);
        $this->assertSame(1, $this->mock->getCallCount());
    }

    public function test_compose_with_empty_grounding_refs_does_not_throw(): void
    {
        $this->mock->setNextResponse('Terima kasih sudah menghubungi kami Kak!');

        $result = $this->composer->compose(
            $this->makeContext(knowledgeData: []),
            $this->makeDecision(),
            $this->makeValidatorResult(),
        );

        $this->assertNotEmpty($result->reply_text);
        $this->assertSame(1, $this->mock->getCallCount());
    }

    // ──────────────────────────────────────────────────────────────────────
    // HANDOFF mode — preset returned, NO LLM call
    // ──────────────────────────────────────────────────────────────────────

    public function test_handoff_required_returns_preset_without_llm_call(): void
    {
        $result = $this->composer->compose(
            $this->makeContext(),
            $this->makeDecision(handoffRequired: true),
            $this->makeValidatorResult(),
        );

        $this->assertSame(ResponseComposerService::HANDOFF_MESSAGE, $result->reply_text);
        $this->assertSame(0, $this->mock->getCallCount());
    }

    public function test_handoff_decision_value_returns_preset_without_llm_call(): void
    {
        $result = $this->composer->compose(
            $this->makeContext(),
            $this->makeDecision(decisionValue: 'handoff'),
            $this->makeValidatorResult(),
        );

        $this->assertSame(ResponseComposerService::HANDOFF_MESSAGE, $result->reply_text);
        $this->assertSame(0, $this->mock->getCallCount());
    }

    // ──────────────────────────────────────────────────────────────────────
    // Mode blocked — preset returned, NO LLM call
    // ──────────────────────────────────────────────────────────────────────

    public function test_mode_blocked_returns_preset_without_llm_call(): void
    {
        $result = $this->composer->compose(
            $this->makeContext(),
            $this->makeDecision(),
            $this->makeValidatorResult(modeResult: 'blocked'),
        );

        $this->assertSame(ResponseComposerService::HANDOFF_MESSAGE, $result->reply_text);
        $this->assertSame(0, $this->mock->getCallCount());
    }

    public function test_blocked_decision_value_returns_preset_without_llm_call(): void
    {
        $result = $this->composer->compose(
            $this->makeContext(),
            $this->makeDecision(decisionValue: 'blocked'),
            $this->makeValidatorResult(),
        );

        $this->assertSame(ResponseComposerService::HANDOFF_MESSAGE, $result->reply_text);
        $this->assertSame(0, $this->mock->getCallCount());
    }

    // ──────────────────────────────────────────────────────────────────────
    // Hallucination detection
    // ──────────────────────────────────────────────────────────────────────

    public function test_detected_hallucination_false_when_reply_only_mentions_grounded_data(): void
    {
        $this->mock->setNextResponse('Halo Kak! Paket Standard tersedia ya Kak.');

        $result = $this->composer->compose(
            $this->makeContext(knowledgeData: [
                'packages' => [['name' => 'Standard', 'slug' => 'standard', 'price' => 5000000]],
            ]),
            $this->makeDecision(),
            $this->makeValidatorResult(),
        );

        $this->assertFalse($result->detected_hallucination);
    }

    public function test_detected_hallucination_true_when_reply_mentions_price_not_in_grounding(): void
    {
        // Mock returns a price, but no price data is in grounding
        $this->mock->setNextResponse('Halo Kak! Harga paket kami Rp 15.000.000 ya Kak.');

        $result = $this->composer->compose(
            $this->makeContext(knowledgeData: []),   // empty — no price data
            $this->makeDecision(),
            $this->makeValidatorResult(),
        );

        $this->assertTrue($result->detected_hallucination);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Prompt content verification
    // ──────────────────────────────────────────────────────────────────────

    public function test_prompt_contains_package_names_from_grounding(): void
    {
        $this->mock->setNextResponse('Paket Standard tersedia Kak!');

        $this->composer->compose(
            $this->makeContext(knowledgeData: [
                'packages' => [['name' => 'Paket Standard', 'slug' => 'standard', 'price' => 5000000]],
            ]),
            $this->makeDecision(),
            $this->makeValidatorResult(),
        );

        $this->assertStringContainsString('Paket Standard', $this->mock->getLastPrompt());
    }

    public function test_prompt_contains_tone_and_kak_instruction(): void
    {
        $this->mock->setNextResponse('Baik Kak!');

        $this->composer->compose(
            $this->makeContext(),
            $this->makeDecision(),
            $this->makeValidatorResult(),
        );

        $lastPrompt = $this->mock->getLastPrompt();
        $this->assertStringContainsString('semi_formal', $lastPrompt);
        $this->assertStringContainsString('Kak', $lastPrompt);
    }

    public function test_prompt_contains_customer_message(): void
    {
        $this->mock->setNextResponse('Baik Kak!');

        $this->composer->compose(
            $this->makeContext(message: 'berapa harga paket wedding?'),
            $this->makeDecision(),
            $this->makeValidatorResult(),
        );

        $this->assertStringContainsString('berapa harga paket wedding?', $this->mock->getLastPrompt());
    }

    // ──────────────────────────────────────────────────────────────────────
    // TokenUsageLogger
    // ──────────────────────────────────────────────────────────────────────

    public function test_token_usage_logger_called_once_when_llm_is_invoked(): void
    {
        $mockLogger = $this->createMock(TokenUsageLogger::class);
        $mockLogger->expects($this->once())->method('log');

        $composer = new ResponseComposerService($this->mock, $mockLogger);
        $this->mock->setNextResponse('Halo Kak!');

        $composer->compose(
            $this->makeContext(),
            $this->makeDecision(),
            $this->makeValidatorResult(),
        );
    }

    public function test_token_usage_logger_not_called_when_handoff_preset_returned(): void
    {
        $mockLogger = $this->createMock(TokenUsageLogger::class);
        $mockLogger->expects($this->never())->method('log');

        $composer = new ResponseComposerService($this->mock, $mockLogger);

        $result = $composer->compose(
            $this->makeContext(),
            $this->makeDecision(handoffRequired: true),
            $this->makeValidatorResult(),
        );

        $this->assertSame(ResponseComposerService::HANDOFF_MESSAGE, $result->reply_text);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Grounding refs passed through
    // ──────────────────────────────────────────────────────────────────────

    public function test_grounding_refs_are_included_in_composed_reply(): void
    {
        $this->mock->setNextResponse('Halo Kak!');

        $result = $this->composer->compose(
            $this->makeContext(knowledgeData: [
                'packages' => [['name' => 'Standard', 'slug' => 'standard', 'price' => 5000000]],
            ], groundingRefs: [
                ['type' => 'structured', 'source' => 'packages', 'id' => 'pkg-1', 'key_data' => 'Standard'],
            ]),
            $this->makeDecision(),
            $this->makeValidatorResult(),
        );

        $this->assertCount(1, $result->grounding_refs);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    private function makeContext(
        ?array  $knowledgeData = null,
        array   $groundingRefs = [],
        string  $message       = 'berapa harga paket?',
    ): TurnContextDTO {
        $structured = $knowledgeData ?? [
            'packages' => [['name' => 'Standard', 'slug' => 'standard', 'price' => 5000000]],
        ];

        return TurnContextDTO::from([
            'tenant' => TenantDTO::from([
                'id'            => Str::uuid()->toString(),
                'name'          => 'Demo Vendor',
                'slug'          => 'demo',
                'status'        => 'active',
                'industry'      => 'wedding',
                'contact_email' => 'demo@vendor.com',
                'contact_phone' => null,
                'created_at'    => now()->toISOString(),
            ]),
            'conversation' => ConversationDTO::from([
                'id'              => Str::uuid()->toString(),
                'tenant_id'       => Str::uuid()->toString(),
                'wa_account_id'   => Str::uuid()->toString(),
                'from_phone'      => '+628121234567',
                'stage'           => ConversationStage::NEW_LEAD->value,
                'agent_mode'      => AgentMode::ACTIVE->value,
                'memory_mode'     => MemoryMode::ACTIVE->value,
                'context_summary' => null,
                'created_at'      => now()->toISOString(),
                'updated_at'      => now()->toISOString(),
            ]),
            'state'           => ConversationStateDTO::from([]),
            'lead'            => LeadProfileDTO::from([]),
            'intent'          => IntentResultDTO::from([
                'intent'       => 'ask_price',
                'confidence'   => 0.92,
                'reason'       => 'Customer asked about price',
                'raw_response' => '{"intent":"ask_price"}',
            ]),
            'entities'        => EntityResultDTO::from([]),
            'knowledge'       => GroundedKnowledgeDTO::from([
                'structured_data' => $structured,
                'vector_results'  => [],
                'grounding_refs'  => $groundingRefs,
                'search_method'   => 'tsvector',
            ]),
            'config'          => TenantConfigDTO::from([
                'tenant_id'            => Str::uuid()->toString(),
                'tone'                 => 'semi_formal',
                'timezone'             => 'Asia/Jakarta',
                'business_hours_start' => '08:00',
                'business_hours_end'   => '21:00',
                'policies'             => [],
                'features'             => [],
            ]),
            'inbound_message' => InboundMessageDTO::from([
                'wa_account_id'       => Str::uuid()->toString(),
                'provider_message_id' => 'msg-001',
                'from_phone'          => '+628121234567',
                'message_type'        => 'text',
                'body'                => $message,
                'media_url'           => null,
                'raw_payload'         => [],
                'received_at'         => now()->toISOString(),
            ]),
            'is_sanitized'       => true,
            'injection_detected' => false,
        ]);
    }

    private function makeDecision(
        bool   $handoffRequired = false,
        string $decisionValue   = 'proceed',
    ): DecisionDTO {
        return DecisionDTO::from([
            'decision'              => $decisionValue,
            'desired_actions'       => ['send_price_info'],
            'allowed_actions'       => ['send_price_info'],
            'blocked_actions'       => [],
            'handoff_required'      => $handoffRequired,
            'handoff_reason'        => null,
            'handoff_priority'      => HandoffPriority::LOW->value,
            'notification_required' => false,
            'reply_strategy'        => 'send_grounded_reply',
            'active_goal'           => 'answer_price_inquiry',
            'stage_transition'      => null,
        ]);
    }

    private function makeValidatorResult(string $modeResult = 'passed'): ValidatorResultDTO
    {
        return ValidatorResultDTO::from([
            'policy_result'         => 'passed',
            'grounding_result'      => 'passed',
            'permission_result'     => 'passed',
            'mode_result'           => $modeResult,
            'final_allowed_actions' => ['send_price_info'],
            'final_blocked_actions' => [],
            'warnings'              => [],
        ]);
    }
}
