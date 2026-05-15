<?php

namespace App\Modules\WhatsApp\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use App\Modules\WhatsApp\Services\WaAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class WaAccountServiceTest extends TestCase
{
    use RefreshDatabase;

    private WaAccountService $service;
    private WaAccountRepository $repo;
    private Tenant $tenant;
    private string $testSecret = 'test-internal-secret-abc123';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.wa_gateway.internal_secret' => $this->testSecret]);
        config(['services.wa_gateway.url'             => 'http://wa-gateway:3001']);

        $this->repo    = app(WaAccountRepository::class);
        $this->service = app(WaAccountService::class);

        $superadmin   = $this->makeSuperadmin();
        $this->actingAs($superadmin);
        $this->tenant = $this->makeTenant('Test Tenant', $superadmin);
    }

    // ── initiateConnect ──────────────────────────────────────────────────────

    public function test_initiate_connect_posts_to_wa_gateway_and_sets_connecting(): void
    {
        Http::fake([
            '*/sessions/start' => Http::response(['status' => 'starting', 'account_id' => 'acc-001'], 200),
        ]);

        $account = $this->repo->create($this->tenant->id, 'CS Utama');
        $this->assertEquals(WaAccountStatus::DISCONNECTED, $account->status);

        $result = $this->service->initiateConnect($account);

        $this->assertTrue($result);
        $account->refresh();
        $this->assertEquals(WaAccountStatus::CONNECTING, $account->status);

        Http::assertSent(function ($request) use ($account) {
            return str_contains($request->url(), '/sessions/start')
                && $request->data()['wa_account_id'] === $account->id;
        });
    }

    public function test_initiate_connect_returns_false_when_gateway_fails(): void
    {
        Http::fake([
            '*/sessions/start' => Http::response([], 500),
        ]);

        $account = $this->repo->create($this->tenant->id, 'CS Utama');
        $result  = $this->service->initiateConnect($account);

        $this->assertFalse($result);
        // Status should NOT change if gateway call fails
        $account->refresh();
        $this->assertEquals(WaAccountStatus::DISCONNECTED, $account->status);
    }

    // ── handleSessionCallback ────────────────────────────────────────────────

    public function test_handle_callback_qr_event_sets_qr_pending(): void
    {
        $account = $this->repo->create($this->tenant->id, 'CS Utama');
        $qrBase64 = base64_encode('fake-qr-image-data');

        $this->service->handleSessionCallback($account->id, [
            'event'     => 'qr',
            'qr_base64' => $qrBase64,
        ]);

        $account->refresh();
        $this->assertEquals(WaAccountStatus::QR_PENDING, $account->status);
        $this->assertEquals($qrBase64, $account->qr_code);
        $this->assertNotNull($account->qr_expires_at);
        $this->assertTrue($account->qr_expires_at->isFuture());
    }

    public function test_handle_callback_connected_event_sets_connected(): void
    {
        $account = $this->repo->create($this->tenant->id, 'CS Utama');

        $this->service->handleSessionCallback($account->id, [
            'event' => 'connected',
            'phone' => '+6281234567890',
        ]);

        $account->refresh();
        $this->assertEquals(WaAccountStatus::CONNECTED, $account->status);
        $this->assertEquals('+6281234567890', $account->phone_number);
        $this->assertNotNull($account->connected_at);
    }

    public function test_handle_callback_disconnected_event_sets_disconnected(): void
    {
        $account = $this->repo->create($this->tenant->id, 'CS Utama');
        $account->markConnected('+6281234567890');

        $this->service->handleSessionCallback($account->id, [
            'event' => 'disconnected',
        ]);

        $account->refresh();
        $this->assertEquals(WaAccountStatus::DISCONNECTED, $account->status);
        $this->assertNull($account->qr_code);
    }

    public function test_handle_callback_failed_event_increments_reconnect(): void
    {
        $account = $this->repo->create($this->tenant->id, 'CS Utama');
        $this->assertEquals(0, $account->reconnect_attempts);

        $this->service->handleSessionCallback($account->id, ['event' => 'failed']);

        $account->refresh();
        $this->assertEquals(WaAccountStatus::FAILED, $account->status);
        $this->assertEquals(1, $account->reconnect_attempts);
    }

    public function test_handle_callback_ignores_unknown_account(): void
    {
        $nonExistentId = Str::uuid()->toString();

        // Should not throw — just logs warning
        $this->service->handleSessionCallback($nonExistentId, ['event' => 'qr', 'qr_base64' => 'test']);
        $this->assertTrue(true); // no exception = pass
    }

    // ── disconnect ───────────────────────────────────────────────────────────

    public function test_disconnect_posts_to_gateway_and_marks_disconnected(): void
    {
        Http::fake([
            '*/sessions/stop' => Http::response(['status' => 'stopped'], 200),
        ]);

        $account = $this->repo->create($this->tenant->id, 'CS Utama');
        $account->markConnected('+6281234567890');
        $account->refresh();
        $this->assertEquals(WaAccountStatus::CONNECTED, $account->status);

        $result = $this->service->disconnect($account);

        $this->assertTrue($result);
        $account->refresh();
        $this->assertEquals(WaAccountStatus::DISCONNECTED, $account->status);

        Http::assertSent(function ($request) use ($account) {
            return str_contains($request->url(), '/sessions/stop')
                && $request->data()['wa_account_id'] === $account->id;
        });
    }

    // ── HTTP route: POST /internal/wa-session-callback/{account_id} ──────────

    public function test_callback_route_rejects_missing_secret(): void
    {
        $account  = $this->repo->create($this->tenant->id, 'CS Utama');
        $response = $this->postJson("/internal/wa-session-callback/{$account->id}", [
            'event' => 'qr',
            'qr_base64' => base64_encode('fake'),
        ]);

        $response->assertStatus(403);
    }

    public function test_callback_route_rejects_wrong_secret(): void
    {
        $account  = $this->repo->create($this->tenant->id, 'CS Utama');
        $response = $this->postJson("/internal/wa-session-callback/{$account->id}", [
            'event' => 'qr',
            'qr_base64' => base64_encode('fake'),
        ], ['X-Internal-Secret' => 'wrong-secret-xyz']);

        $response->assertStatus(403);
    }

    public function test_callback_route_with_valid_qr_payload_updates_account(): void
    {
        $account  = $this->repo->create($this->tenant->id, 'CS Utama');
        $qrBase64 = base64_encode('fake-qr-data');

        $response = $this->postJson("/internal/wa-session-callback/{$account->id}", [
            'event'     => 'qr',
            'qr_base64' => $qrBase64,
        ], ['X-Internal-Secret' => $this->testSecret]);

        $response->assertStatus(200)->assertJson(['status' => 'ok']);

        $account->refresh();
        $this->assertEquals(WaAccountStatus::QR_PENDING, $account->status);
        $this->assertEquals($qrBase64, $account->qr_code);
    }

    public function test_callback_route_with_connected_payload_updates_account(): void
    {
        $account  = $this->repo->create($this->tenant->id, 'CS Utama');

        $response = $this->postJson("/internal/wa-session-callback/{$account->id}", [
            'event' => 'connected',
            'phone' => '+6281234567890',
        ], ['X-Internal-Secret' => $this->testSecret]);

        $response->assertStatus(200);

        $account->refresh();
        $this->assertEquals(WaAccountStatus::CONNECTED, $account->status);
        $this->assertEquals('+6281234567890', $account->phone_number);
    }

    public function test_callback_route_returns_404_for_unknown_account(): void
    {
        $fakeId   = Str::uuid()->toString();
        $response = $this->postJson("/internal/wa-session-callback/{$fakeId}", [
            'event' => 'qr',
            'qr_base64' => base64_encode('fake'),
        ], ['X-Internal-Secret' => $this->testSecret]);

        $response->assertStatus(404);
    }

    public function test_callback_route_rejects_invalid_event_value(): void
    {
        $account  = $this->repo->create($this->tenant->id, 'CS Utama');

        $response = $this->postJson("/internal/wa-session-callback/{$account->id}", [
            'event' => 'unknown_event',
        ], ['X-Internal-Secret' => $this->testSecret]);

        $response->assertStatus(422);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeTenant(string $name, User $createdBy): Tenant
    {
        $slug = Str::slug($name);

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
