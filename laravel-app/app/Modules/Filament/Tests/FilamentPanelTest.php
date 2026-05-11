<?php

namespace App\Modules\Filament\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\UserRole;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FilamentPanelTest extends TestCase
{
    use RefreshDatabase;

    // ── Redirect to login when unauthenticated ──────────────────────────

    public function test_superadmin_panel_redirects_to_login_when_unauthenticated(): void
    {
        $response = $this->get('/superadmin');
        $response->assertRedirect('/superadmin/login');
    }

    public function test_tenant_panel_redirects_to_login_when_unauthenticated(): void
    {
        $response = $this->get('/app');
        $response->assertRedirect('/app/login');
    }

    // ── canAccessPanel unit tests ────────────────────────────────────────

    public function test_superadmin_can_access_superadmin_panel(): void
    {
        $superadmin = User::factory()->create([
            'role' => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $panel = Filament::getPanel('superadmin');
        $this->assertTrue($superadmin->canAccessPanel($panel));
    }

    public function test_tenant_admin_can_access_tenant_panel(): void
    {
        $tenantAdmin = User::factory()->create([
            'role' => UserRole::TENANT_ADMIN,
            'is_active' => true,
        ]);

        $panel = Filament::getPanel('tenant');
        $this->assertTrue($tenantAdmin->canAccessPanel($panel));
    }

    public function test_tenant_admin_cannot_access_superadmin_panel(): void
    {
        $tenantAdmin = User::factory()->create([
            'role' => UserRole::TENANT_ADMIN,
            'is_active' => true,
        ]);

        $panel = Filament::getPanel('superadmin');
        $this->assertFalse($tenantAdmin->canAccessPanel($panel));
    }

    public function test_superadmin_cannot_access_tenant_panel(): void
    {
        $superadmin = User::factory()->create([
            'role' => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $panel = Filament::getPanel('tenant');
        $this->assertFalse($superadmin->canAccessPanel($panel));
    }

    public function test_inactive_user_cannot_access_any_panel(): void
    {
        $inactiveUser = User::factory()->create([
            'role' => UserRole::SUPERADMIN,
            'is_active' => false,
        ]);

        $superadminPanel = Filament::getPanel('superadmin');
        $tenantPanel = Filament::getPanel('tenant');

        $this->assertFalse($inactiveUser->canAccessPanel($superadminPanel));
        $this->assertFalse($inactiveUser->canAccessPanel($tenantPanel));
    }

    // ── HTTP 403 for cross-panel access ──────────────────────────────────

    public function test_tenant_admin_gets_403_accessing_superadmin_panel(): void
    {
        $tenantAdmin = User::factory()->create([
            'role' => UserRole::TENANT_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($tenantAdmin)->get('/superadmin');
        $response->assertForbidden();
    }

    public function test_superadmin_gets_403_accessing_tenant_panel(): void
    {
        $superadmin = User::factory()->create([
            'role' => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($superadmin)->get('/app');
        $response->assertForbidden();
    }

    public function test_tenant_panel_is_accessible_to_tenant_admin(): void
    {
        $tenantAdmin = User::factory()->create([
            'role' => UserRole::TENANT_ADMIN,
            'is_active' => true,
        ]);

        $response = $this->actingAs($tenantAdmin)->get('/app');
        $response->assertSuccessful();
    }
}
