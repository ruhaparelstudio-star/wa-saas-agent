<?php

namespace App\Modules\Conversation\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Models\ConversationMessage;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\Lead\Models\Lead;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    private ConversationRepository $repo;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = new ConversationRepository();

        $superadmin  = $this->makeSuperadmin();
        $this->tenantA = $this->makeTenant('Vendor A', $superadmin);
        $this->tenantB = $this->makeTenant('Vendor B', $superadmin);
    }

    // ── findOrCreateByPhone ───────────────────────────────────────

    public function test_find_or_create_creates_conversation_and_lead(): void
    {
        $conv = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628121234567');

        $this->assertInstanceOf(Conversation::class, $conv);
        $this->assertEquals(ConversationStage::NEW_LEAD, $conv->stage);
        $this->assertEquals($this->tenantA->id, $conv->tenant_id);

        $lead = Lead::withoutGlobalScopes()->where('conversation_id', $conv->id)->first();
        $this->assertNotNull($lead);
        $this->assertEquals('+628121234567', $lead->customer_phone);
    }

    public function test_find_or_create_returns_same_conversation_for_same_phone(): void
    {
        $conv1 = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628121234567');
        $conv2 = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628121234567');

        $this->assertEquals($conv1->id, $conv2->id);

        $count = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->where('customer_phone', '+628121234567')
            ->count();
        $this->assertEquals(1, $count);
    }

    // ── entity_cache merge ───────────────────────────────────────

    public function test_update_entity_cache_merges_correctly(): void
    {
        $conv = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628129999001');

        $conv->updateEntityCache(['customer_name' => 'Budi']);
        $conv->refresh();
        $this->assertEquals('Budi', $conv->entity_cache['customer_name']);

        $conv->updateEntityCache(['event_date' => '2026-06-15']);
        $conv->refresh();
        $this->assertEquals('Budi', $conv->entity_cache['customer_name']);
        $this->assertEquals('2026-06-15', $conv->entity_cache['event_date']);
    }

    // ── Lead::updateFromEntities ──────────────────────────────────

    public function test_lead_update_from_entities_saves_customer_name(): void
    {
        $conv = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628129999002');
        $lead = Lead::withoutGlobalScopes()->where('conversation_id', $conv->id)->first();

        $lead->updateFromEntities(['customer_name' => 'Siti']);
        $lead->refresh();

        $this->assertEquals('Siti', $lead->customer_name);
    }

    public function test_lead_score_calculation_all_entities_present(): void
    {
        $conv = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628129999003');
        $lead = Lead::withoutGlobalScopes()->where('conversation_id', $conv->id)->first();

        $lead->updateFromEntities([
            'customer_name' => 'Andi',
            'event_date'    => '2026-07-20',
            'budget_min'    => 20000000,
            'budget_max'    => 30000000,
            'package_slug'  => 'standard',
            'guest_count'   => 200,
        ]);
        $lead->refresh();

        $this->assertEquals(100, $lead->lead_score);
    }

    public function test_lead_score_zero_when_no_entities(): void
    {
        $conv = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628129999004');
        $lead = Lead::withoutGlobalScopes()->where('conversation_id', $conv->id)->first();

        $this->assertEquals(0, $lead->lead_score);
    }

    // ── ConversationMessage::addMessage ──────────────────────────

    public function test_add_message_increments_message_count(): void
    {
        $conv = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628129999005');
        $this->assertEquals(0, $conv->message_count);

        $conv->addMessage([
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => 'halo kak',
        ]);

        $conv->refresh();
        $this->assertEquals(1, $conv->message_count);
        $this->assertNotNull($conv->last_message_at);
    }

    public function test_add_message_stores_correctly(): void
    {
        $conv = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628129999006');

        $msg = $conv->addMessage([
            'direction'    => 'inbound',
            'message_type' => 'text',
            'body'         => 'berapa harga paket?',
            'intent'       => 'ask_price',
        ]);

        $this->assertInstanceOf(ConversationMessage::class, $msg);
        $this->assertEquals('inbound', $msg->direction);
        $this->assertEquals('berapa harga paket?', $msg->body);
        $this->assertEquals('ask_price', $msg->intent);
    }

    // ── Tenant isolation ─────────────────────────────────────────

    public function test_tenant_isolation_conversations(): void
    {
        $convA = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628120000001');
        $convB = $this->repo->findOrCreateByPhone($this->tenantB->id, '+628120000002');

        $resultA = $this->repo->getRecentForTenant($this->tenantA->id);
        $resultB = $this->repo->getRecentForTenant($this->tenantB->id);

        $this->assertTrue($resultA->contains('id', $convA->id));
        $this->assertFalse($resultA->contains('id', $convB->id));

        $this->assertTrue($resultB->contains('id', $convB->id));
        $this->assertFalse($resultB->contains('id', $convA->id));
    }

    // ── Model helper methods ──────────────────────────────────────

    public function test_conversation_is_active(): void
    {
        $conv = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628120000010');
        $this->assertTrue($conv->isActive());
    }

    public function test_lead_to_lead_profile_dto(): void
    {
        $conv = $this->repo->findOrCreateByPhone($this->tenantA->id, '+628120000011');
        $lead = Lead::withoutGlobalScopes()->where('conversation_id', $conv->id)->first();

        $lead->updateFromEntities(['customer_name' => 'Rina']);

        $dto = $lead->toLeadProfileDTO();

        $this->assertEquals($lead->id, $dto->id);
        $this->assertEquals('+628120000011', $dto->phone);
        $this->assertEquals('Rina', $dto->name);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin-conv-' . Str::random(4) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(string $name, User $createdBy): Tenant
    {
        $slug = Str::slug($name) . '-' . Str::random(6);

        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => $name,
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }
}
