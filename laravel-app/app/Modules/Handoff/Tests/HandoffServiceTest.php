<?php

namespace App\Modules\Handoff\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Handoff\Models\HandoffRecord;
use App\Modules\Handoff\Repositories\HandoffRepository;
use App\Modules\Handoff\Services\HandoffService;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\Shared\Enums\HandoffStatus;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HandoffServiceTest extends TestCase
{
    use RefreshDatabase;

    private HandoffService $service;
    private HandoffRepository $repository;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(HandoffRepository::class);
        $this->service    = app(HandoffService::class);

        $superadmin    = $this->makeSuperadmin();
        $this->actingAs($superadmin);
        $this->tenantA = $this->makeTenant('Tenant A', $superadmin);
        $this->tenantB = $this->makeTenant('Tenant B', $superadmin);
    }

    // ── triggerHandoff ────────────────────────────────────────────────────

    public function test_trigger_handoff_creates_pending_record(): void
    {
        $conv     = $this->makeConversation($this->tenantA->id);
        $decision = $this->makeDecision('medium');

        $record = $this->service->triggerHandoff($conv, $decision);

        $this->assertInstanceOf(HandoffRecord::class, $record);
        $this->assertEquals(HandoffStatus::PENDING, $record->status);
        $this->assertEquals($conv->id, $record->conversation_id);
        $this->assertEquals($this->tenantA->id, $record->tenant_id);
    }

    public function test_trigger_handoff_sets_conversation_agent_mode_to_handoff(): void
    {
        $conv     = $this->makeConversation($this->tenantA->id);
        $decision = $this->makeDecision('medium');

        $this->service->triggerHandoff($conv, $decision);

        $conv->refresh();
        $this->assertEquals(AgentMode::HANDOFF, $conv->agent_mode);
    }

    public function test_trigger_handoff_with_urgent_priority(): void
    {
        $conv     = $this->makeConversation($this->tenantA->id);
        $decision = $this->makeDecision('urgent', 'customer angry twice');

        $record = $this->service->triggerHandoff($conv, $decision);

        $this->assertEquals(HandoffPriority::URGENT, $record->priority);
        $this->assertEquals('customer angry twice', $record->reason);
    }

    // ── resolveHandoff ────────────────────────────────────────────────────

    public function test_resolve_handoff_with_resume_ai_sets_conversation_to_active(): void
    {
        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->service->triggerHandoff($conv, $this->makeDecision());

        $this->service->resolveHandoff($record, 'Customer satisfied', resumeAI: true);

        $conv->refresh();
        $this->assertEquals(AgentMode::ACTIVE, $conv->agent_mode);

        $record->refresh();
        $this->assertEquals(HandoffStatus::RESOLVED, $record->status);
        $this->assertEquals('Customer satisfied', $record->resolution_notes);
        $this->assertNotNull($record->resolved_at);
    }

    public function test_resolve_handoff_without_resume_ai_sets_conversation_to_limited(): void
    {
        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->service->triggerHandoff($conv, $this->makeDecision());

        $this->service->resolveHandoff($record, 'Handled manually', resumeAI: false);

        $conv->refresh();
        $this->assertEquals(AgentMode::LIMITED, $conv->agent_mode);
    }

    // ── getActiveHandoffs ────────────────────────────────────────────────

    public function test_get_active_handoffs_returns_only_pending_and_in_progress(): void
    {
        $convA1 = $this->makeConversation($this->tenantA->id);
        $convA2 = $this->makeConversation($this->tenantA->id, phone: '+628999000002');
        $convA3 = $this->makeConversation($this->tenantA->id, phone: '+628999000003');

        $r1 = $this->service->triggerHandoff($convA1, $this->makeDecision());
        $r2 = $this->service->triggerHandoff($convA2, $this->makeDecision());
        $r3 = $this->service->triggerHandoff($convA3, $this->makeDecision());

        // Resolve one
        $this->service->resolveHandoff($r3, 'Done');

        $active = $this->service->getActiveHandoffs($this->tenantA->id);

        $this->assertCount(2, $active);
        $this->assertTrue($active->contains('id', $r1->id));
        $this->assertTrue($active->contains('id', $r2->id));
        $this->assertFalse($active->contains('id', $r3->id));
    }

    public function test_count_active_by_tenant_returns_correct_count(): void
    {
        $convA1 = $this->makeConversation($this->tenantA->id);
        $convA2 = $this->makeConversation($this->tenantA->id, phone: '+628999000002');

        $this->service->triggerHandoff($convA1, $this->makeDecision());
        $this->service->triggerHandoff($convA2, $this->makeDecision());

        $this->assertEquals(2, $this->repository->countActiveByTenant($this->tenantA->id));
        $this->assertEquals(0, $this->repository->countActiveByTenant($this->tenantB->id));
    }

    // ── assignHandoff ────────────────────────────────────────────────────

    public function test_assign_handoff_sets_status_in_progress_and_assigned_to(): void
    {
        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->service->triggerHandoff($conv, $this->makeDecision());
        $admin  = $this->makeAdminUser($this->tenantA->id);

        $this->service->assignHandoff($record, $admin->id);

        $record->refresh();
        $this->assertEquals(HandoffStatus::IN_PROGRESS, $record->status);
        $this->assertEquals($admin->id, $record->assigned_to);
    }

    // ── tenant isolation ─────────────────────────────────────────────────

    public function test_tenant_isolation_handoffs_tenant_a_not_visible_to_tenant_b(): void
    {
        $convA = $this->makeConversation($this->tenantA->id);
        $this->service->triggerHandoff($convA, $this->makeDecision());

        $activeB = $this->service->getActiveHandoffs($this->tenantB->id);
        $this->assertCount(0, $activeB);

        $activeA = $this->service->getActiveHandoffs($this->tenantA->id);
        $this->assertCount(1, $activeA);
    }

    // ── HandoffRecord model ──────────────────────────────────────────────

    public function test_handoff_record_is_active_returns_true_for_pending_and_in_progress(): void
    {
        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->service->triggerHandoff($conv, $this->makeDecision());

        $this->assertTrue($record->isActive());

        $admin = $this->makeAdminUser($this->tenantA->id);
        $record->assign($admin->id);
        $record->refresh();
        $this->assertTrue($record->isActive());

        $record->resolve('done');
        $record->refresh();
        $this->assertFalse($record->isActive());
    }

    // ── HandoffRepository ────────────────────────────────────────────────

    public function test_find_active_by_conversation_returns_active_record(): void
    {
        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->service->triggerHandoff($conv, $this->makeDecision());

        $found = $this->repository->findActiveByConversation($conv->id);
        $this->assertNotNull($found);
        $this->assertEquals($record->id, $found->id);
    }

    public function test_find_active_by_conversation_returns_null_after_resolve(): void
    {
        $conv   = $this->makeConversation($this->tenantA->id);
        $record = $this->service->triggerHandoff($conv, $this->makeDecision());
        $this->service->resolveHandoff($record, 'done');

        $found = $this->repository->findActiveByConversation($conv->id);
        $this->assertNull($found);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function makeDecision(
        string $priority = 'medium',
        string $reason = null,
    ): DecisionDTO {
        return DecisionDTO::from([
            'decision'             => 'handoff',
            'desired_actions'      => ['flag_handoff'],
            'allowed_actions'      => ['flag_handoff'],
            'blocked_actions'      => [],
            'handoff_required'     => true,
            'handoff_reason'       => $reason,
            'handoff_priority'     => $priority,
            'notification_required' => false,
            'reply_strategy'       => '',
            'active_goal'          => '',
            'stage_transition'     => null,
        ]);
    }

    private function makeConversation(string $tenantId, string $phone = '+628999000001'): Conversation
    {
        return Conversation::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $tenantId,
            'customer_phone' => $phone,
            'stage'          => ConversationStage::NEW_LEAD->value,
            'agent_mode'     => AgentMode::ACTIVE->value,
        ]);
    }

    private function makeSuperadmin(string $email = 'admin@platform.com'): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => $email,
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeAdminUser(string $tenantId): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Admin',
            'email'     => 'admin-' . Str::random(6) . '@tenant.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $tenantId,
            'is_active' => true,
        ]);
    }

    private function makeTenant(string $name, User $createdBy): Tenant
    {
        $slug = Str::slug($name) . '-' . Str::random(4);

        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => $name,
            'slug'          => $slug,
            'status'        => TenantStatus::TRIAL,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }
}
