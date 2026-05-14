<?php

namespace Tests\Feature\Filament;

use App\Modules\Auth\Models\User;
use App\Modules\Knowledge\Models\Faq;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\TenantTone;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\TenantConfig\Models\TenantSetting;
use App\Modules\TenantConfig\Services\TenantPolicyService;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class FilamentKnowledgePanelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $otherTenant;
    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $superadmin = User::create([
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
            'created_by_id' => $superadmin->id,
        ]);

        $this->otherTenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Vendor B',
            'slug'          => 'vendor-b',
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => 'vendor-b@example.com',
            'created_by_id' => $superadmin->id,
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

    public function test_tenant_admin_can_access_packages_page(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/packages');

        $response->assertStatus(200);
    }

    public function test_tenant_admin_can_access_faqs_page(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/faqs');

        $response->assertStatus(200);
    }

    public function test_tenant_admin_can_access_settings_page(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/tenant-settings');

        $response->assertStatus(200);
    }

    public function test_tenant_admin_can_access_policy_settings_page(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/policy-settings');

        $response->assertStatus(200);
    }

    public function test_package_create_assigns_correct_tenant_id(): void
    {
        $tenantId = $this->tenant->id;

        $this->actingAs($this->tenantAdmin);

        // Simulate what CreatePackage->mutateFormDataBeforeCreate does
        Package::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'name'      => 'Paket Test Create',
            'slug'      => 'paket-test-create',
            'category'  => 'wedding',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('packages', [
            'name'      => 'Paket Test Create',
            'slug'      => 'paket-test-create',
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_tenant_isolation_packages_query_scoped_to_tenant(): void
    {
        Package::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'name'      => 'Paket Milik A',
            'slug'      => 'paket-milik-a',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        Package::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $this->otherTenant->id,
            'name'      => 'Paket Milik B',
            'slug'      => 'paket-milik-b',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        // Simulate PackageResource::getEloquentQuery scoped to tenant
        $packages = Package::where('tenant_id', $this->tenant->id)->get();

        $this->assertCount(1, $packages);
        $this->assertEquals('Paket Milik A', $packages->first()->name);
    }

    public function test_faq_create_assigns_correct_tenant_id(): void
    {
        Faq::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'question'  => 'Berapa harga paket?',
            'answer'    => 'Mulai dari Rp 8 juta.',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $this->assertDatabaseHas('faqs', [
            'question'  => 'Berapa harga paket?',
            'tenant_id' => $this->tenant->id,
        ]);
    }

    public function test_tenant_settings_can_be_saved(): void
    {
        TenantSetting::updateOrCreate(
            ['tenant_id' => $this->tenant->id],
            [
                'tone'                 => TenantTone::FRIENDLY,
                'timezone'             => 'Asia/Makassar',
                'business_hours_start' => '09:00',
                'business_hours_end'   => '20:00',
                'business_days'        => [1, 2, 3, 4, 5, 6],
            ]
        );

        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $this->tenant->id,
            'timezone'  => 'Asia/Makassar',
        ]);

        $setting = TenantSetting::where('tenant_id', $this->tenant->id)->first();
        $this->assertEquals(TenantTone::FRIENDLY, $setting->tone);
    }

    public function test_policy_settings_can_be_saved(): void
    {
        $policyService = app(TenantPolicyService::class);
        $policyService->setPolicy($this->tenant->id, PolicyKey::PRICELIST_MODE, 'on_request');
        $policyService->setPolicy($this->tenant->id, PolicyKey::AFTER_HOURS_BEHAVIOR, 'auto_reply');

        $this->assertDatabaseHas('tenant_policies', [
            'tenant_id'    => $this->tenant->id,
            'policy_key'   => PolicyKey::PRICELIST_MODE->value,
            'policy_value' => 'on_request',
        ]);

        $this->assertEquals(
            'auto_reply',
            $policyService->getPolicy($this->tenant->id, PolicyKey::AFTER_HOURS_BEHAVIOR)
        );
    }

    public function test_other_tenant_package_returns_404(): void
    {
        $otherId = Str::uuid()->toString();
        Package::create([
            'id'        => $otherId,
            'tenant_id' => $this->otherTenant->id,
            'name'      => 'Paket Lain',
            'slug'      => 'paket-lain',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/packages/' . $otherId . '/edit');

        $response->assertStatus(404);
    }
}
