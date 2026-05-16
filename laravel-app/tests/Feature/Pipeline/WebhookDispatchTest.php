<?php

namespace Tests\Feature\Pipeline;

use App\Modules\AgentCore\Jobs\ProcessInboundMessageJob;
use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRINSIP 13 — Webhook dispatches ProcessInboundMessageJob.
 * Note: QUEUE_CONNECTION=sync means jobs run immediately in test env.
 * We verify the HTTP response; actual job execution is covered in TurnPipelineServiceTest.
 */
class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    private string $waAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        $superadmin        = $this->makeSuperadmin();
        $tenant            = $this->makeTenant($superadmin);
        $this->waAccountId = $this->makeWaAccount($tenant->id)->id;
    }

    /**
     * Webhook returns queued status and accepted flag.
     * QUEUE_CONNECTION=sync runs the job inline — we mock LLM to prevent crash.
     */
    public function test_webhook_inbound_returns_queued_status(): void
    {
        // Bind MockLlmAdapter so pipeline doesn't crash when job runs inline
        $mock = new \App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter();
        $mock->setNextResponse(json_encode(['intent' => 'greeting', 'confidence' => 0.9, 'reason' => 'test']));
        $mock->setNextResponse(json_encode(['entities' => [], 'corrections' => [], 'needs_clarification' => [], 'detected_language' => 'id', 'confidence' => 0.8]));
        $mock->setNextResponse('Halo Kak! 😊');
        app()->instance(\App\Modules\Shared\Contracts\LlmClientInterface::class, $mock);

        $msgId    = 'msg-' . Str::uuid()->toString();
        $response = $this->postJson('/webhook/inbound', [
            'wa_account_id'       => $this->waAccountId,
            'from_phone'          => '+628121234567',
            'message_type'        => 'text',
            'body'                => 'halo kak',
            'provider_message_id' => $msgId,
            'received_at'         => now()->toISOString(),
        ], ['X-Internal-Secret' => env('WA_INTERNAL_SECRET', '')]);

        $response->assertStatus(200)
                 ->assertJsonFragment(['status' => 'queued', 'accepted' => true]);
    }

    public function test_webhook_returns_message_id_in_response(): void
    {
        $mock = new \App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter();
        $mock->setNextResponse(json_encode(['intent' => 'greeting', 'confidence' => 0.9, 'reason' => 'test']));
        $mock->setNextResponse(json_encode(['entities' => [], 'corrections' => [], 'needs_clarification' => [], 'detected_language' => 'id', 'confidence' => 0.8]));
        $mock->setNextResponse('Halo Kak! 😊');
        app()->instance(\App\Modules\Shared\Contracts\LlmClientInterface::class, $mock);

        $msgId    = 'msg-' . Str::uuid()->toString();
        $response = $this->postJson('/webhook/inbound', [
            'wa_account_id'       => $this->waAccountId,
            'from_phone'          => '+628121234567',
            'message_type'        => 'text',
            'body'                => 'test message',
            'provider_message_id' => $msgId,
            'received_at'         => now()->toISOString(),
        ], ['X-Internal-Secret' => env('WA_INTERNAL_SECRET', '')]);

        $response->assertStatus(200)
                 ->assertJsonFragment(['message_id' => $msgId]);
    }

    public function test_webhook_returns_404_for_unknown_wa_account(): void
    {
        $response = $this->postJson('/webhook/inbound', [
            'wa_account_id' => Str::uuid()->toString(),
            'from_phone'    => '+628121234567',
            'message_type'  => 'text',
            'body'          => 'halo',
            'received_at'   => now()->toISOString(),
        ], ['X-Internal-Secret' => env('WA_INTERNAL_SECRET', '')]);

        $response->assertStatus(404);
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin-wh-' . Str::random(6) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(User $createdBy): Tenant
    {
        $slug = 'wh-' . Str::random(6);
        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Webhook Vendor',
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }

    private function makeWaAccount(string $tenantId): WaAccount
    {
        return WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $tenantId,
            'display_name' => 'Test WA Account',
            'status'       => 'connected',
        ]);
    }
}
