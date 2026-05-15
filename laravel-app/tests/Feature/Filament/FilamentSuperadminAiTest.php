<?php

namespace Tests\Feature\Filament;

use App\Modules\AgentCore\LLM\Models\PromptTemplate;
use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use App\Modules\Auth\Models\User;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FilamentSuperadminAiTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $tenantAdmin;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'super@platform.com',
            'password'  => bcrypt('password'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $this->tenantA = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Vendor A',
            'slug'          => 'vendor-a',
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => 'vendor-a@example.com',
            'created_by_id' => $this->superadmin->id,
        ]);

        $this->tenantB = Tenant::create([
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
            'name'      => 'Admin A',
            'email'     => 'admin@vendor-a.com',
            'password'  => bcrypt('password'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $this->tenantA->id,
            'is_active' => true,
        ]);
    }

    public function test_superadmin_can_access_decision_traces_page(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/superadmin/decision-traces');

        $response->assertStatus(200);
    }

    public function test_superadmin_can_access_prompt_templates_page(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/superadmin/prompt-templates');

        $response->assertStatus(200);
    }

    public function test_superadmin_can_see_decision_traces_from_all_tenants(): void
    {
        $this->createDecisionTrace($this->tenantA->id, 'greeting');
        $this->createDecisionTrace($this->tenantB->id, 'ask_price');

        $this->actingAs($this->superadmin);

        $traces = DecisionTrace::all();
        $this->assertCount(2, $traces);

        $tenantIds = $traces->pluck('tenant_id')->unique()->sort()->values()->toArray();
        $this->assertContains($this->tenantA->id, $tenantIds);
        $this->assertContains($this->tenantB->id, $tenantIds);
    }

    public function test_superadmin_can_view_single_decision_trace(): void
    {
        $trace = $this->createDecisionTrace($this->tenantA->id, 'ask_price');

        $response = $this->actingAs($this->superadmin)
            ->get('/superadmin/decision-traces/' . $trace->id);

        $response->assertStatus(200);
    }

    public function test_superadmin_can_edit_prompt_template(): void
    {
        $template = PromptTemplate::create([
            'id'       => Str::uuid()->toString(),
            'name'     => 'intent_classifier',
            'version'  => 'v1.0',
            'template' => 'Original template content here.',
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('prompt_templates', [
            'name'      => 'intent_classifier',
            'version'   => 'v1.0',
            'is_active' => true,
        ]);

        $template->update(['template' => 'Updated template content.', 'version' => 'v1.1']);

        $this->assertDatabaseHas('prompt_templates', [
            'name'     => 'intent_classifier',
            'version'  => 'v1.1',
            'template' => 'Updated template content.',
        ]);
    }

    public function test_tenant_admin_cannot_access_superadmin_panel(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/superadmin/decision-traces');

        $response->assertStatus(403);
    }

    public function test_tenant_admin_cannot_access_superadmin_prompt_templates(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/superadmin/prompt-templates');

        $response->assertStatus(403);
    }

    public function test_decision_trace_has_tenant_relationship(): void
    {
        $trace = $this->createDecisionTrace($this->tenantA->id, 'greeting');

        $trace->load('tenant');

        $this->assertEquals($this->tenantA->id, $trace->tenant->id);
        $this->assertEquals('Vendor A', $trace->tenant->name);
    }

    public function test_prompt_template_toggle_active(): void
    {
        $template = PromptTemplate::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'response_composer',
            'version'   => 'v1.0',
            'template'  => 'Compose reply template.',
            'is_active' => true,
        ]);

        $template->update(['is_active' => false]);

        $this->assertDatabaseHas('prompt_templates', [
            'id'        => $template->id,
            'is_active' => false,
        ]);

        $template->update(['is_active' => true]);

        $this->assertDatabaseHas('prompt_templates', [
            'id'        => $template->id,
            'is_active' => true,
        ]);
    }

    private function createConversation(string $tenantId): Conversation
    {
        return Conversation::withoutGlobalScopes()->create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $tenantId,
            'customer_phone' => '+628121234567',
            'stage'          => 'new_lead',
            'agent_mode'     => 'active',
            'memory_mode'    => 'active',
            'lead_temperature' => 'cold',
            'entity_cache'   => [],
            'message_count'  => 0,
        ]);
    }

    private function createDecisionTrace(string $tenantId, string $intent): DecisionTrace
    {
        $conversation = $this->createConversation($tenantId);

        return DecisionTrace::withoutGlobalScopes()->create([
            'id'                      => Str::uuid()->toString(),
            'tenant_id'               => $tenantId,
            'conversation_id'         => $conversation->id,
            'raw_message'             => 'Test message for ' . $intent,
            'message_type'            => 'text',
            'is_sanitized'            => true,
            'injection_detected'      => false,
            'intent'                  => $intent,
            'intent_confidence'       => 0.95,
            'intent_reason'           => 'Test reason',
            'extracted_entities'      => [],
            'grounding_refs'          => [],
            'decision'                => 'proceed',
            'desired_actions'         => ['send_greeting'],
            'allowed_actions'         => ['send_greeting'],
            'blocked_actions'         => [],
            'handoff_required'        => false,
            'validator_warnings'      => [],
            'prompt_tokens_total'     => 100,
            'completion_tokens_total' => 50,
            'detected_hallucination'  => false,
            'actions_dispatched'      => [],
            'processing_time_ms'      => 250,
        ]);
    }
}
