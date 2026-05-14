<?php

namespace Tests\Feature\Contracts;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->waGatewayUrl   = rtrim(env('WA_GATEWAY_URL', 'http://wa-gateway:3001'), '/');
        $this->internalSecret = env('WA_INTERNAL_SECRET', '');
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
            'wa_account_id' => 'acc-001',
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
            'wa_account_id' => 'acc-001',
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
            'wa_account_id' => 'acc-001',
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
            'wa_account_id' => 'acc-001',
            // missing: from_phone, message_type, received_at
        ], ['X-Internal-Secret' => $this->internalSecret]);

        $response->assertStatus(422);
    }

    public function test_webhook_inbound_accepts_null_body_for_media_messages(): void
    {
        $response = $this->postJson('/webhook/inbound', [
            'wa_account_id' => 'acc-001',
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

        $this->assertTrue($response->successful(), "WA Gateway /dispatch returned {$response->status()}");
        $this->assertTrue($response->json('success'));
        $this->assertNotEmpty($response->json('provider_message_id'));
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
}
