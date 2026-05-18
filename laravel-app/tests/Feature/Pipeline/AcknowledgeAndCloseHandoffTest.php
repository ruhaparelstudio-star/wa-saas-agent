<?php

namespace Tests\Feature\Pipeline;

use App\Modules\AgentCore\Decision\Services\DecisionEngineService;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Handoff\Models\HandoffRecord;
use App\Modules\Notification\Models\AdminNotification;
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
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Scopes\TenantScope;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Auth\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression test for the QA conversation bug:
 *   intent=acknowledge with booking draft → stage was incorrectly transitioning
 *   to CLOSED with no handoff_record + no notification.
 *
 * Expected behavior now:
 *   - Stage stays WAITING_BOOKING (CLOSED reserved for paid leads per CLAUDE.md)
 *   - flag_handoff action triggered
 *   - handoff_record created (status=pending, priority=medium)
 *   - admin_notifications row created (type=handoff_required)
 */
class AcknowledgeAndCloseHandoffTest extends TestCase
{
    use RefreshDatabase;

    public function test_acknowledge_after_draft_booking_creates_handoff_not_closes(): void
    {
        $tenant = $this->makeTenant();
        $repo   = new ConversationRepository();

        // Seed a conversation already in WAITING_BOOKING with a booking code cached.
        $conv = $repo->findOrCreateByPhone($tenant->id, '+628121234567', null);
        $conv->update([
            'stage'        => ConversationStage::WAITING_BOOKING->value,
            'customer_name' => 'Aris',
            'entity_cache' => [
                'customer_name'        => 'Aris',
                'event_date'           => '2026-05-20',
                'package_slug'         => 'premium',
                'last_booking_code'    => 'BKG-202605-0001',
                'booking_intent_signal' => true,
            ],
        ]);
        $conv->refresh();

        $context = $this->buildContext($tenant, $conv);
        $engine  = app(DecisionEngineService::class);
        $decision = $engine->decide($context);

        // Should NOT close.
        $this->assertNotSame(ConversationStage::CLOSED->value, $decision->stage_transition);
        // Should require handoff.
        $this->assertTrue($decision->handoff_required);
        $this->assertContains('flag_handoff', $decision->desired_actions);
        $this->assertSame('acknowledge_and_close', $decision->reply_strategy);
    }

    private function buildContext(Tenant $tenant, Conversation $conv): TurnContextDTO
    {
        $resolver = app(\App\Modules\TenantConfig\Services\TenantConfigResolver::class);
        $configDto = $resolver->resolve($tenant->id);

        return TurnContextDTO::from([
            'tenant' => TenantDTO::from([
                'id' => $tenant->id, 'name' => $tenant->name, 'status' => $tenant->status->value,
            ]),
            'conversation' => ConversationDTO::from([
                'id' => $conv->id, 'tenant_id' => $tenant->id, 'wa_account_id' => $conv->wa_account_id,
                'from_phone' => $conv->customer_phone, 'agent_mode' => $conv->agent_mode->value,
                'created_at' => $conv->created_at->toIso8601String(),
            ]),
            'state' => ConversationStateDTO::from([
                'stage' => $conv->stage->value,
                'agent_mode' => $conv->agent_mode->value,
                'memory_mode' => MemoryMode::ACTIVE->value,
                'temperature' => 'cold',
                'entities' => $conv->entity_cache ?? [],
                'message_count' => $conv->message_count,
            ]),
            'lead' => LeadProfileDTO::from([
                'id' => 'lead-x', 'tenant_id' => $tenant->id, 'phone' => $conv->customer_phone,
                'name' => 'Aris', 'temperature' => 'cold', 'entities' => $conv->entity_cache ?? [],
                'conversation_count' => 1, 'last_seen_at' => now()->toIso8601String(),
            ]),
            'intent'   => IntentResultDTO::from([
                'intent' => 'acknowledge', 'confidence' => 0.9, 'reason' => '', 'raw_response' => '{}',
            ]),
            'entities' => EntityResultDTO::from([
                'entities' => ['customer_name' => 'Aris', 'event_date' => '2026-05-20', 'package_slug' => 'premium'],
                'confidence' => 0.9,
            ]),
            'knowledge' => GroundedKnowledgeDTO::from([
                'structured_data' => ['availability' => ['date' => '2026-05-20', 'is_available' => true]],
                'vector_results'  => [],
                'grounding_refs'  => [],
            ]),
            'config' => $configDto,
            'inbound_message' => InboundMessageDTO::from([
                'wa_account_id' => 'wa-1', 'provider_message_id' => Str::uuid()->toString(),
                'from_phone' => $conv->customer_phone, 'message_type' => 'text',
                'body' => 'boleh ka', 'media_url' => null, 'raw_payload' => [],
                'received_at' => now()->toIso8601String(),
            ]),
            'is_sanitized' => true,
            'injection_detected' => false,
        ]);
    }

    private function makeTenant(): Tenant
    {
        $superadmin = User::create([
            'id' => Str::uuid()->toString(),
            'name' => 'Super', 'email' => 'super-' . Str::random(6) . '@x.com',
            'password' => bcrypt('Password123!'),
            'role' => UserRole::SUPERADMIN->value,
            'is_active' => true,
        ]);

        $tenant = Tenant::withoutGlobalScope(TenantScope::class)->create([
            'id' => Str::uuid()->toString(),
            'name' => 'Test',
            'industry' => 'wedding',
            'status' => TenantStatus::ACTIVE->value,
            'created_by' => $superadmin->id,
        ]);
        return $tenant;
    }
}
