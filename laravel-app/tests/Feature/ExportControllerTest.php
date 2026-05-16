<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User   $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $superadmin = User::create([
            'name'      => 'Super',
            'email'     => 'super-ec-' . Str::random(5) . '@test.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'name'          => 'EC Vendor',
            'slug'          => 'ec-v-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'ec@vendor.com',
            'created_by_id' => $superadmin->id,
        ]);

        $this->user = User::create([
            'name'      => 'Admin EC',
            'email'     => 'admin-ec-' . Str::random(5) . '@vendor.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
    }

    public function test_export_bookings_returns_csv_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)->get(route('export.bookings'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_export_invoices_returns_csv_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)->get(route('export.invoices'));

        $response->assertStatus(200);
        $this->assertStringContainsString('csv', $response->headers->get('content-type', ''));
    }

    public function test_export_leads_returns_csv_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)->get(route('export.leads'));

        $response->assertStatus(200);
        $this->assertStringContainsString('csv', $response->headers->get('content-type', ''));
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->get(route('export.bookings'));

        // Must not return 200 — either redirect (302) or auth error (401/403)
        $this->assertNotEquals(200, $response->status());
    }
}
