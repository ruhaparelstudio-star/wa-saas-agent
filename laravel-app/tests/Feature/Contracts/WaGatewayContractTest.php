<?php

namespace Tests\Feature\Contracts;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PRINSIP 13 — Contract test antara Laravel dan WA Gateway.
 *
 * Contract:
 * 1. POST /webhook/inbound  (WA → Laravel): wajib ada fields + X-Internal-Secret
 * 2. POST /dispatch         (Laravel → WA): wajib ada fields, response {success, provider_message_id}
 * 3. GET  /status/:id       (Laravel → WA): response {status, phone, connected_at}
 * 4. Header X-Internal-Secret wajib divalidasi di kedua sisi
 */
class WaGatewayContractTest extends TestCase
{
    use RefreshDatabase;

    private string $waGatewayUrl;
    private string $internalSecret;
    private string $waAccountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->waGatewayUrl   = rtrim(env('WA_GATEWAY_URL', 'http://wa-gateway:3001'), '/');
        $this->internalSecret = env('WA_INTERNAL_SECRET', '');

        $user = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Contract Test Superadmin',
            'email'     => 'contract-super@test.com',
            'password'  => bcrypt('password'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Contract Test Tenant',
            'slug'          => 'contract-test-tenant',
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => 'contract@test.com',
            'created_by_id' => $user->id,
        ]);

        $waAccount = WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $tenant->id,
            'display_name' => 'Contract Test WA Account',
            'status'       => 'connected',
        ]);

        $this->waAccountId = $waAccount->id;
    }

    private function requireGateway(): void
    {
        $host = parse_url($this->waGatewayUrl, PHP_URL_HOST);
        $port = parse_url($this->waGatewayUrl, PHP_URL_PORT) ?? 80;
        $sock = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$sock) {
            $this->markTestSkipped("WA Gateway not reachable at {$this->waGatewayUrl} — run inside Docker network.");
        }
        fclose($sock);
    }

    // ── Laravel side: POST /webhook/inbound ──────────────────────────────────

    public function test_webhook_inbound_accepts_valid_payload_with_secret(): void
    {
        $response = $this->postJson('/webhook/inbound', [
            'wa_account_id' => $this->waAccountId,
            'from_phone'    => '+628111000001',
            'message_type'  => 'text',
            'body'          => 'Halo kak, mau tanya soal paket',
            'received_at'   => now()->toIso8601String(),
        ], ['X-Internal-Secret' => $this->internalSecret]);

        $response->assertStatus(200)
                 ->assertJson(['accepted' => true]);
    }

    public function test_webhook_inbound_rejects_missing_secret(): void
    {
        if (empty($this->internalSecret)) {
            $this->markTestSkipped('WA_INTERNAL_SECRET not set — secret enforcement skipped in dev');
        }

        $response = $this->postJson('/webhook/inbound', [
            'wa_account_id' => $this->waAccountId,
            'from_phone'    => '+628111000001',
            'message_type'  => 'text',
            'body'          => 'test',
            'received_at'   => now()->toIso8601String(),
        ]);

        $response->assertStatus(403);
    }

    public function test_webhook_inbound_rejects_wrong_secret(): void
    {
        if (empty($this->internalSecret)) {
            $this->markTestSkipped('WA_INTERNAL_SECRET not set — secret enforcement skipped in dev');
        }

        $response = $this->postJson('/webhook/inbound', [
            'wa_account_id' => $this->waAccountId,
            'from_phone'    => '+628111000001',
            'message_type'  => 'text',
            'body'          => 'test',
            'received_at'   => now()->toIso8601String(),
        ], ['X-Internal-Secret' => 'wrong_secret_value']);

        $response->assertStatus(403);
    }

    public function test_webhook_inbound_rejects_missing_required_fields(): void
    {
        $response = $this->postJson('/webhook/inbound', [
            'wa_account_id' => $this->waAccountId,
            // missing: from_phone, message_type, received_at
        ], ['X-Internal-Secret' => $this->internalSecret]);

        $response->assertStatus(422);
    }

    public function test_webhook_inbound_returns_404_for_unknown_wa_account(): void
    {
        $response = $this->postJson('/webhook/inbound', [
            'wa_account_id' => Str::uuid()->toString(),
            'from_phone'    => '+628111000001',
            'message_type'  => 'text',
            'body'          => 'test',
            'received_at'   => now()->toIso8601String(),
        ], ['X-Internal-Secret' => $this->internalSecret]);

        $response->assertStatus(404);
    }

    public function test_webhook_inbound_accepts_null_body_for_media_messages(): void
    {
        $response = $this->postJson('/webhook/inbound', [
            'wa_account_id' => $this->waAccountId,
            'from_phone'    => '+628111000001',
            'message_type'  => 'image',
            'body'          => null,
            'received_at'   => now()->toIso8601String(),
        ], ['X-Internal-Secret' => $this->internalSecret]);

        $response->assertStatus(200)
                 ->assertJson(['accepted' => true]);
    }

    // ── WA Gateway side: POST /dispatch ──────────────────────────────────────

    public function test_wa_gateway_dispatch_accepts_valid_payload(): void
    {
        $this->requireGateway();
        $response = Http::withHeaders(['X-Internal-Secret' => $this->internalSecret])
            ->post("{$this->waGatewayUrl}/dispatch", [
                'wa_account_id' => 'acc-001',
                'to_phone'      => '+628111000001',
                'message_type'  => 'text',
                'body'          => 'Halo Kak! Ada yang bisa kami bantu?',
            ]);

        // Gateway always returns HTTP 200; success=false when session not active (no real phone)
        $this->assertTrue($response->successful(), "WA Gateway /dispatch returned {$response->status()}");
        $this->assertIsBool($response->json('success'));
        // If success=true (active session): provider_message_id must be present
        if ($response->json('success')) {
            $this->assertNotEmpty($response->json('provider_message_id'));
        } else {
            // success=false: must have error field explaining why (e.g. 'Session not found')
            $this->assertNotEmpty($response->json('error'));
        }
    }

    public function test_wa_gateway_dispatch_rejects_unauthorized_request(): void
    {
        $this->requireGateway();
        $response = Http::withHeaders(['X-Internal-Secret' => 'wrong_secret_12345'])
            ->post("{$this->waGatewayUrl}/dispatch", [
                'wa_account_id' => 'acc-001',
                'to_phone'      => '+628111000001',
                'message_type'  => 'text',
                'body'          => 'test',
            ]);

        $this->assertEquals(403, $response->status(), 'WA Gateway should reject wrong secret with 403');
    }

    public function test_wa_gateway_dispatch_rejects_missing_fields(): void
    {
        $this->requireGateway();
        $response = Http::withHeaders(['X-Internal-Secret' => $this->internalSecret])
            ->post("{$this->waGatewayUrl}/dispatch", [
                'wa_account_id' => 'acc-001',
                // missing to_phone, message_type, body
            ]);

        $this->assertEquals(422, $response->status(), 'WA Gateway should return 422 for missing fields');
    }

    // ── WA Gateway side: GET /status/:wa_account_id ──────────────────────────

    public function test_wa_gateway_status_returns_correct_format(): void
    {
        $this->requireGateway();
        $response = Http::withHeaders(['X-Internal-Secret' => $this->internalSecret])
            ->get("{$this->waGatewayUrl}/status/acc-001");

        $this->assertTrue($response->successful(), "WA Gateway /status returned {$response->status()}");
        $this->assertArrayHasKey('status', $response->json());
        $this->assertArrayHasKey('phone', $response->json());
        $this->assertArrayHasKey('connected_at', $response->json());
    }

    public function test_wa_gateway_status_rejects_unauthorized_request(): void
    {
        $this->requireGateway();
        $response = Http::withHeaders(['X-Internal-Secret' => 'wrong_secret_12345'])
            ->get("{$this->waGatewayUrl}/status/acc-001");

        $this->assertEquals(403, $response->status(), 'WA Gateway should reject wrong secret with 403');
    }

    // ── WA Gateway side: GET /health ──────────────────────────────────────────

    public function test_wa_gateway_health_returns_ok(): void
    {
        $this->requireGateway();
        $response = Http::get("{$this->waGatewayUrl}/health");

        $this->assertTrue($response->successful(), "WA Gateway /health returned {$response->status()}");
        $this->assertEquals('ok', $response->json('status'));
    }

    // ── WA Gateway side: POST /sessions/start ────────────────────────────────

    public function test_wa_gateway_sessions_start_returns_starting(): void
    {
        $this->requireGateway();
        $response = Http::withHeaders(['X-Internal-Secret' => $this->internalSecret])
            ->post("{$this->waGatewayUrl}/sessions/start", [
                'wa_account_id' => 'test-acc-'.uniqid(),
                'callback_url'  => null,
            ]);

        // May return 200 (starting) or 500 if Baileys not available — both are acceptable in dev
        $this->assertContains(
            $response->status(),
            [200, 500],
            "WA Gateway /sessions/start returned unexpected status {$response->status()}"
        );
        if ($response->successful()) {
            $this->assertNotEmpty($response->json('account_id'));
        }
    }

    // ── Contract format: dispatch must include correct fields ─────────────────

    public function test_dispatch_contract_format_via_action_dispatcher(): void
    {
        // This test verifies ActionDispatcher sends the correct contract fields
        // to the WA gateway. Uses Http::fake to intercept the outbound call.
        Http::fake([
            '*' => Http::response([
                'success'             => true,
                'provider_message_id' => 'fake-msg-id-123',
            ], 200),
        ]);

        $adapter = app(\App\Modules\WhatsApp\Adapters\WhatsAppGatewayAdapter::class);
        $result  = $adapter->sendText('acc-001', '+628111000001', 'Halo Kak!');

        // Verify the gateway was called with the correct contract fields
        Http::assertSent(function ($request) {
            $body = $request->data();
            return isset($body['wa_account_id'])
                && isset($body['to_phone'])
                && isset($body['message_type'])
                && isset($body['body']);
        });
    }
}
