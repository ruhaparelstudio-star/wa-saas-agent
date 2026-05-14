<?php

namespace App\Modules\AgentCore\Tests;

use App\Modules\AgentCore\Validators\ActionPermissionValidator;
use App\Modules\AgentCore\Validators\GroundingValidator;
use App\Modules\AgentCore\Validators\ModeValidator;
use App\Modules\AgentCore\Validators\PolicyValidator;
use App\Modules\AgentCore\Validators\ValidatorChainService;
use App\Modules\Plans\Services\FeatureGateService;
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
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Shared\Enums\MemoryMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidatorChainTest extends TestCase
{
    use RefreshDatabase;

    private PolicyValidator           $policyValidator;
    private GroundingValidator        $groundingValidator;
    private ActionPermissionValidator $actionPermissionValidator;
    private ModeValidator             $modeValidator;
    private ValidatorChainService     $chain;

    protected function setUp(): void
    {
        parent::setUp();

        $mockFeatureGate = $this->createMock(FeatureGateService::class);
        $mockFeatureGate->method('isLeadLimitUnlimited')->willReturn(true);
        $mockFeatureGate->method('getLeadLimit')->willReturn(0);

        $this->policyValidator           = new PolicyValidator($mockFeatureGate);
        $this->groundingValidator        = new GroundingValidator();
        $this->actionPermissionValidator = new ActionPermissionValidator();
        $this->modeValidator             = new ModeValidator();

        $this->chain = new ValidatorChainService(
            $this->policyValidator,
            $this->groundingValidator,
            $this->actionPermissionValidator,
            $this->modeValidator,
        );
    }

    // ──────────────────────────────────────────────────────────────
    // PolicyValidator
    // ──────────────────────────────────────────────────────────────

    public function test_policy_validator_blocks_send_price_info_when_pricelist_on_request(): void
    {
        $context  = $this->makeContext(
            actions: ['send_price_info'],
            policies: ['pricelist_mode' => 'on_request'],
        );
        $decision = $this->makeDecision(['send_price_info']);

        [$blocked, $warnings] = $this->policyValidator->validate($context, $decision);

        $this->assertNotEmpty($blocked);
        $this->assertSame('send_price_info', $blocked[0]['action']);
        $this->assertNotEmpty($warnings);
    }

    public function test_policy_validator_allows_send_price_info_when_pricelist_public(): void
    {
        $context  = $this->makeContext(
            actions: ['send_price_info'],
            policies: ['pricelist_mode' => 'public'],
        );
        $decision = $this->makeDecision(['send_price_info']);

        [$blocked, $warnings] = $this->policyValidator->validate($context, $decision);

        $this->assertEmpty(array_filter($blocked, fn($b) => $b['action'] === 'send_price_info'));
    }

    public function test_policy_validator_blocks_all_when_lead_limit_reached_and_fallback_reject(): void
    {
        $mockFeatureGate = $this->createMock(FeatureGateService::class);
        $mockFeatureGate->method('isLeadLimitUnlimited')->willReturn(false);
        // limit = 0 means count (0) >= limit (0) → blocked
        $mockFeatureGate->method('getLeadLimit')->willReturn(0);

        $policyValidator = new PolicyValidator($mockFeatureGate);

        // Use a real UUID so PostgreSQL uuid type is satisfied
        $realUuid = \Illuminate\Support\Str::uuid()->toString();

        $context  = $this->makeContext(
            actions: ['send_greeting'],
            policies: ['lead_limit_fallback' => 'reject'],
            tenantId: $realUuid,
        );
        $decision = $this->makeDecision(['send_greeting']);

        [$blocked, $warnings] = $policyValidator->validate($context, $decision);

        $blockedActions = array_column($blocked, 'action');
        $this->assertContains('*', $blockedActions, 'All actions should be blocked when limit=0 and fallback=reject');
    }

    // ──────────────────────────────────────────────────────────────
    // GroundingValidator
    // ──────────────────────────────────────────────────────────────

    public function test_grounding_validator_returns_partial_when_send_price_info_without_prices(): void
    {
        $context  = $this->makeContext(
            actions: ['send_price_info'],
            knowledge: ['structured_data' => []],
        );
        $decision = $this->makeDecision(['send_price_info']);

        $result = $this->groundingValidator->validate($context, $decision);

        $this->assertSame('partial', $result['result']);
    }

    public function test_grounding_validator_returns_passed_when_prices_available(): void
    {
        $context = $this->makeContext(
            actions: ['send_price_info'],
            knowledge: ['structured_data' => ['prices' => [['name' => 'Standard', 'price' => 5000000]]]],
        );
        $decision = $this->makeDecision(['send_price_info']);

        $result = $this->groundingValidator->validate($context, $decision);

        $this->assertSame('passed', $result['result']);
    }

    public function test_grounding_validator_returns_failed_when_send_package_detail_without_packages(): void
    {
        $context  = $this->makeContext(
            actions: ['send_package_detail'],
            knowledge: ['structured_data' => []],
        );
        $decision = $this->makeDecision(['send_package_detail']);

        $result = $this->groundingValidator->validate($context, $decision);

        $this->assertSame('failed', $result['result']);
        $this->assertTrue($result['detected_hallucination']);
    }

    public function test_grounding_validator_passes_for_send_general_reply(): void
    {
        $context  = $this->makeContext(actions: ['send_general_reply']);
        $decision = $this->makeDecision(['send_general_reply']);

        $result = $this->groundingValidator->validate($context, $decision);

        $this->assertSame('passed', $result['result']);
        $this->assertFalse($result['detected_hallucination']);
    }

    // ──────────────────────────────────────────────────────────────
    // ActionPermissionValidator
    // ──────────────────────────────────────────────────────────────

    public function test_action_permission_blocks_initiate_booking_in_exploration_stage(): void
    {
        $context  = $this->makeContext(
            actions: ['initiate_booking'],
            stage: ConversationStage::EXPLORATION,
        );
        $decision = $this->makeDecision(['initiate_booking']);

        $result = $this->actionPermissionValidator->validate($context, $decision);

        $this->assertEmpty($result['allowed']);
        $this->assertNotEmpty($result['blocked']);
        $this->assertSame('initiate_booking', $result['blocked'][0]->action);
    }

    public function test_action_permission_allows_initiate_booking_in_consideration_stage(): void
    {
        $context  = $this->makeContext(
            actions: ['initiate_booking'],
            stage: ConversationStage::CONSIDERATION,
        );
        $decision = $this->makeDecision(['initiate_booking']);

        $result = $this->actionPermissionValidator->validate($context, $decision);

        $this->assertContains('initiate_booking', $result['allowed']);
        $this->assertEmpty($result['blocked']);
    }

    public function test_action_permission_blocks_retrieve_invoice_outside_invoice_phase(): void
    {
        $context  = $this->makeContext(
            actions: ['retrieve_invoice'],
            stage: ConversationStage::EXPLORATION,
        );
        $decision = $this->makeDecision(['retrieve_invoice']);

        $result = $this->actionPermissionValidator->validate($context, $decision);

        $this->assertNotEmpty($result['blocked']);
        $this->assertSame('retrieve_invoice', $result['blocked'][0]->action);
    }

    public function test_action_permission_allows_retrieve_invoice_in_invoice_phase(): void
    {
        $context  = $this->makeContext(
            actions: ['retrieve_invoice'],
            stage: ConversationStage::INVOICE_PHASE,
        );
        $decision = $this->makeDecision(['retrieve_invoice']);

        $result = $this->actionPermissionValidator->validate($context, $decision);

        $this->assertContains('retrieve_invoice', $result['allowed']);
    }

    public function test_action_permission_blocks_actions_in_closed_stage(): void
    {
        $context  = $this->makeContext(
            actions: ['send_price_info'],
            stage: ConversationStage::CLOSED,
        );
        $decision = $this->makeDecision(['send_price_info']);

        $result = $this->actionPermissionValidator->validate($context, $decision);

        $this->assertNotEmpty($result['blocked']);
    }

    // ──────────────────────────────────────────────────────────────
    // ModeValidator
    // ──────────────────────────────────────────────────────────────

    public function test_mode_validator_blocks_all_when_agent_mode_handoff(): void
    {
        $context  = $this->makeContext(
            actions: ['send_greeting'],
            agentMode: AgentMode::HANDOFF,
        );
        $decision = $this->makeDecision(['send_greeting']);

        $result = $this->modeValidator->validate($context, $decision);

        $this->assertSame('blocked', $result['result']);
        $this->assertEmpty($result['allowed_actions']);
        $this->assertStringContainsString('tim kami', $result['message']);
    }

    public function test_mode_validator_passes_when_agent_mode_active(): void
    {
        $context  = $this->makeContext(
            actions: ['send_greeting'],
            agentMode: AgentMode::ACTIVE,
        );
        $decision = $this->makeDecision(['send_greeting']);

        $result = $this->modeValidator->validate($context, $decision);

        $this->assertSame('passed', $result['result']);
        $this->assertContains('send_greeting', $result['allowed_actions']);
    }

    public function test_mode_validator_blocks_all_when_agent_mode_paused(): void
    {
        $context  = $this->makeContext(
            actions: ['send_price_info'],
            agentMode: AgentMode::PAUSED,
        );
        $decision = $this->makeDecision(['send_price_info']);

        $result = $this->modeValidator->validate($context, $decision);

        $this->assertSame('blocked', $result['result']);
        $this->assertEmpty($result['allowed_actions']);
    }

    public function test_mode_validator_filters_limited_actions_when_agent_mode_limited(): void
    {
        $context  = $this->makeContext(
            actions: ['send_price_info', 'initiate_booking'],
            agentMode: AgentMode::LIMITED,
        );
        $decision = $this->makeDecision(['send_price_info', 'initiate_booking']);

        $result = $this->modeValidator->validate($context, $decision);

        $this->assertSame('passed', $result['result']);
        $this->assertContains('send_price_info', $result['allowed_actions']);
        $this->assertNotContains('initiate_booking', $result['allowed_actions']);
    }

    // ──────────────────────────────────────────────────────────────
    // Full chain
    // ──────────────────────────────────────────────────────────────

    public function test_run_all_returns_valid_validator_result_dto(): void
    {
        $context  = $this->makeContext(actions: ['send_greeting']);
        $decision = $this->makeDecision(['send_greeting']);

        $result = $this->chain->runAll($context, $decision);

        $this->assertSame('passed', $result->policy_result);
        $this->assertSame('passed', $result->grounding_result);
        $this->assertSame('passed', $result->permission_result);
        $this->assertSame('passed', $result->mode_result);
        $this->assertContains('send_greeting', $result->final_allowed_actions);
    }

    public function test_run_all_with_handoff_mode_blocks_all_actions(): void
    {
        $context  = $this->makeContext(
            actions: ['send_greeting'],
            agentMode: AgentMode::HANDOFF,
        );
        $decision = $this->makeDecision(['send_greeting']);

        $result = $this->chain->runAll($context, $decision);

        $this->assertSame('blocked', $result->mode_result);
        $this->assertEmpty($result->final_allowed_actions);
    }

    public function test_run_all_permission_result_is_blocked_partial_when_some_blocked(): void
    {
        $context  = $this->makeContext(
            actions: ['send_greeting', 'initiate_booking'],
            stage: ConversationStage::EXPLORATION,
        );
        $decision = $this->makeDecision(['send_greeting', 'initiate_booking']);

        $result = $this->chain->runAll($context, $decision);

        $this->assertSame('blocked_partial', $result->permission_result);
        $this->assertContains('send_greeting', $result->final_allowed_actions);
    }

    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    private function makeContext(
        array $actions = ['send_general_reply'],
        array $policies = [],
        array $knowledge = [],
        ConversationStage $stage = ConversationStage::NEW_LEAD,
        AgentMode $agentMode = AgentMode::ACTIVE,
        string $tenantId = 'tenant-test-001',
    ): TurnContextDTO {
        $structuredData = $knowledge['structured_data'] ?? [];

        return new TurnContextDTO(
            tenant: TenantDTO::from([
                'id'            => $tenantId,
                'name'          => 'Demo Tenant',
                'slug'          => 'demo',
                'status'        => 'active',
                'industry'      => 'wedding',
                'contact_email' => 'demo@example.com',
            ]),
            conversation: ConversationDTO::from([
                'id'              => 'conv-test-001',
                'tenant_id'       => $tenantId,
                'wa_account_id'   => 'wa-001',
                'from_phone'      => '+628121234567',
                'stage'           => $stage->value,
                'agent_mode'      => $agentMode->value,
                'memory_mode'     => MemoryMode::ACTIVE->value,
                'context_summary' => null,
                'created_at'      => now()->toISOString(),
                'updated_at'      => now()->toISOString(),
            ]),
            state: ConversationStateDTO::from([
                'stage'            => $stage->value,
                'agent_mode'       => $agentMode->value,
                'memory_mode'      => MemoryMode::ACTIVE->value,
                'lead_temperature' => LeadTemperature::COLD->value,
                'entities'         => [],
                'turn_count'       => 1,
                'last_intent'      => null,
            ]),
            lead: LeadProfileDTO::from([
                'id'                 => 'lead-test-001',
                'tenant_id'          => 'tenant-test-001',
                'phone'              => '+628121234567',
                'name'               => null,
                'temperature'        => LeadTemperature::COLD->value,
                'entities'           => [],
                'conversation_count' => 1,
                'last_seen_at'       => null,
            ]),
            intent: IntentResultDTO::from([
                'intent'       => 'greeting',
                'confidence'   => 0.95,
                'reason'       => 'test',
                'raw_response' => '{}',
            ]),
            entities: EntityResultDTO::from([
                'entities'            => [],
                'corrections'         => [],
                'previous_references' => [],
                'confidence'          => 0.90,
                'needs_clarification' => [],
                'detected_language'   => 'id',
            ]),
            knowledge: GroundedKnowledgeDTO::from([
                'structured_data' => $structuredData,
                'vector_results'  => [],
                'grounding_refs'  => [],
                'search_method'   => 'tsvector',
            ]),
            config: TenantConfigDTO::from([
                'tenant_id'            => $tenantId,
                'tone'                 => 'semi_formal',
                'timezone'             => 'Asia/Jakarta',
                'business_hours_start' => '08:00',
                'business_hours_end'   => '21:00',
                'policies'             => $policies,
                'features'             => [],
            ]),
            inbound_message: InboundMessageDTO::from([
                'wa_account_id'       => 'wa-001',
                'provider_message_id' => 'msg-test-001',
                'from_phone'          => '+628121234567',
                'message_type'        => 'text',
                'body'                => 'test message',
                'media_url'           => null,
                'raw_payload'         => [],
                'received_at'         => now()->toISOString(),
            ]),
            is_sanitized:      true,
            injection_detected: false,
        );
    }

    private function makeDecision(array $desiredActions): DecisionDTO
    {
        return DecisionDTO::from([
            'decision'              => 'proceed',
            'desired_actions'       => $desiredActions,
            'allowed_actions'       => $desiredActions,
            'blocked_actions'       => [],
            'handoff_required'      => false,
            'handoff_reason'        => null,
            'handoff_priority'      => HandoffPriority::LOW->value,
            'notification_required' => false,
            'reply_strategy'        => 'send_grounded_reply',
            'active_goal'           => 'test',
            'stage_transition'      => null,
        ]);
    }
}
