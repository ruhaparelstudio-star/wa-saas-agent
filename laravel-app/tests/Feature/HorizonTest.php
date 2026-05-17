<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HorizonTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;
    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::create([
            'name'      => 'Super',
            'email'     => 'super-horizon-' . Str::random(5) . '@test.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'name'          => 'Horizon Tenant',
            'slug'          => 'horizon-t-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'horizon@tenant.com',
            'created_by_id' => $this->superadmin->id,
        ]);

        $this->tenantAdmin = User::create([
            'name'      => 'Tenant Admin',
            'email'     => 'admin-horizon-' . Str::random(5) . '@tenant.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $tenant->id,
            'is_active' => true,
        ]);
    }

    public function test_superadmin_can_access_horizon(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/horizon');

        // Horizon returns 200 (local env always allows /horizon UI)
        $response->assertStatus(200);
    }

    public function test_horizon_gate_allows_superadmin(): void
    {
        // Gate::define('viewHorizon') is the production access control.
        // In local env Horizon bypasses it, so we test the gate directly.
        $canView = \Illuminate\Support\Facades\Gate::forUser($this->superadmin)->allows('viewHorizon');
        $this->assertTrue($canView);
    }

    public function test_horizon_gate_blocks_tenant_admin(): void
    {
        $cannotView = \Illuminate\Support\Facades\Gate::forUser($this->tenantAdmin)->allows('viewHorizon');
        $this->assertFalse($cannotView);
    }

    public function test_horizon_gate_blocks_unauthenticated(): void
    {
        // null user has no role
        $cannotView = \Illuminate\Support\Facades\Gate::allows('viewHorizon');
        $this->assertFalse($cannotView);
    }

    public function test_horizon_route_exists(): void
    {
        // Horizon registers its own routes — verify the route is registered
        $this->assertTrue(
            collect(\Illuminate\Support\Facades\Route::getRoutes())->contains(
                fn ($route) => str_contains($route->uri(), 'horizon')
            )
        );
    }
}
