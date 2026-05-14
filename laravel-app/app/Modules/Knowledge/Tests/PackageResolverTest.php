<?php

namespace App\Modules\Knowledge\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Knowledge\Models\PackagePrice;
use App\Modules\Knowledge\Services\PackageResolver;
use App\Modules\Knowledge\Services\PriceResolver;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PackageResolverTest extends TestCase
{
    use RefreshDatabase;

    private PackageResolver $packageResolver;
    private PriceResolver $priceResolver;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        // Force array cache driver in tests to avoid Redis serialization issues
        config(['cache.default' => 'array']);
        Cache::flush();

        $this->packageResolver = app(PackageResolver::class);
        $this->priceResolver   = app(PriceResolver::class);

        $superadmin    = $this->makeSuperadmin();
        $this->tenantA = $this->makeTenant('Vendor A', $superadmin);
        $this->tenantB = $this->makeTenant('Vendor B', $superadmin);
    }

    public function test_get_active_packages_returns_only_active(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket Standard', 'standard', true);
        $this->makePackage($this->tenantA->id, 'Paket Lama', 'lama', false);

        $packages = $this->packageResolver->getActivePackages($this->tenantA->id);

        $this->assertCount(1, $packages);
        $this->assertEquals('Paket Standard', $packages->first()->name);
    }

    public function test_get_package_detail_with_correct_slug(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket Standard', 'standard');

        $pkg = $this->packageResolver->getPackageDetail($this->tenantA->id, 'standard');

        $this->assertNotNull($pkg);
        $this->assertEquals('Paket Standard', $pkg->name);
    }

    public function test_get_package_detail_with_wrong_slug_returns_null(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket Standard', 'standard');

        $pkg = $this->packageResolver->getPackageDetail($this->tenantA->id, 'tidak-ada');

        $this->assertNull($pkg);
    }

    public function test_match_by_name_exact(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket Standard', 'standard');

        $pkg = $this->packageResolver->matchByName($this->tenantA->id, 'Paket Standard');

        $this->assertNotNull($pkg);
        $this->assertEquals('standard', $pkg->slug);
    }

    public function test_match_by_name_case_insensitive(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket Standard', 'standard');

        $pkg = $this->packageResolver->matchByName($this->tenantA->id, 'paket standard');

        $this->assertNotNull($pkg);
    }

    public function test_match_by_name_ilike_partial(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket Standard Wedding', 'standard-wedding');

        $pkg = $this->packageResolver->matchByName($this->tenantA->id, 'Standard');

        $this->assertNotNull($pkg);
    }

    public function test_match_by_name_not_found_returns_null(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket Standard', 'standard');

        $pkg = $this->packageResolver->matchByName($this->tenantA->id, 'Paket Xyz Tidak Ada');

        $this->assertNull($pkg);
    }

    public function test_get_active_price_with_valid_date(): void
    {
        $pkg = $this->makePackage($this->tenantA->id, 'Paket A', 'paket-a');
        $this->makePrice($pkg->id, $this->tenantA->id, 10_000_000, '2026-01-01', null);

        $price = $this->priceResolver->getActivePrice($pkg->id, Carbon::parse('2026-05-14'));

        $this->assertNotNull($price);
        $this->assertEquals(10_000_000, $price->price_idr);
    }

    public function test_get_active_price_with_expired_valid_until_returns_null(): void
    {
        $pkg = $this->makePackage($this->tenantA->id, 'Paket B', 'paket-b');
        $this->makePrice($pkg->id, $this->tenantA->id, 10_000_000, '2026-01-01', '2026-04-30');

        $price = $this->priceResolver->getActivePrice($pkg->id, Carbon::parse('2026-05-14'));

        $this->assertNull($price);
    }

    public function test_get_lowest_current_price(): void
    {
        $pkg1 = $this->makePackage($this->tenantA->id, 'Paket Intimate', 'intimate');
        $pkg2 = $this->makePackage($this->tenantA->id, 'Paket Premium', 'premium');

        $today = Carbon::today()->toDateString();
        $this->makePrice($pkg1->id, $this->tenantA->id, 8_000_000, $today, null);
        $this->makePrice($pkg2->id, $this->tenantA->id, 28_000_000, $today, null);

        $lowest = $this->priceResolver->getLowestCurrentPrice($this->tenantA->id);

        $this->assertNotNull($lowest);
        $this->assertEquals(8_000_000, $lowest->price_idr);
    }

    public function test_cache_prevents_multiple_db_queries(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket A', 'paket-a');

        $queryCount = 0;
        DB::listen(function ($q) use (&$queryCount) {
            if (str_contains($q->sql, 'packages')) {
                $queryCount++;
            }
        });

        $this->packageResolver->getActivePackages($this->tenantA->id);
        $this->packageResolver->getActivePackages($this->tenantA->id);

        $this->assertEquals(1, $queryCount, 'Second call should hit cache, not DB');
    }

    public function test_tenant_isolation_packages(): void
    {
        $this->makePackage($this->tenantA->id, 'Paket Vendor A', 'vendor-a');
        $this->makePackage($this->tenantB->id, 'Paket Vendor B', 'vendor-b');

        $packagesA = $this->packageResolver->getActivePackages($this->tenantA->id);
        $packagesB = $this->packageResolver->getActivePackages($this->tenantB->id);

        $this->assertCount(1, $packagesA);
        $this->assertCount(1, $packagesB);
        $this->assertEquals('Paket Vendor A', $packagesA->first()->name);
        $this->assertEquals('Paket Vendor B', $packagesB->first()->name);
    }

    // ────────────────── helpers ──────────────────

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
        $slug = Str::slug($name) . '-' . Str::random(4);

        return Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => $name,
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);
    }

    private function makePackage(
        string $tenantId,
        string $name,
        string $slug,
        bool $isActive = true,
    ): Package {
        return Package::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'name'      => $name,
            'slug'      => $slug,
            'is_active' => $isActive,
            'sort_order' => 0,
        ]);
    }

    private function makePrice(
        string $packageId,
        string $tenantId,
        int $priceIdr,
        string $validFrom,
        ?string $validUntil,
        bool $isActive = true,
    ): PackagePrice {
        return PackagePrice::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $tenantId,
            'package_id'  => $packageId,
            'label'       => 'Weekday',
            'price_idr'   => $priceIdr,
            'valid_from'  => $validFrom,
            'valid_until' => $validUntil,
            'is_active'   => $isActive,
        ]);
    }
}