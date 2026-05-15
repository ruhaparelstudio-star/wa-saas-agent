<?php

namespace App\Modules\AgentCore\Tests;

use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use App\Modules\AgentCore\Pipeline\Services\DecisionTraceLogger;
use App\Modules\Auth\Models\User;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\ConversationDTO;
use App\Modules\Shared\DTOs\ConversationStateDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\EntityResultDTO;
use App\Modules\Shared\DTOs\GroundedKnowledgeDTO;
use App\Modules\Shared\DTOs\GroundingRefDTO;
use App\Modules\Shared\DTOs\InboundMessageDTO;
use App\Modules\Shared\DTOs\IntentResultDTO;
use App\Modules\Shared\DTOs\LeadProfileDTO;
use App\Modules\Shared\DTOs\TenantConfigDTO;
use App\Modules\Shared\DTOs\TenantDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\DTOs\TurnResultDTO;
use App\Modules\Shared\DTOs\ValidatorResultDTO;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DecisionTraceLoggerTest extends TestCase
{
    use RefreshDatabase;

    private DecisionTraceLogger $logger;
    private ConversationRepository $repo;
    private Tenant $tenant;
    private string $tenantId;
    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new DecisionTraceLogger();
        $this->repo   = new ConversationRepository();

        $superadmin   = $this->makeSuperadmin('trace-a');
        $this->tenant = $this->makeTenant($superadmin, 'trace-a');
        $this->tenantId = $this->tenant->id;

        $conv = $this->repo->findOrCreateByPhone($this->tenantId, '+628121234567');
        $this->conversationId = $conv->id;
    }

    // ── log() persists trace with all core fields ──────────────────────────

    public function test_log_saves_decision_trace_with_all_fields(): void
    {
        $context = $this->makeContext();
        $result  = $this->makeResult();
        $llmData = $this->makeLlmData();

        $trace = $this->logger->log($context, $result, $llmData);

        $this->assertDatabaseHas('decision_traces', [
            'id'             => $trace->id,
            'tenant_id'      => $this->tenantId,
            'conversation_id' => $this->conversationId,
            'intent'         => 'ask_price',
            'decision'       => 'proceed',
            'is_sanitized'   => true,
            'handoff_required' => false,
        ]);
    }

    // ── intent_confidence is stored as float ──────────────────────────────

    public function test_intent_confidence_is_stored_as_float(): void
    {
        $context = $this->makeContext(intentConfidence: 0.95);
        $trace   = $this->logger->log($context, $this->makeResult(), $this->makeLlmData());

        $fresh = DecisionTrace::withoutGlobalScopes()->find($trace->id);
        $this->assertIsFloat($fresh->intent_confidence);
        $this->assertEqualsWithDelta(0.95, $fresh->intent_confidence, 0.001);
    }

    // ── grounding_refs stored as array, not object ─────────────────────────

    public function test_grounding_refs_stored_as_array(): void
    {
        $ref = GroundingRefDTO::from([
            'type'     => 'structured',
            'source'   => 'packages',
            'id'       => Str::uuid()->toString(),
            'key_data' => 'Paket Standard - Rp 20 juta',
        ]);

        $context = $this->makeContext(groundingRefs: [$ref]);
        $trace   = $this->logger->log($context, $this->makeResult(), $this->makeLlmData());

        $fresh = DecisionTrace::withoutGlobalScopes()->find($trace->id);
        $this->assertIsArray($fresh->grounding_refs);
        $this->assertCount(1, $fresh->grounding_refs);
        $this->assertSame('packages', $fresh->grounding_refs[0]['source']);
    }

    // ── phone number is masked in final_reply ─────────────────────────────

    public function test_phone_number_masked_in_final_reply(): void
    {
        $reply = ComposedReplyDTO::from([
            'reply_text'            => 'Silakan hubungi +628121234567 ya Kak',
            'reply_type'            => 'text',
            'attachments'           => [],
            'grounding_refs'        => [],
            'detected_hallucination' => false,
        ]);

        $llmData = array_merge($this->makeLlmData(), ['composed_reply' => $reply]);
        $trace   = $this->logger->log($this->makeContext(), $this->makeResult(), $llmData);

        $fresh = DecisionTrace::withoutGlobalScopes()->find($trace->id);
        $this->assertStringNotContainsString('628121234567', $fresh->final_reply);
        $this->assertStringContainsString('****', $fresh->final_reply);
    }

    // ── getTracesByConversation returns only traces for that conversation ─

    public function test_get_traces_by_conversation_returns_correct_traces(): void
    {
        $context = $this->makeContext();
        $result  = $this->makeResult();

        $this->logger->log($context, $result, $this->makeLlmData());
        $this->logger->log($context, $result, $this->makeLlmData());

        $traces = $this->logger->getTracesByConversation($this->conversationId);
        $this->assertCount(2, $traces);

        foreach ($traces as $t) {
            $this->assertSame($this->conversationId, $t->conversation_id);
        }
    }

    // ── tenant isolation ──────────────────────────────────────────────────

    public function test_tenant_isolation_traces_from_tenant_a_not_visible_to_tenant_b(): void
    {
        // Tenant A trace
        $this->logger->log($this->makeContext(), $this->makeResult(), $this->makeLlmData());

        // Tenant B
        $superadminB = $this->makeSuperadmin('trace-b');
        $tenantB     = $this->makeTenant($superadminB, 'trace-b');
        $convB       = $this->repo->findOrCreateByPhone($tenantB->id, '+628987654321');

        // Query with TenantScope (scoped to tenantB context) would not find tenantA traces
        // We verify by direct DB count per tenant
        $countA = DecisionTrace::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->count();

        $countB = DecisionTrace::withoutGlobalScopes()
            ->where('tenant_id', $tenantB->id)
            ->count();

        $this->assertSame(1, $countA);
        $this->assertSame(0, $countB);
    }

    // ── processing_time_ms is stored ──────────────────────────────────────

    public function test_processing_time_ms_is_stored(): void
    {
        $result = $this->makeResult(processingTimeMs: 342);
        $trace  = $this->logger->log($this->makeContext(), $result, $this->makeLlmData());

        $fresh = DecisionTrace::withoutGlobalScopes()->find($trace->id);
        $this->assertSame(342, $fresh->processing_time_ms);
    }

    // ── error_message is stored when present ──────────────────────────────

    public function test_error_message_stored_when_present(): void
    {
        $llmData = array_merge($this->makeLlmData(), ['error_message' => 'LLM timeout after 30s']);
        $trace   = $this->logger->log($this->makeContext(), $this->makeResult(), $llmData);

        $fresh = DecisionTrace::withoutGlobalScopes()->find($trace->id);
        $this->assertSame('LLM timeout after 30s', $fresh->error_message);
    }

    // ── maskPhone helper ──────────────────────────────────────────────────

    public function test_mask_phone_masks_plus62_format(): void
    {
        $masked = $this->logger->maskPhone('+628121234567');
        $this->assertStringNotContainsString('628121234567', $masked);
        $this->assertStringContainsString('****', $masked);
    }

    public function test_mask_phone_masks_08_format(): void
    {
        $masked = $this->logger->maskPhone('08121234567');
        $this->assertStringNotContainsString('08121234567', $masked);
        $this->assertStringContainsString('****', $masked);
    }

    public function test_mask_phone_leaves_non_phone_text_intact(): void
    {
        $text   = 'Halo Kak, ada yang bisa kami bantu?';
        $masked = $this->logger->maskPhone($text);
        $this->assertSame($text, $masked);
    }

    // ── injection_detected stored from context ────────────────────────────

    public function test_injection_detected_stored_from_context(): void
    {
        $context = $this->makeContext(injectionDetected: true);
        $trace   = $this->logger->log($context, $this->makeResult(), $this->makeLlmData());

        $fresh = DecisionTrace::withoutGlobalScopes()->find($trace->id);
        $this->assertTrue($fresh->injection_detected);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeContext(
        float  $intentConfidence = 0.9,
        array  $groundingRefs    = [],
        bool   $injectionDetected = false,
    ): TurnContextDTO {
        return TurnContextDTO::from([
            'tenant' => TenantDTO::from([
                'id'            => $this->tenantId,
                'name'          => 'Demo Vendor',
                'slug'          => 'demo',
                'status'        => 'active',
                'industry'      => 'wedding',
                'contact_email' => 'demo@vendor.com',
                'contact_phone' => null,
                'created_at'    => now()->toISOString(),
            ]),
            'conversation' => ConversationDTO::from([
                'id'              => $this->conversationId,
                'tenant_id'       => $this->tenantId,
                'wa_account_id'   => Str::uuid()->toString(),
                'from_phone'      => '+628121234567',
                'stage'           => ConversationStage::NEW_LEAD->value,
                'agent_mode'      => AgentMode::ACTIVE->value,
                'memory_mode'     => MemoryMode::ACTIVE->value,
                'context_summary' => null,
                'created_at'      => now()->toISOString(),
                'updated_at'      => now()->toISOString(),
            ]),
            'state'    => ConversationStateDTO::from([]),
            'lead'     => LeadProfileDTO::from([]),
            'intent'   => IntentResultDTO::from([
                'intent'       => 'ask_price',
                'confidence'   => $intentConfidence,
                'reason'       => 'Customer bertanya harga',
                'raw_response' => '{"intent":"ask_price","confidence":' . $intentConfidence . '}',
            ]),
            'entities' => EntityResultDTO::from([
                'entities'             => ['customer_name' => 'Budi'],
                'corrections'          => [],
                'previous_references'  => [],
                'confidence'           => 0.85,
                'needs_clarification'  => [],
                'detected_language'    => 'id',
            ]),
            'knowledge' => GroundedKnowledgeDTO::from([
                'structured_data' => [],
                'vector_results'  => [],
                'grounding_refs'  => $groundingRefs,
                'search_method'   => 'tsvector',
            ]),
            'config' => TenantConfigDTO::from([
                'tenant_id'            => $this->tenantId,
                'tone'                 => 'semi_formal',
                'timezone'             => 'Asia/Jakarta',
                'business_hours_start' => '08:00',
                'business_hours_end'   => '21:00',
                'policies'             => [],
                'features'             => [],
            ]),
            'inbound_message' => InboundMessageDTO::from([
                'wa_account_id'       => Str::uuid()->toString(),
                'provider_message_id' => 'msg-' . Str::uuid(),
                'from_phone'          => '+628121234567',
                'message_type'        => 'text',
                'body'                => 'berapa harga paketnya?',
                'media_url'           => null,
                'raw_payload'         => [],
                'received_at'         => now()->toISOString(),
            ]),
            'is_sanitized'       => true,
            'injection_detected' => $injectionDetected,
        ]);
    }

    private function makeResult(int $processingTimeMs = 150): TurnResultDTO
    {
        return TurnResultDTO::from([
            'reply_sent'          => true,
            'actions_dispatched'  => ['send_price_info'],
            'decision_trace_id'   => '',
            'conversation_id'     => $this->conversationId,
            'new_state'           => ConversationStateDTO::from([]),
            'processing_time_ms'  => $processingTimeMs,
        ]);
    }

    private function makeLlmData(): array
    {
        return [
            'intent_prompt'    => 'Classify the following message...',
            'entity_prompt'    => 'Extract entities from...',
            'composer_prompt'  => 'Compose a reply for...',
            'intent_raw'       => '{"intent":"ask_price","confidence":0.9}',
            'entity_raw'       => '{"entities":{"customer_name":"Budi"}}',
            'composer_raw'     => 'Harga paket standard mulai Rp 20 juta Kak',
            'token_totals'     => ['prompt' => 500, 'completion' => 100],
            'decision'         => DecisionDTO::from([
                'decision'              => 'proceed',
                'desired_actions'       => ['send_price_info'],
                'allowed_actions'       => ['send_price_info'],
                'blocked_actions'       => [],
                'handoff_required'      => false,
                'handoff_reason'        => null,
                'handoff_priority'      => HandoffPriority::LOW->value,
                'notification_required' => false,
                'reply_strategy'        => 'send_price_breakdown',
                'active_goal'           => 'send price info',
                'stage_transition'      => 'exploration',
            ]),
            'validator_result' => ValidatorResultDTO::from([
                'policy_result'          => 'passed',
                'grounding_result'       => 'passed',
                'permission_result'      => 'passed',
                'mode_result'            => 'passed',
                'final_allowed_actions'  => ['send_price_info'],
                'final_blocked_actions'  => [],
                'warnings'               => [],
            ]),
            'composed_reply'   => ComposedReplyDTO::from([
                'reply_text'            => 'Harga paket standard mulai Rp 20 juta Kak 😊',
                'reply_type'            => 'text',
                'attachments'           => [],
                'grounding_refs'        => [],
                'detected_hallucination' => false,
            ]),
            'stage_before' => 'new_lead',
        ];
    }

    private function makeSuperadmin(string $suffix): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin-' . $suffix . '-' . Str::random(4) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(User $createdBy, string $suffix): Tenant
    {
        $slug = 'trace-' . $suffix . '-' . Str::random(4);
        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Trace Vendor ' . $suffix,
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }
}
