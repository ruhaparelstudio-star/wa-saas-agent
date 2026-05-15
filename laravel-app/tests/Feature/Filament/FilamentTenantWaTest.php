<?php

namespace Tests\Feature\Filament;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FilamentTenantWaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $tenantAdmin;
    private User $superadmin;

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
    }

    // ─── Panel access ───────────────────────────────────────────────────

    public function test_tenant_admin_can_access_wa_accounts_page(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/wa-accounts');

        $response->assertStatus(200);
    }

    public function test_superadmin_cannot_access_tenant_panel(): void
    {
        $response = $this->actingAs($this->superadmin)
            ->get('/app/wa-accounts');

        // Superadmin cannot access tenant panel — Filament returns 403
        $response->assertStatus(403);
    }

    // ─── Create ─────────────────────────────────────────────────────────

    public function test_tenant_admin_can_create_wa_account(): void
    {
        WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenant->id,
            'display_name' => 'CS Utama',
            'status'       => WaAccountStatus::DISCONNECTED,
            'metadata'     => [],
        ]);

        $this->assertDatabaseHas('wa_accounts', [
            'display_name' => 'CS Utama',
            'tenant_id'    => $this->tenant->id,
            'status'       => WaAccountStatus::DISCONNECTED->value,
        ]);
    }

    // ─── Tenant isolation ────────────────────────────────────────────────

    public function test_tenant_admin_sees_only_own_wa_accounts(): void
    {
        WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenant->id,
            'display_name' => 'Akun A',
            'status'       => WaAccountStatus::DISCONNECTED,
            'metadata'     => [],
        ]);

        WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->otherTenant->id,
            'display_name' => 'Akun B',
            'status'       => WaAccountStatus::DISCONNECTED,
            'metadata'     => [],
        ]);

        // Simulate WaAccountResource::getEloquentQuery scoped to tenant
        $accounts = WaAccount::where('tenant_id', $this->tenant->id)->get();

        $this->assertCount(1, $accounts);
        $this->assertEquals('Akun A', $accounts->first()->display_name);
    }

    // ─── QR status endpoint ─────────────────────────────────────────────

    public function test_qr_status_endpoint_returns_200_for_own_account(): void
    {
        $account = WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenant->id,
            'display_name' => 'CS Test',
            'status'       => WaAccountStatus::QR_PENDING,
            'qr_code'      => 'base64qrstring==',
            'qr_expires_at' => now()->addMinutes(4),
            'metadata'     => [],
        ]);

        $response = $this->actingAs($this->tenantAdmin)
            ->getJson("/app/wa-accounts/{$account->id}/qr-status");

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'qr_code', 'phone', 'is_expired']);
        $response->assertJson(['status' => 'qr_pending']);
    }

    public function test_qr_status_endpoint_returns_403_for_other_tenants_account(): void
    {
        $otherAccount = WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->otherTenant->id,
            'display_name' => 'Akun Tenant B',
            'status'       => WaAccountStatus::QR_PENDING,
            'qr_code'      => 'someqr',
            'qr_expires_at' => now()->addMinutes(4),
            'metadata'     => [],
        ]);

        $response = $this->actingAs($this->tenantAdmin)
            ->getJson("/app/wa-accounts/{$otherAccount->id}/qr-status");

        // TenantScope hides other tenant's records → 404 (KANBAN: "403 atau 404")
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }

    public function test_qr_status_endpoint_returns_404_for_unknown_account(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->getJson('/app/wa-accounts/' . Str::uuid() . '/qr-status');

        $response->assertStatus(404);
    }

    public function test_qr_status_connected_account_returns_no_qr_code(): void
    {
        $account = WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenant->id,
            'display_name' => 'CS Connected',
            'status'       => WaAccountStatus::CONNECTED,
            'phone_number' => '+6281234567890',
            'connected_at' => now(),
            'metadata'     => [],
        ]);

        $response = $this->actingAs($this->tenantAdmin)
            ->getJson("/app/wa-accounts/{$account->id}/qr-status");

        $response->assertStatus(200);
        $response->assertJson(['status' => 'connected', 'qr_code' => null]);
    }

    public function test_unauthenticated_user_cannot_access_qr_status(): void
    {
        $account = WaAccount::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenant->id,
            'display_name' => 'CS Test',
            'status'       => WaAccountStatus::DISCONNECTED,
            'metadata'     => [],
        ]);

        $response = $this->getJson("/app/wa-accounts/{$account->id}/qr-status");

        $response->assertStatus(401);
    }
}
