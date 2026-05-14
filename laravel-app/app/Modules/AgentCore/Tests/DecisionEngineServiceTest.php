<?php

namespace App\Modules\AgentCore\Tests;

use App\Modules\AgentCore\Decision\Services\DecisionEngineService;
use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\DTOs\ConversationDTO;
use App\Modules\Shared\DTOs\ConversationStateDTO;
use App\Modules\Shared\DTOs\EntityResultDTO;
use App\Modules\Shared\DTOs\GroundedKnowledgeDTO;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\DTOs\IntentResultDTO;
use App\Modules\Shared\DTOs\LeadProfileDTO;
use App\Modules\Shared\DTOs\TenantConfigDTO;
use App\Modules\Shared\DTOs\TenantDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\TenantConfig\Services\BusinessHoursService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecisionEngineServiceTest extends TestCase
{
    use RefreshDatabase;

    private DecisionEngineService $engine;
    private MockLlmAdapter $mockLlm;
    private BusinessHoursService $mockBusiness;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockLlm = new MockLlmAdapter();
        $this->app->instance(LlmClientInterface::class, $this->mockLlm);

        // Default: business is open
        $this->mockBusiness = $this->createMock(BusinessHoursService::class);
        $this->mockBusiness->method('isOpen')->willReturn(true);
        $this->mockBusiness->method('getAfterHoursBehavior')->willReturn('queue');

        $this->engine = new DecisionEngineService($this->mockBusiness);
    }

    // ──────────────────────────────────────────────────────────────
    // Intent → Action mapping
    // ──────────────────────────────────────────────────────────────

    public function test_greeting_intent_returns_send_greeting_action(): void
    {
        $context = $this->makeContext('greeting');

        $result = $this->engine->decide($context);

        $this->assertSame('proceed', $result->decision);
        $this->assertContains('send_greeting', $result->desired_actions);
        $this->assertSame(ConversationStage::NEW_LEAD->value, $context->state->stage->value);
        $this->assertNull($result->stage_transition);
    }

    public function test_ask_price_returns_send_price_info(): void
    {
        $context = $this->makeContext('ask_price');

        $result = $this->engine->decide($context);

        $this->assertContains('send_price_info', $result->desired_actions);
        $this->assertSame('send_price_breakdown', $result->reply_strategy);
    }

    public function test_ask_package_detail_with_package_slug_returns_send_package_detail(): void
    {
        $context = $this->makeContext('ask_package_detail', entities: ['package_slug' => 'standard']);

        $result = $this->engine->decide($context);

        $this->assertContains('send_package_detail', $result->desired_actions);
    }

    public function test_ask_package_detail_without_package_slug_returns_clarification(): void
    {
        $context = $this->makeContext('ask_package_detail');

        $result = $this->engine->decide($context);

        $this->assertContains('ask_package_clarification', $result->desired_actions);
    }

    public function test_ask_availability_returns_check_and_send(): void
    {
        $context = $this->makeContext('ask_availability');

        $result = $this->engine->decide($context);

        $this->assertContains('check_availability', $result->desired_actions);
        $this->assertContains('send_availability', $result->desired_actions);
    }

    // ──────────────────────────────────────────────────────────────
    // Handoff triggers
    // ──────────────────────────────────────────────────────────────

    public function test_handoff_request_intent_triggers_handoff_with_medium_priority(): void
    {
        $context = $this->makeContext('handoff_request');

        $result = $this->engine->decide($context);

        $this->assertSame('handoff', $result->decision);
        $this->assertTrue($result->handoff_required);
        $this->assertSame('medium', $result->handoff_priority->value);
        $this->assertSame('send_handoff_message', $result->reply_strategy);
    }

    public function test_kata_kasar_second_time_triggers_urgent_handoff(): void
    {
        // priorAbuseCount = 1 (first kasar already recorded)
        $context = $this->makeContext(
            'unclear_message',
            body: 'kamu bajingan sekali',
            stateEntities: ['abusive_count' => 1],
        );

        $result = $this->engine->decide($context);

        $this->assertSame('handoff', $result->decision);
        $this->assertTrue($result->handoff_required);
        $this->assertSame('urgent', $result->handoff_priority->value);
        $this->assertStringContainsString('abusive', $result->handoff_reason);
    }

    public function test_kata_kasar_first_time_does_not_trigger_handoff(): void
    {
        // priorAbuseCount = 0 (no prior abuse)
        $context = $this->makeContext(
            'unclear_message',
            body: 'kamu bodoh sekali',
        );

        $result = $this->engine->decide($context);

        $this->assertSame('proceed', $result->decision);
        $this->assertFalse($result->handoff_required);
    }

    public function test_ancaman_triggers_urgent_handoff_immediately(): void
    {
        $context = $this->makeContext(
            'unclear_message',
            body: 'saya akan somasi kalian besok',
        );

        $result = $this->engine->decide($context);

        $this->assertSame('handoff', $result->decision);
        $this->assertSame('urgent', $result->handoff_priority->value);
    }

    public function test_out_of_scope_three_times_triggers_low_priority_handoff(): void
    {
        $context = $this->makeContext(
            'out_of_scope',
            stateEntities: ['out_of_scope_count' => 2],
        );

        $result = $this->engine->decide($context);

        $this->assertSame('handoff', $result->decision);
        $this->assertSame('low', $result->handoff_priority->value);
    }

    // ──────────────────────────────────────────────────────────────
    // Stage machine transitions
    // ──────────────────────────────────────────────────────────────

    public function test_new_lead_ask_price_transitions_to_exploration(): void
    {
        $context = $this->makeContext('ask_price', stage: ConversationStage::NEW_LEAD);

        $result = $this->engine->decide($context);

        $this->assertSame(ConversationStage::EXPLORATION->value, $result->stage_transition);
    }

    public function test_new_lead_greeting_stays_new_lead(): void
    {
        $context = $this->makeContext('greeting', stage: ConversationStage::NEW_LEAD);

        $result = $this->engine->decide($context);

        $this->assertNull($result->stage_transition);
    }

    public function test_exploration_with_guest_count_and_event_date_transitions_to_qualification(): void
    {
        $context = $this->makeContext(
            'ask_price',
            stage: ConversationStage::EXPLORATION,
            entities: ['guest_count' => 200, 'event_date' => '2026-06-15'],
        );

        $result = $this->engine->decide($context);

        $this->assertSame(ConversationStage::QUALIFICATION->value, $result->stage_transition);
    }

    public function test_qualification_with_package_slug_transitions_to_recommendation(): void
    {
        $context = $this->makeContext(
            'ask_package_detail',
            stage: ConversationStage::QUALIFICATION,
            entities: ['package_slug' => 'standard'],
        );

        $result = $this->engine->decide($context);

        $this->assertSame(ConversationStage::RECOMMENDATION->value, $result->stage_transition);
    }

    public function test_consideration_confirm_booking_transitions_to_booking(): void
    {
        $context = $this->makeContext('confirm_booking', stage: ConversationStage::CONSIDERATION);

        $result = $this->engine->decide($context);

        $this->assertSame(ConversationStage::BOOKING->value, $result->stage_transition);
    }

    // ──────────────────────────────────────────────────────────────
    // After hours
    // ──────────────────────────────────────────────────────────────

    public function test_after_hours_returns_after_hours_decision(): void
    {
        $closedBusiness = $this->createMock(BusinessHoursService::class);
        $closedBusiness->method('isOpen')->willReturn(false);
        $closedBusiness->method('getAfterHoursBehavior')->willReturn('queue');

        $engine = new DecisionEngineService($closedBusiness);

        $context = $this->makeContext('greeting');

        $result = $engine->decide($context);

        $this->assertSame('after_hours', $result->decision);
        $this->assertSame('send_after_hours_reply', $result->reply_strategy);
        $this->assertFalse($result->handoff_required);
    }

    public function test_after_hours_with_ignore_behavior_proceeds_normally(): void
    {
        $ignoreBusiness = $this->createMock(BusinessHoursService::class);
        $ignoreBusiness->method('isOpen')->willReturn(false);
        $ignoreBusiness->method('getAfterHoursBehavior')->willReturn('ignore');

        $engine = new DecisionEngineService($ignoreBusiness);

        $context = $this->makeContext('greeting');

        $result = $engine->decide($context);

        $this->assertSame('proceed', $result->decision);
    }

    // ──────────────────────────────────────────────────────────────
    // PRINSIP 1 — Zero LLM calls
    // ──────────────────────────────────────────────────────────────

    public function test_decision_engine_makes_zero_llm_calls(): void
    {
        $this->mockLlm->reset();

        $contexts = [
            $this->makeContext('greeting'),
            $this->makeContext('ask_price'),
            $this->makeContext('handoff_request'),
            $this->makeContext('confirm_booking', stage: ConversationStage::CONSIDERATION),
        ];

        foreach ($contexts as $context) {
            $this->engine->decide($context);
        }

        $this->assertSame(0, $this->mockLlm->getCallCount(), 'DecisionEngine MUST NOT call LLM (PRINSIP 1)');
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeContext(
        string $intent,
        ConversationStage $stage = ConversationStage::NEW_LEAD,
        array $entities = [],
        array $stateEntities = [],
        string $body = 'test message',
    ): TurnContextDTO {
        return new TurnContextDTO(
            tenant: TenantDTO::from([
                'id'            => 'tenant-test-001',
                'name'          => 'Demo Tenant',
                'slug'          => 'demo',
                'status'        => 'active',
                'industry'      => 'wedding',
                'contact_email' => 'demo@example.com',
            ]),
            conversation: ConversationDTO::from([
                'id'            => 'conv-test-001',
                'tenant_id'     => 'tenant-test-001',
                'wa_account_id' => 'wa-001',
                'from_phone'    => '+628121234567',
                'stage'         => $stage->value,
                'agent_mode'    => AgentMode::ACTIVE->value,
                'memory_mode'   => MemoryMode::ACTIVE->value,
                'context_summary' => null,
                'created_at'    => now()->toISOString(),
                'updated_at'    => now()->toISOString(),
            ]),
            state: ConversationStateDTO::from([
                'stage'           => $stage->value,
                'agent_mode'      => AgentMode::ACTIVE->value,
                'memory_mode'     => MemoryMode::ACTIVE->value,
                'lead_temperature' => LeadTemperature::COLD->value,
                'entities'        => $stateEntities,
                'turn_count'      => 1,
                'last_intent'     => null,
            ]),
            lead: LeadProfileDTO::from([
                'id'                 => 'lead-test-001',
                'tenant_id'          => 'tenant-test-001',
                'phone'              => '+628121234567',
                'name'               => null,
                'temperature'        => LeadTemperature::COLD->value,
                'entities'           => $entities,
                'conversation_count' => 1,
                'last_seen_at'       => null,
            ]),
            intent: IntentResultDTO::from([
                'intent'       => $intent,
                'confidence'   => 0.95,
                'reason'       => 'test',
                'raw_response' => '{}',
            ]),
            entities: EntityResultDTO::from([
                'entities'            => $entities,
                'corrections'         => [],
                'previous_references' => [],
                'confidence'          => 0.90,
                'needs_clarification' => [],
                'detected_language'   => 'id',
            ]),
            knowledge: GroundedKnowledgeDTO::from([
                'structured_data' => [],
                'vector_results'  => [],
                'grounding_refs'  => [],
                'search_method'   => 'tsvector',
            ]),
            config: TenantConfigDTO::from([
                'tenant_id'            => 'tenant-test-001',
                'tone'                 => 'semi_formal',
                'timezone'             => 'Asia/Jakarta',
                'business_hours_start' => '08:00',
                'business_hours_end'   => '21:00',
                'policies'             => [],
                'features'             => [],
            ]),
            inbound_message: InboundMessageDTO::from([
                'wa_account_id'       => 'wa-001',
                'provider_message_id' => 'msg-test-001',
                'from_phone'          => '+628121234567',
                'message_type'        => 'text',
                'body'                => $body,
                'media_url'           => null,
                'raw_payload'         => [],
                'received_at'         => now()->toISOString(),
            ]),
            is_sanitized:     true,
            injection_detected: false,
        );
    }
}
