<?php

namespace Tests\Feature\Filament;

use App\Modules\Auth\Models\User;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FilamentAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User   $tenantAdmin;
    private User   $superadmin;

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

        $planWithAdvanced = $this->makePlan(analyticsAdvanced: true);

        $this->tenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Wedding Vendor',
            'slug'          => 'wedding-vendor-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => 'vendor@example.com',
            'created_by_id' => $this->superadmin->id,
        ]);

        TenantSubscription::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'plan_id'   => $planWithAdvanced->id,
            'status'    => 'active',
            'starts_at' => Carbon::now()->subDays(15),
            'ends_at'   => Carbon::now()->addDays(15),
        ]);

        $this->tenantAdmin = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Tenant Admin',
            'email'     => 'admin@vendor.com',
            'password'  => Hash::make('password'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
    }

    public function test_tenant_admin_can_access_analytics_page(): void
    {
        $response = $this->actingAs($this->tenantAdmin)->get('/app/analytics');

        $response->assertStatus(200);
    }

    public function test_analytics_page_shows_upgrade_notice_when_feature_disabled(): void
    {
        $planBasic = $this->makePlan(analyticsAdvanced: false);

        $tenantBasic = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Basic Vendor',
            'slug'          => 'basic-vendor-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => 'basic@example.com',
            'created_by_id' => $this->superadmin->id,
        ]);

        TenantSubscription::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenantBasic->id,
            'plan_id'   => $planBasic->id,
            'status'    => 'active',
            'starts_at' => Carbon::now()->subDays(15),
            'ends_at'   => Carbon::now()->addDays(15),
        ]);

        $adminBasic = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Basic Admin',
            'email'     => 'basicadmin@vendor.com',
            'password'  => Hash::make('password'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $tenantBasic->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($adminBasic)->get('/app/analytics');

        $response->assertStatus(200);
        $response->assertSee('Analytics Lanjutan Terkunci');
    }

    public function test_analytics_page_shows_idr_revenue_format(): void
    {
        // Revenue display is always shown (even when 0), we just check the page renders with Rp
        $response = $this->actingAs($this->tenantAdmin)->get('/app/analytics');

        $response->assertStatus(200);
        $response->assertSee('Rp');
    }

    public function test_superadmin_can_access_cross_tenant_analytics(): void
    {
        $response = $this->actingAs($this->superadmin)->get('/superadmin/analytics');

        $response->assertStatus(200);
    }

    public function test_tenant_admin_cannot_access_superadmin_analytics(): void
    {
        $response = $this->actingAs($this->tenantAdmin)->get('/superadmin/analytics');

        // Filament may redirect tenant admins away (302) or return 403
        $this->assertContains($response->status(), [302, 403]);
    }

    private function makePlan(bool $analyticsAdvanced = true): Plan
    {
        $plan = Plan::create([
            'id'         => Str::uuid()->toString(),
            'code'       => 'plan-' . Str::random(4),
            'name'       => $analyticsAdvanced ? 'Pro' : 'Starter',
            'is_active'  => true,
            'sort_order' => 0,
        ]);

        $features = [
            FeatureKey::MAX_WA_AGENTS->value          => '3',
            FeatureKey::MONTHLY_LEAD_LIMIT->value      => '-1',
            FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'false',
            FeatureKey::FOLLOW_UP_AUTOMATION->value    => 'false',
            FeatureKey::ANALYTICS_ADVANCED->value      => $analyticsAdvanced ? 'true' : 'false',
            FeatureKey::MULTI_CHANNEL->value           => 'false',
        ];

        foreach ($features as $key => $value) {
            PlanFeature::create([
                'id'            => Str::uuid()->toString(),
                'plan_id'       => $plan->id,
                'feature_key'   => $key,
                'feature_value' => $value,
            ]);
        }

        return $plan;
    }
}
