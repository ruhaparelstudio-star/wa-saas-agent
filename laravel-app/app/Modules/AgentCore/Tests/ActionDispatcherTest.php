<?php

namespace App\Modules\AgentCore\Tests;

use App\Modules\AgentCore\Pipeline\Services\ActionDispatcher;
use App\Modules\Auth\Models\User;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Models\ConversationMessage;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Lead\Models\Lead;
use App\Modules\Shared\Contracts\ChannelGatewayInterface;
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
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ActionDispatcherTest extends TestCase
{
    use RefreshDatabase;

    private ConversationRepository $repo;
    private Tenant $tenant;
    private Conversation $conversation;
    private string $tenantId;
    private string $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = new ConversationRepository();

        $superadmin     = $this->makeSuperadmin();
        $this->tenant   = $this->makeTenant($superadmin);
        $this->tenantId = $this->tenant->id;

        $this->conversation   = $this->repo->findOrCreateByPhone($this->tenantId, '+628121234567');
        $this->conversationId = $this->conversation->id;
    }

    // ── Outbound message persisted ─────────────────────────────────────────

    public function test_dispatch_with_reply_saves_outbound_message(): void
    {
        $dispatcher = $this->makeDispatcher(gateway: null);

        $dispatcher->dispatch(
            $this->makeContext(),
            $this->makeReply('Halo Kak! Tersedia ya 😊'),
            $this->makeDecision(),
        );

        $outbound = ConversationMessage::withoutGlobalScopes()
            ->where('conversation_id', $this->conversationId)
            ->where('direction', 'outbound')
            ->first();

        $this->assertNotNull($outbound);
        $this->assertSame('Halo Kak! Tersedia ya 😊', $outbound->body);
    }

    // ── Stage transition ───────────────────────────────────────────────────

    public function test_dispatch_with_stage_transition_updates_conversation_stage(): void
    {
        $dispatcher = $this->makeDispatcher(gateway: null);

        $dispatcher->dispatch(
            $this->makeContext(),
            $this->makeReply('Baik Kak!'),
            $this->makeDecision(stageTransition: 'exploration'),
        );

        $conv = Conversation::withoutGlobalScopes()->find($this->conversationId);
        $this->assertSame(ConversationStage::EXPLORATION, $conv->stage);
    }

    // ── Lead update ────────────────────────────────────────────────────────

    public function test_dispatch_update_lead_action_updates_customer_name(): void
    {
        $dispatcher = $this->makeDispatcher(gateway: null);

        $dispatcher->dispatch(
            $this->makeContext(entities: ['customer_name' => 'Budi']),
            $this->makeReply('Halo Kak Budi!'),
            $this->makeDecision(desiredActions: ['update_lead']),
        );

        $lead = Lead::withoutGlobalScopes()->where('conversation_id', $this->conversationId)->first();
        $this->assertSame('Budi', $lead->customer_name);
    }

    // ── Gateway down — no exception ────────────────────────────────────────

    public function test_gateway_not_reachable_does_not_throw(): void
    {
        $gateway = $this->createMock(ChannelGatewayInterface::class);
        $gateway->method('sendText')->willThrowException(new \RuntimeException('Connection refused'));

        $dispatcher = $this->makeDispatcher(gateway: $gateway);

        $result = $dispatcher->dispatch(
            $this->makeContext(),
            $this->makeReply('Test reply'),
            $this->makeDecision(),
        );

        // Should not throw; sendReply returns false
        $this->assertIsArray($result);
    }

    // ── sendReply returns false when gateway is null ───────────────────────

    public function test_send_reply_returns_false_when_no_gateway(): void
    {
        $dispatcher = $this->makeDispatcher(gateway: null);
        $result     = $dispatcher->sendReply($this->makeContext(), $this->makeReply('Hi'));

        $this->assertFalse($result);
    }

    // ── sendReply returns true when gateway succeeds ───────────────────────

    public function test_send_reply_returns_true_when_gateway_succeeds(): void
    {
        $gateway = $this->createMock(ChannelGatewayInterface::class);
        $gateway->method('sendText')->willReturn(true);

        $dispatcher = $this->makeDispatcher(gateway: $gateway);
        $result     = $dispatcher->sendReply($this->makeContext(), $this->makeReply('Hi'));

        $this->assertTrue($result);
    }

    // ── Dispatched array contains action names ─────────────────────────────

    public function test_dispatched_array_contains_action_names(): void
    {
        $dispatcher = $this->makeDispatcher(gateway: null);

        $result = $dispatcher->dispatch(
            $this->makeContext(),
            $this->makeReply('Baik Kak!'),
            $this->makeDecision(desiredActions: ['send_greeting']),
        );

        $this->assertIsArray($result);
        $this->assertContains('send_greeting', $result);
    }

    // ── Handoff flag sets agent_mode ───────────────────────────────────────

    public function test_flag_handoff_action_sets_agent_mode_to_handoff(): void
    {
        $dispatcher = $this->makeDispatcher(gateway: null);

        $dispatcher->dispatch(
            $this->makeContext(),
            $this->makeReply('Kami teruskan ke tim Kak.'),
            $this->makeDecision(desiredActions: ['flag_handoff']),
        );

        $conv = Conversation::withoutGlobalScopes()->find($this->conversationId);
        $this->assertSame(AgentMode::HANDOFF, $conv->agent_mode);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeDispatcher(?ChannelGatewayInterface $gateway): ActionDispatcher
    {
        return new ActionDispatcher($gateway, $this->repo);
    }

    private function makeContext(array $entities = []): TurnContextDTO
    {
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
            'state'           => ConversationStateDTO::from([]),
            'lead'            => LeadProfileDTO::from([]),
            'intent'          => IntentResultDTO::from([
                'intent'       => 'ask_price',
                'confidence'   => 0.9,
                'reason'       => 'test',
                'raw_response' => '{}',
            ]),
            'entities'        => EntityResultDTO::from(['entities' => $entities]),
            'knowledge'       => GroundedKnowledgeDTO::from([
                'structured_data' => [],
                'vector_results'  => [],
                'grounding_refs'  => [],
                'search_method'   => 'tsvector',
            ]),
            'config'          => TenantConfigDTO::from([
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
                'body'                => 'berapa harga?',
                'media_url'           => null,
                'raw_payload'         => [],
                'received_at'         => now()->toISOString(),
            ]),
            'is_sanitized'       => true,
            'injection_detected' => false,
        ]);
    }

    private function makeReply(string $text = 'Halo Kak!'): ComposedReplyDTO
    {
        return ComposedReplyDTO::from([
            'reply_text'            => $text,
            'reply_type'            => 'text',
            'attachments'           => [],
            'grounding_refs'        => [],
            'detected_hallucination' => false,
        ]);
    }

    private function makeDecision(
        ?string $stageTransition = null,
        array   $desiredActions  = ['send_greeting'],
    ): DecisionDTO {
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
            'stage_transition'      => $stageTransition,
        ]);
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin-disp-' . Str::random(4) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(User $createdBy): Tenant
    {
        $slug = 'disp-' . Str::random(6);
        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Dispatch Vendor',
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }
}
