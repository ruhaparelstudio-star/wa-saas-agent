<?php

namespace App\Modules\TenantConfig\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\DTOs\TenantConfigDTO;
use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\TenantTone;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\TenantConfig\Models\TenantPolicy;
use App\Modules\TenantConfig\Models\TenantSetting;
use App\Modules\TenantConfig\Services\BusinessHoursService;
use App\Modules\TenantConfig\Services\TenantConfigResolver;
use App\Modules\TenantConfig\Services\TenantPolicyService;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantConfigTest extends TestCase
{
    use RefreshDatabase;

    private TenantConfigResolver $resolver;
    private BusinessHoursService $businessHours;
    private TenantPolicyService $policyService;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver      = app(TenantConfigResolver::class);
        $this->businessHours = app(BusinessHoursService::class);
        $this->policyService = app(TenantPolicyService::class);

        $this->tenant = $this->makeTenant();
    }

    // ── TenantConfigResolver ──────────────────────────────────────────────

    public function test_resolve_returns_valid_tenant_config_dto(): void
    {
        $this->makeSetting($this->tenant->id);

        $dto = $this->resolver->resolve($this->tenant->id);

        $this->assertInstanceOf(TenantConfigDTO::class, $dto);
        $this->assertSame($this->tenant->id, $dto->tenant_id);
    }

    public function test_resolve_returns_default_timezone_when_no_setting(): void
    {
        $dto = $this->resolver->resolve($this->tenant->id);

        $this->assertSame('Asia/Jakarta', $dto->timezone);
    }

    public function test_resolve_returns_default_tone_when_no_setting(): void
    {
        $dto = $this->resolver->resolve($this->tenant->id);

        $this->assertSame(TenantTone::SEMI_FORMAL, $dto->tone);
    }

    public function test_resolve_returns_default_business_hours_when_no_setting(): void
    {
        $dto = $this->resolver->resolve($this->tenant->id);

        $this->assertSame('08:00', $dto->business_hours_start);
        $this->assertSame('21:00', $dto->business_hours_end);
    }

    public function test_resolve_reflects_saved_setting(): void
    {
        $this->makeSetting($this->tenant->id, [
            'timezone'             => 'Asia/Makassar',
            'tone'                 => TenantTone::CASUAL->value,
            'business_hours_start' => '09:00',
            'business_hours_end'   => '20:00',
        ]);

        $dto = $this->resolver->resolve($this->tenant->id);

        $this->assertSame('Asia/Makassar', $dto->timezone);
        $this->assertSame(TenantTone::CASUAL, $dto->tone);
        $this->assertSame('09:00', $dto->business_hours_start);
    }

    public function test_cache_prevents_multiple_db_queries_on_resolve(): void
    {
        Cache::flush();

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            if (str_contains($query->sql, 'tenant_settings')) {
                $queryCount++;
            }
        });

        $this->resolver->resolve($this->tenant->id);
        $this->resolver->resolve($this->tenant->id);
        $this->resolver->resolve($this->tenant->id);

        $this->assertSame(1, $queryCount);
    }

    public function test_invalidate_cache_forces_fresh_db_query(): void
    {
        Cache::flush();

        $this->resolver->resolve($this->tenant->id);
        $this->resolver->invalidateCache($this->tenant->id);

        $queryCount = 0;
        DB::listen(function ($query) use (&$queryCount) {
            if (str_contains($query->sql, 'tenant_settings')) {
                $queryCount++;
            }
        });

        $this->resolver->resolve($this->tenant->id);

        $this->assertSame(1, $queryCount);
    }

    // ── BusinessHoursService ──────────────────────────────────────────────

    public function test_is_open_during_business_hours_on_weekday(): void
    {
        $this->makeSetting($this->tenant->id, [
            'timezone'             => 'Asia/Jakarta',
            'business_hours_start' => '08:00',
            'business_hours_end'   => '21:00',
            'business_days'        => [1, 2, 3, 4, 5, 6],
        ]);

        // Monday 10:00 WIB = Monday 03:00 UTC
        $datetime = Carbon::parse('2026-05-11 03:00:00', 'UTC'); // Monday

        $this->assertTrue($this->businessHours->isOpen($this->tenant->id, $datetime));
    }

    public function test_is_closed_after_business_hours(): void
    {
        $this->makeSetting($this->tenant->id, [
            'timezone'             => 'Asia/Jakarta',
            'business_hours_start' => '08:00',
            'business_hours_end'   => '21:00',
            'business_days'        => [1, 2, 3, 4, 5, 6],
        ]);

        // Monday 22:00 WIB = Monday 15:00 UTC
        $datetime = Carbon::parse('2026-05-11 15:00:00', 'UTC'); // Monday

        $this->assertFalse($this->businessHours->isOpen($this->tenant->id, $datetime));
    }

    public function test_is_closed_on_sunday_when_not_in_business_days(): void
    {
        $this->makeSetting($this->tenant->id, [
            'timezone'             => 'Asia/Jakarta',
            'business_hours_start' => '08:00',
            'business_hours_end'   => '21:00',
            'business_days'        => [1, 2, 3, 4, 5, 6], // no Sunday (7)
        ]);

        // Sunday 10:00 WIB = Sunday 03:00 UTC
        $datetime = Carbon::parse('2026-05-10 03:00:00', 'UTC'); // Sunday

        $this->assertFalse($this->businessHours->isOpen($this->tenant->id, $datetime));
    }

    public function test_timezone_conversion_works_correctly(): void
    {
        $this->makeSetting($this->tenant->id, [
            'timezone'             => 'Asia/Jakarta',  // UTC+7
            'business_hours_start' => '08:00',
            'business_hours_end'   => '21:00',
            'business_days'        => [1, 2, 3, 4, 5, 6],
        ]);

        // 01:00 UTC = 08:00 WIB → exactly at open time → should be open
        $datetime = Carbon::parse('2026-05-11 01:00:00', 'UTC'); // Monday

        $this->assertTrue($this->businessHours->isOpen($this->tenant->id, $datetime));
    }

    public function test_is_open_uses_default_when_no_setting(): void
    {
        // Default: Mon-Sat 08:00-21:00 Asia/Jakarta
        // Monday 10:00 WIB = 03:00 UTC
        $datetime = Carbon::parse('2026-05-11 03:00:00', 'UTC'); // Monday

        $this->assertTrue($this->businessHours->isOpen($this->tenant->id, $datetime));
    }

    // ── TenantPolicyService ───────────────────────────────────────────────

    public function test_get_policy_returns_default_when_not_set(): void
    {
        $result = $this->policyService->getPolicy($this->tenant->id, PolicyKey::INVOICE_MAX_RESEND);

        $this->assertSame('3', $result);
    }

    public function test_get_policy_returns_default_for_all_keys(): void
    {
        $this->assertSame('text', $this->policyService->getPolicy($this->tenant->id, PolicyKey::PRICELIST_MODE));
        $this->assertSame('require_customer_name', $this->policyService->getPolicy($this->tenant->id, PolicyKey::PRICELIST_MIN_REQUIREMENT));
        $this->assertSame('queue', $this->policyService->getPolicy($this->tenant->id, PolicyKey::LEAD_LIMIT_FALLBACK));
        $this->assertSame('queue', $this->policyService->getPolicy($this->tenant->id, PolicyKey::AFTER_HOURS_BEHAVIOR));
        $this->assertSame('3', $this->policyService->getPolicy($this->tenant->id, PolicyKey::INVOICE_MAX_RESEND));
        $this->assertSame('true', $this->policyService->getPolicy($this->tenant->id, PolicyKey::CONCURRENT_BOOKING_LOCK));
    }

    public function test_set_policy_persists_and_get_policy_returns_new_value(): void
    {
        $this->policyService->setPolicy(
            $this->tenant->id,
            PolicyKey::AFTER_HOURS_BEHAVIOR,
            'auto_reply'
        );

        $result = $this->policyService->getPolicy($this->tenant->id, PolicyKey::AFTER_HOURS_BEHAVIOR);

        $this->assertSame('auto_reply', $result);
    }

    public function test_set_policy_is_idempotent_on_update(): void
    {
        $this->policyService->setPolicy($this->tenant->id, PolicyKey::INVOICE_MAX_RESEND, '5');
        $this->policyService->setPolicy($this->tenant->id, PolicyKey::INVOICE_MAX_RESEND, '7');

        $result = $this->policyService->getPolicy($this->tenant->id, PolicyKey::INVOICE_MAX_RESEND);

        $this->assertSame('7', $result);
        $this->assertSame(1, TenantPolicy::where('tenant_id', $this->tenant->id)
            ->where('policy_key', PolicyKey::INVOICE_MAX_RESEND->value)->count());
    }

    public function test_get_policies_merges_defaults_with_stored(): void
    {
        $this->policyService->setPolicy($this->tenant->id, PolicyKey::PRICELIST_MODE, 'on_request');

        $policies = $this->policyService->getPolicies($this->tenant->id);

        $this->assertSame('on_request', $policies[PolicyKey::PRICELIST_MODE->value]);
        // Other keys should still have defaults
        $this->assertSame('3', $policies[PolicyKey::INVOICE_MAX_RESEND->value]);
        $this->assertSame('queue', $policies[PolicyKey::AFTER_HOURS_BEHAVIOR->value]);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function makeTenant(): Tenant
    {
        $admin = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Admin',
            'email'     => 'admin@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'Test Tenant',
            'slug'          => 'test-tenant',
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => 'tenant@example.com',
            'created_by_id' => $admin->id,
        ]);
    }

    private function makeSetting(string $tenantId, array $overrides = []): TenantSetting
    {
        return TenantSetting::create(array_merge([
            'id'                   => Str::uuid()->toString(),
            'tenant_id'            => $tenantId,
            'tone'                 => TenantTone::SEMI_FORMAL->value,
            'timezone'             => 'Asia/Jakarta',
            'business_hours_start' => '08:00',
            'business_hours_end'   => '21:00',
            'business_days'        => [1, 2, 3, 4, 5, 6],
            'after_hours_message'  => null,
        ], $overrides));
    }
}
