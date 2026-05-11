<?php

namespace App\Modules\Plans\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeatureGateServiceTest extends TestCase
{
    use RefreshDatabase;

    private FeatureGateService $service;
    private Tenant $starterTenant;
    private Tenant $growthTenant;
    private Tenant $proTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(FeatureGateService::class);

        $superadmin = $this->makeSuperadmin();

        // Seed plans
        $starter = $this->makePlan('starter', 'Starter', [
            FeatureKey::MAX_WA_AGENTS->value         => '1',
            FeatureKey::MONTHLY_LEAD_LIMIT->value     => '100',
            FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'false',
            FeatureKey::FOLLOW_UP_AUTOMATION->value   => 'false',
            FeatureKey::ANALYTICS_ADVANCED->value     => 'false',
        ]);

        $growth = $this->makePlan('growth', 'Growth', [
            FeatureKey::MAX_WA_AGENTS->value         => '2',
            FeatureKey::MONTHLY_LEAD_LIMIT->value     => '500',
            FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'true',
            FeatureKey::FOLLOW_UP_AUTOMATION->value   => 'true',
            FeatureKey::ANALYTICS_ADVANCED->value     => 'false',
        ]);

        $pro = $this->makePlan('pro', 'Pro', [
            FeatureKey::MAX_WA_AGENTS->value         => '5',
            FeatureKey::MONTHLY_LEAD_LIMIT->value     => '-1',
            FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'true',
            FeatureKey::FOLLOW_UP_AUTOMATION->value   => 'true',
            FeatureKey::ANALYTICS_ADVANCED->value     => 'true',
        ]);

        $this->starterTenant = $this->makeTenant('Starter Co', $superadmin, $starter);
        $this->growthTenant  = $this->makeTenant('Growth Co', $superadmin, $growth);
        $this->proTenant     = $this->makeTenant('Pro Co', $superadmin, $pro);
    }

    public function test_starter_calendar_disabled(): void
    {
        $this->assertFalse(
            $this->service->isCalendarEnabled($this->starterTenant->id)
        );
    }

    public function test_growth_calendar_enabled(): void
    {
        $this->assertTrue(
            $this->service->isCalendarEnabled($this->growthTenant->id)
        );
    }

    public function test_starter_follow_up_disabled(): void
    {
        $this->assertFalse(
            $this->service->isFollowUpEnabled($this->starterTenant->id)
        );
    }

    public function test_growth_follow_up_enabled(): void
    {
        $this->assertTrue(
            $this->service->isFollowUpEnabled($this->growthTenant->id)
        );
    }

    public function test_pro_lead_limit_unlimited(): void
    {
        $this->assertTrue(
            $this->service->isLeadLimitUnlimited($this->proTenant->id)
        );
    }

    public function test_starter_lead_limit_is_100(): void
    {
        $this->assertEquals(100, $this->service->getLeadLimit($this->starterTenant->id));
    }

    public function test_growth_lead_limit_is_500(): void
    {
        $this->assertEquals(500, $this->service->getLeadLimit($this->growthTenant->id));
    }

    public function test_starter_cannot_add_wa_agent_when_at_limit(): void
    {
        $this->assertFalse(
            $this->service->canAddWaAgent($this->starterTenant->id, 1)
        );
    }

    public function test_starter_can_add_wa_agent_when_below_limit(): void
    {
        $this->assertTrue(
            $this->service->canAddWaAgent($this->starterTenant->id, 0)
        );
    }

    public function test_pro_analytics_advanced_enabled(): void
    {
        $this->assertTrue(
            $this->service->check($this->proTenant->id, FeatureKey::ANALYTICS_ADVANCED)
        );
    }

    public function test_starter_analytics_advanced_disabled(): void
    {
        $this->assertFalse(
            $this->service->check($this->starterTenant->id, FeatureKey::ANALYTICS_ADVANCED)
        );
    }

    public function test_no_subscription_returns_false(): void
    {
        $superadmin = $this->makeSuperadmin('other@admin.com');
        $tenantNoSub = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'No Sub Co',
            'slug'          => 'no-sub-co',
            'status'        => TenantStatus::TRIAL,
            'industry'      => 'wedding',
            'contact_email' => 'nosub@example.com',
            'created_by_id' => $superadmin->id,
        ]);

        $this->assertFalse(
            $this->service->check($tenantNoSub->id, FeatureKey::GOOGLE_CALENDAR_ENABLED)
        );
    }

    public function test_cache_hit_prevents_multiple_db_queries(): void
    {
        Cache::flush();

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            if (str_contains($query->sql, 'tenant_subscriptions')) {
                $queryCount++;
            }
        });

        $this->service->isCalendarEnabled($this->starterTenant->id);
        $this->service->isFollowUpEnabled($this->starterTenant->id);
        $this->service->getLeadLimit($this->starterTenant->id);

        // All 3 calls share the same cache entry → only 1 DB query
        $this->assertEquals(1, $queryCount);
    }

    // ────────────────── helpers ──────────────────

    private function makeSuperadmin(string $email = 'admin@platform.com'): User
    {
        return User::create([
            'id'       => Str::uuid()->toString(),
            'name'     => 'Superadmin',
            'email'    => $email,
            'password' => bcrypt('Password123!'),
            'role'     => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makePlan(string $code, string $name, array $features): Plan
    {
        $plan = Plan::create([
            'id'       => Str::uuid()->toString(),
            'code'     => $code,
            'name'     => $name,
            'is_active' => true,
            'sort_order' => 0,
        ]);

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

    private function makeTenant(string $name, User $createdBy, Plan $plan): Tenant
    {
        $slug = \Illuminate\Support\Str::slug($name);

        $tenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => $name,
            'slug'          => $slug,
            'status'        => TenantStatus::TRIAL,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);

        TenantSubscription::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'plan_id'   => $plan->id,
            'status'    => 'trial',
            'starts_at' => now(),
        ]);

        return $tenant;
    }
}
