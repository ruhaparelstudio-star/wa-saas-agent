<?php

namespace Tests\Feature\Filament;

use App\Filament\Tenant\Pages\InboxPage;
use App\Modules\Auth\Models\User;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Models\ConversationMessage;
use App\Modules\Handoff\Models\HandoffRecord;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\Shared\Enums\HandoffStatus;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentTenantInboxTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $tenantAdmin;
    private User $superadmin;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'super@platform.com',
            'password'  => Hash::make('password'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Vendor A',
            'slug'          => 'vendor-a',
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => 'vendor-a@example.com',
            'created_by_id' => $this->superadmin->id,
        ]);

        $this->otherTenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Vendor B',
            'slug'          => 'vendor-b',
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => 'vendor-b@example.com',
            'created_by_id' => $this->superadmin->id,
        ]);

        $this->tenantAdmin = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Admin Vendor A',
            'email'     => 'admin@vendor-a.com',
            'password'  => Hash::make('password'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        $this->conversation = Conversation::withoutGlobalScopes()->create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenant->id,
            'customer_phone' => '+6281234567890',
            'customer_name'  => 'Budi Santoso',
            'stage'          => ConversationStage::EXPLORATION->value,
            'agent_mode'     => AgentMode::ACTIVE->value,
        ]);
    }

    // ─── Panel access ────────────────────────────────────────────────────

    public function test_tenant_admin_can_access_inbox_page(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/inbox');

        $response->assertStatus(200);
    }

    public function test_superadmin_cannot_access_tenant_inbox(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/app/inbox');

        $response->assertStatus(403);
    }

    public function test_unauthenticated_user_cannot_access_inbox(): void
    {
        $response = $this->get('/app/inbox');

        $response->assertRedirect();
    }

    // ─── Conversation list isolation ─────────────────────────────────────

    public function test_conversation_list_shows_only_own_tenant(): void
    {
        // Conversation for other tenant
        Conversation::withoutGlobalScopes()->create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->otherTenant->id,
            'customer_phone' => '+6289999999999',
            'stage'          => ConversationStage::NEW_LEAD->value,
            'agent_mode'     => AgentMode::ACTIVE->value,
        ]);

        $this->actingAs($this->tenantAdmin);

        // TenantScope applies: getConversations() only returns own tenant's
        $page = Livewire::actingAs($this->tenantAdmin)->test(InboxPage::class);

        $conversations = app(InboxPage::class)->getConversations();

        // After actingAs, TenantScope filters by tenant_id
        foreach ($conversations as $conv) {
            $this->assertEquals($this->tenant->id, $conv->tenant_id);
        }
    }

    public function test_closed_conversations_are_excluded_from_list(): void
    {
        Conversation::withoutGlobalScopes()->create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenant->id,
            'customer_phone' => '+6281111111111',
            'stage'          => ConversationStage::CLOSED->value,
            'agent_mode'     => AgentMode::ACTIVE->value,
        ]);

        $this->actingAs($this->tenantAdmin);

        $page = new InboxPage();
        $conversations = $page->getConversations();

        $stages = $conversations->pluck('stage')->map(fn ($s) => $s->value)->toArray();
        $this->assertNotContains(ConversationStage::CLOSED->value, $stages);
    }

    // ─── Takeover action ─────────────────────────────────────────────────

    public function test_takeover_conversation_changes_mode_to_handoff(): void
    {
        $this->actingAs($this->tenantAdmin);

        Livewire::actingAs($this->tenantAdmin)
            ->test(InboxPage::class)
            ->set('selectedConversationId', $this->conversation->id)
            ->call('takeoverConversation');

        $this->conversation->refresh();
        $this->assertEquals(AgentMode::HANDOFF, $this->conversation->agent_mode);
    }

    public function test_takeover_creates_handoff_record(): void
    {
        $this->actingAs($this->tenantAdmin);

        Livewire::actingAs($this->tenantAdmin)
            ->test(InboxPage::class)
            ->set('selectedConversationId', $this->conversation->id)
            ->call('takeoverConversation');

        $this->assertDatabaseHas('handoff_records', [
            'conversation_id' => $this->conversation->id,
            'status'          => HandoffStatus::PENDING->value,
        ]);
    }

    // ─── Resume AI action ────────────────────────────────────────────────

    public function test_resume_ai_changes_mode_to_active(): void
    {
        $this->conversation->update(['agent_mode' => AgentMode::HANDOFF->value]);

        HandoffRecord::create([
            'id'              => Str::uuid()->toString(),
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'status'          => HandoffStatus::PENDING->value,
            'priority'        => HandoffPriority::MEDIUM->value,
        ]);

        $this->actingAs($this->tenantAdmin);

        Livewire::actingAs($this->tenantAdmin)
            ->test(InboxPage::class)
            ->set('selectedConversationId', $this->conversation->id)
            ->call('resumeAI');

        $this->conversation->refresh();
        $this->assertEquals(AgentMode::ACTIVE, $this->conversation->agent_mode);
    }

    public function test_resume_ai_resolves_active_handoff_record(): void
    {
        $this->conversation->update(['agent_mode' => AgentMode::HANDOFF->value]);

        $handoff = HandoffRecord::create([
            'id'              => Str::uuid()->toString(),
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'status'          => HandoffStatus::PENDING->value,
            'priority'        => HandoffPriority::MEDIUM->value,
        ]);

        $this->actingAs($this->tenantAdmin);

        Livewire::actingAs($this->tenantAdmin)
            ->test(InboxPage::class)
            ->set('selectedConversationId', $this->conversation->id)
            ->call('resumeAI');

        $handoff->refresh();
        $this->assertEquals(HandoffStatus::RESOLVED, $handoff->status);
        $this->assertNotNull($handoff->resolved_at);
    }

    // ─── Admin reply action ──────────────────────────────────────────────

    public function test_admin_reply_saves_outbound_message(): void
    {
        $this->conversation->update(['agent_mode' => AgentMode::HANDOFF->value]);
        $this->actingAs($this->tenantAdmin);

        Livewire::actingAs($this->tenantAdmin)
            ->test(InboxPage::class)
            ->set('selectedConversationId', $this->conversation->id)
            ->set('replyText', 'Halo Kak, ada yang bisa kami bantu?')
            ->call('sendAdminReply');

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $this->conversation->id,
            'direction'       => 'outbound',
            'body'            => 'Halo Kak, ada yang bisa kami bantu?',
        ]);
    }

    public function test_admin_reply_clears_reply_text_after_send(): void
    {
        $this->conversation->update(['agent_mode' => AgentMode::HANDOFF->value]);
        $this->actingAs($this->tenantAdmin);

        $component = Livewire::actingAs($this->tenantAdmin)
            ->test(InboxPage::class)
            ->set('selectedConversationId', $this->conversation->id)
            ->set('replyText', 'Test message')
            ->call('sendAdminReply');

        $component->assertSet('replyText', '');
    }

    public function test_admin_reply_validates_empty_message(): void
    {
        $this->actingAs($this->tenantAdmin);

        Livewire::actingAs($this->tenantAdmin)
            ->test(InboxPage::class)
            ->set('selectedConversationId', $this->conversation->id)
            ->set('replyText', '')
            ->call('sendAdminReply')
            ->assertHasErrors(['replyText']);
    }

    // ─── Close conversation action ───────────────────────────────────────

    public function test_close_conversation_sets_stage_to_closed(): void
    {
        $this->actingAs($this->tenantAdmin);

        Livewire::actingAs($this->tenantAdmin)
            ->test(InboxPage::class)
            ->set('selectedConversationId', $this->conversation->id)
            ->call('closeConversation');

        $this->conversation->refresh();
        $this->assertEquals(ConversationStage::CLOSED, $this->conversation->stage);
    }

    public function test_close_conversation_clears_selected_id(): void
    {
        $this->actingAs($this->tenantAdmin);

        Livewire::actingAs($this->tenantAdmin)
            ->test(InboxPage::class)
            ->set('selectedConversationId', $this->conversation->id)
            ->call('closeConversation')
            ->assertSet('selectedConversationId', null);
    }

    // ─── Tenant isolation: actions ───────────────────────────────────────

    public function test_tenant_b_cannot_takeover_tenant_a_conversation(): void
    {
        $adminB = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Admin B',
            'email'     => 'admin@vendor-b.com',
            'password'  => Hash::make('password'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $this->otherTenant->id,
            'is_active' => true,
        ]);

        $this->actingAs($adminB);

        // The conversation belongs to tenant A. TenantScope prevents it being found.
        $component = Livewire::actingAs($adminB)
            ->test(InboxPage::class)
            ->set('selectedConversationId', $this->conversation->id)
            ->call('takeoverConversation');

        // Conversation should remain ACTIVE (not taken over by tenant B)
        $this->conversation->refresh();
        $this->assertEquals(AgentMode::ACTIVE, $this->conversation->agent_mode);

        // No handoff record should be created for this conversation by tenant B
        $this->assertDatabaseMissing('handoff_records', [
            'conversation_id' => $this->conversation->id,
            'tenant_id'       => $this->otherTenant->id,
        ]);
    }

    // ─── Navigation badge ────────────────────────────────────────────────

    public function test_navigation_badge_shows_pending_handoff_count(): void
    {
        HandoffRecord::create([
            'id'              => Str::uuid()->toString(),
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'status'          => HandoffStatus::PENDING->value,
            'priority'        => HandoffPriority::MEDIUM->value,
        ]);

        $this->actingAs($this->tenantAdmin);

        $badge = InboxPage::getNavigationBadge();

        $this->assertEquals('1', $badge);
    }
}
