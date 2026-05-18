<?php

namespace Tests\Unit\QualityGuard;

use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\QualityGuard\Rules\AvailabilityHallucinationRule;
use App\Modules\QualityGuard\Rules\HandoffPromiseWithoutRecordRule;
use App\Modules\QualityGuard\Rules\PriceHallucinationRule;
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
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Shared\Enums\MemoryMode;
use PHPUnit\Framework\TestCase;

class RulesTest extends TestCase
{
    public function test_availability_hallucination_fires_for_date_claim_without_data(): void
    {
        $rule = new AvailabilityHallucinationRule();
        $context = $this->makeContext(structured: []);
        $reply = $this->makeReply('Tanggal 20 Mei 2026 tersedia, Kak.');

        $violation = $rule->evaluate($context, $this->makeDecision(), $reply);

        $this->assertNotNull($violation);
        $this->assertSame(QualityIssueCode::AVAILABILITY_HALLUCINATION, $violation->code);
        $this->assertSame(QualitySeverity::CRITICAL, $violation->severity);
    }

    public function test_availability_hallucination_no_fire_for_package_availability(): void
    {
        $rule = new AvailabilityHallucinationRule();
        // "Paket Standard tersedia" — no date context, just package talk.
        $context = $this->makeContext(structured: ['packages' => [['name' => 'Standard']]]);
        $reply = $this->makeReply('Paket Standard tersedia ya Kak.');

        $this->assertNull($rule->evaluate($context, $this->makeDecision(), $reply));
    }

    public function test_availability_hallucination_no_fire_when_data_present(): void
    {
        $rule = new AvailabilityHallucinationRule();
        $context = $this->makeContext(structured: ['availability' => ['date' => '2026-05-20', 'is_available' => true]]);
        $reply = $this->makeReply('Tanggal 20 Mei tersedia ya Kak.');

        $this->assertNull($rule->evaluate($context, $this->makeDecision(), $reply));
    }

    public function test_price_hallucination_fires_when_no_packages(): void
    {
        $rule = new PriceHallucinationRule();
        $context = $this->makeContext(structured: []);
        $reply = $this->makeReply('Paket kami Rp 5.000.000 ya Kak.');

        $violation = $rule->evaluate($context, $this->makeDecision(), $reply);

        $this->assertNotNull($violation);
        $this->assertSame(QualitySeverity::CRITICAL, $violation->severity);
    }

    public function test_handoff_promise_fires_when_no_flag_handoff_action(): void
    {
        $rule = new HandoffPromiseWithoutRecordRule();
        $context = $this->makeContext();
        $decision = $this->makeDecision(actions: ['send_acknowledgement']);
        $reply = $this->makeReply('Sip Kak, tim sales akan follow up Kakak ya 🙏');

        $violation = $rule->evaluate($context, $decision, $reply);

        $this->assertNotNull($violation);
        $this->assertSame(QualitySeverity::CRITICAL, $violation->severity);
    }

    public function test_handoff_promise_no_fire_when_flag_handoff_present(): void
    {
        $rule = new HandoffPromiseWithoutRecordRule();
        $context = $this->makeContext();
        $decision = $this->makeDecision(actions: ['send_acknowledgement', 'flag_handoff']);
        $reply = $this->makeReply('Sip Kak, tim sales akan follow up Kakak ya 🙏');

        $this->assertNull($rule->evaluate($context, $decision, $reply));
    }

    private function makeContext(array $structured = [], string $agentMode = 'active'): TurnContextDTO
    {
        return TurnContextDTO::from([
            'tenant'     => TenantDTO::from(['id' => 'tenant-1', 'name' => 'X', 'status' => 'active']),
            'conversation' => ConversationDTO::from([
                'id'            => 'conv-1',
                'tenant_id'     => 'tenant-1',
                'wa_account_id' => 'wa-1',
                'from_phone'    => '+628121234567',
                'agent_mode'    => $agentMode,
                'created_at'    => now()->toIso8601String(),
            ]),
            'state'      => ConversationStateDTO::from([
                'stage'        => ConversationStage::NEW_LEAD->value,
                'agent_mode'   => $agentMode,
                'memory_mode'  => MemoryMode::ACTIVE->value,
                'temperature'  => LeadTemperature::COLD->value,
                'entities'     => [],
                'message_count' => 1,
            ]),
            'lead'       => LeadProfileDTO::from([
                'id' => 'lead-1', 'tenant_id' => 'tenant-1', 'phone' => '+628121234567',
                'name' => null, 'temperature' => 'cold', 'entities' => [],
                'conversation_count' => 1, 'last_seen_at' => now()->toIso8601String(),
            ]),
            'intent'     => IntentResultDTO::from(['intent' => 'greeting', 'confidence' => 1.0, 'reason' => '', 'raw_response' => '{}']),
            'entities'   => EntityResultDTO::from(['entities' => [], 'confidence' => 1.0]),
            'knowledge'  => GroundedKnowledgeDTO::from(['structured_data' => $structured, 'vector_results' => [], 'grounding_refs' => []]),
            'config'     => TenantConfigDTO::from(['tenant_id' => 'tenant-1', 'tone' => 'semi_formal', 'timezone' => 'Asia/Jakarta', 'business_hours' => ['open' => '08:00', 'close' => '21:00'], 'feature_flags' => [], 'policy_values' => []]),
            'inbound_message' => InboundMessageDTO::from([
                'wa_account_id' => 'wa-1', 'provider_message_id' => 'm-1', 'from_phone' => '+628121234567',
                'message_type' => 'text', 'body' => 'halo', 'media_url' => null, 'raw_payload' => [],
                'received_at' => now()->toIso8601String(),
            ]),
            'is_sanitized' => true,
            'injection_detected' => false,
        ]);
    }

    private function makeDecision(array $actions = ['send_greeting']): DecisionDTO
    {
        return DecisionDTO::from([
            'decision'              => 'proceed',
            'desired_actions'       => $actions,
            'allowed_actions'       => $actions,
            'blocked_actions'       => [],
            'handoff_required'      => false,
            'handoff_reason'        => null,
            'handoff_priority'      => HandoffPriority::LOW->value,
            'notification_required' => false,
            'reply_strategy'        => 'send_grounded_reply',
            'active_goal'           => '',
            'stage_transition'      => null,
        ]);
    }

    private function makeReply(string $text): ComposedReplyDTO
    {
        return ComposedReplyDTO::from([
            'reply_text'             => $text,
            'reply_type'             => 'text',
            'attachments'            => [],
            'grounding_refs'         => [],
            'detected_hallucination' => false,
        ]);
    }
}
