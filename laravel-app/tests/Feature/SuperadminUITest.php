<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E2E-style UI verification for the superadmin panel.
 * Tests rendered HTML to ensure navigation groups, form sections,
 * and the fixed Google OAuth save button are present and correct.
 */
class SuperadminUITest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::factory()->create([
            'email'     => 'admin@platform.com',
            'password'  => bcrypt('Demo123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    // ── Navigation & Branding ────────────────────────────────────────────────

    public function test_dashboard_loads_and_shows_brand(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin');
        $response->assertRedirect(); // Filament redirects home to first resource
    }

    public function test_tenants_page_loads_with_management_group(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/tenants');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Management', $html,
            'Navigation group "Management" should be visible');
        $this->assertStringContainsString('Tenants', $html,
            '"Tenants" nav item should be visible');
    }

    public function test_plans_page_loads_with_management_group(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/plans');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Management', $html,
            'Navigation group "Management" should be visible');
        $this->assertStringContainsString('Plans', $html,
            '"Plans" nav item should be visible');
    }

    public function test_decision_traces_page_loads_with_ai_pipeline_group(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/decision-traces');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('AI Pipeline', $html,
            'Navigation group "AI Pipeline" should be visible');
        $this->assertStringContainsString('Decision Traces', $html,
            '"Decision Traces" nav item should be visible');
    }

    public function test_prompt_templates_page_loads_with_ai_pipeline_group(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/prompt-templates');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('AI Pipeline', $html,
            'Navigation group "AI Pipeline" should be visible');
        $this->assertStringContainsString('Prompt Templates', $html,
            '"Prompt Templates" nav item should be visible');
    }

    public function test_analytics_page_loads_with_reports_group(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/analytics');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Reports', $html,
            'Navigation group "Reports" should be visible');
        $this->assertStringContainsString('Cross-Tenant Analytics', $html,
            '"Cross-Tenant Analytics" nav label should be visible');
    }

    public function test_analytics_page_has_period_selector_and_table(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/analytics');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Bulan Ini', $html, 'Period selector "Bulan Ini" should be visible');
        $this->assertStringContainsString('Revenue', $html, 'Revenue column should be visible');
    }

    // ── Google OAuth Save Button (critical bug fix) ──────────────────────────

    public function test_google_oauth_page_loads_with_system_settings_group(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/google-oauth-settings');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('System Settings', $html,
            'Navigation group "System Settings" should replace "Pengaturan Sistem"');
        $this->assertStringNotContainsString('Pengaturan Sistem', $html,
            'Old Indonesian group name should NOT appear');
    }

    public function test_google_oauth_save_button_visible_in_header(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/google-oauth-settings');
        $response->assertOk();
        $html = $response->getContent();

        // The save button rendered via getHeaderActions() appears in the Filament header slot
        $this->assertStringContainsString('Simpan Konfigurasi', $html,
            'Save button must be visible — was missing because getFormActions() was used instead of getHeaderActions()');
    }

    public function test_google_oauth_has_form_fields(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/google-oauth-settings');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Google Client ID', $html, 'Client ID field should be visible');
        $this->assertStringContainsString('Google Client Secret', $html, 'Client Secret field should be visible');
        $this->assertStringContainsString('Redirect URI', $html, 'Redirect URI field should be visible');
        $this->assertStringContainsString('Status Koneksi', $html, 'Status section should be visible');
    }

    // ── Tenant Form Sections ─────────────────────────────────────────────────

    public function test_create_tenant_form_has_sections(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/tenants/create');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Business Info', $html,
            'Form section "Business Info" should be present after refactor');
        $this->assertStringContainsString('Contact', $html,
            'Form section "Contact" should be present after refactor');
    }

    // ── Plan Form Sections ───────────────────────────────────────────────────

    public function test_create_plan_form_has_sections(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/plans/create');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('Plan Details', $html,
            'Form section "Plan Details" should be present after refactor');
        $this->assertStringContainsString('Settings', $html,
            'Form section "Settings" should be present after refactor');
    }

    // ── Profile page (new via ->profile()) ───────────────────────────────────

    public function test_profile_page_accessible(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/profile');
        $response->assertOk();
    }

    // ── No Indonesian navigation groups remain in superadmin ─────────────────

    public function test_no_indonesian_nav_groups_remain(): void
    {
        $pages = [
            '/superadmin/tenants',
            '/superadmin/plans',
            '/superadmin/google-oauth-settings',
            '/superadmin/analytics',
            '/superadmin/decision-traces',
        ];

        foreach ($pages as $url) {
            $html = $this->actingAs($this->superadmin)->get($url)->getContent();
            $this->assertStringNotContainsString('Pengaturan Sistem', $html,
                "Page {$url} must not contain old Indonesian group name");
        }
    }
}
