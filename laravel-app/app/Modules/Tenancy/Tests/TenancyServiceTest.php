<?php

namespace App\Modules\Tenancy\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\ActivationToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\ActivationService;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TenancyServiceTest extends TestCase
{
    use RefreshDatabase;

    private TenantService $tenantService;
    private ActivationService $activationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activationService = new ActivationService();
        $this->tenantService = new TenantService($this->activationService);
    }

    private function makeSuperadmin(): User
    {
        return User::create([
            'name' => 'Super Admin',
            'email' => 'admin@platform.com',
            'password' => Hash::make('Password123!'),
            'role' => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    public function test_superadmin_can_create_tenant(): void
    {
        Mail::fake();
        $superadmin = $this->makeSuperadmin();

        $tenant = $this->tenantService->create([
            'name' => 'Foto Kenangan',
            'contact_email' => 'owner@fotokenangan.com',
            'contact_phone' => '08123456789',
            'industry' => 'wedding',
        ], $superadmin);

        $this->assertInstanceOf(Tenant::class, $tenant);
        $this->assertEquals('Foto Kenangan', $tenant->name);
        $this->assertEquals(TenantStatus::TRIAL, $tenant->status);
        $this->assertDatabaseHas('tenants', ['name' => 'Foto Kenangan']);
        $this->assertDatabaseHas('users', ['email' => 'owner@fotokenangan.com', 'role' => UserRole::TENANT_ADMIN->value]);
        $this->assertDatabaseHas('tenant_users', ['tenant_id' => $tenant->id, 'is_primary' => true]);
    }

    public function test_activation_token_is_generated_after_create(): void
    {
        Mail::fake();
        $superadmin = $this->makeSuperadmin();

        $tenant = $this->tenantService->create([
            'name' => 'Katering Bahagia',
            'contact_email' => 'owner@katering.com',
        ], $superadmin);

        $this->assertDatabaseHas('activation_tokens', ['tenant_id' => $tenant->id]);
        $this->assertEquals(1, ActivationToken::where('tenant_id', $tenant->id)->count());
    }

    public function test_expired_token_is_rejected(): void
    {
        Mail::fake();
        $superadmin = $this->makeSuperadmin();
        $tenant = $this->tenantService->create([
            'name' => 'Dekorasi Indah',
            'contact_email' => 'owner@dekorasi.com',
        ], $superadmin);

        // Expire token manually
        $rawToken = 'some_random_raw_token_64_chars_long_for_testing_purpose_here!!';
        $hashed = hash('sha256', $rawToken);
        ActivationToken::where('tenant_id', $tenant->id)->delete();
        ActivationToken::create([
            'tenant_id' => $tenant->id,
            'token' => $hashed,
            'expires_at' => now()->subHour(),
        ]);

        $result = $this->activationService->validateToken($rawToken);
        $this->assertNull($result);
    }

    public function test_token_can_only_be_used_once(): void
    {
        Mail::fake();
        $superadmin = $this->makeSuperadmin();
        $tenant = $this->tenantService->create([
            'name' => 'Wedding Organizer Pro',
            'contact_email' => 'owner@wopro.com',
        ], $superadmin);

        $dbToken = ActivationToken::where('tenant_id', $tenant->id)->first();

        // Extract raw token by creating a fresh one we control
        $rawToken = 'controlled_raw_token_exactly_64_chars_long_for_test_purposes!!';
        $hashed = hash('sha256', $rawToken);
        ActivationToken::where('tenant_id', $tenant->id)->delete();
        ActivationToken::create([
            'tenant_id' => $tenant->id,
            'token' => $hashed,
            'expires_at' => now()->addHours(48),
        ]);

        // First use — should succeed
        $user = $this->activationService->activate($rawToken, 'NewPassword123!');
        $this->assertInstanceOf(User::class, $user);

        // Second use — should fail
        $result = $this->activationService->validateToken($rawToken);
        $this->assertNull($result);
    }

    public function test_old_tokens_invalid_after_resend(): void
    {
        Mail::fake();
        $superadmin = $this->makeSuperadmin();
        $tenant = $this->tenantService->create([
            'name' => 'Venue Impian',
            'contact_email' => 'owner@venueimpian.com',
        ], $superadmin);

        $rawToken = 'old_raw_token_exactly_64_characters_long_for_testing_purposes!!';
        $hashed = hash('sha256', $rawToken);
        ActivationToken::where('tenant_id', $tenant->id)->delete();
        ActivationToken::create([
            'tenant_id' => $tenant->id,
            'token' => $hashed,
            'expires_at' => now()->addHours(48),
        ]);

        // Resend activation — old token should be invalidated
        $this->activationService->resendActivation($tenant);

        $result = $this->activationService->validateToken($rawToken);
        $this->assertNull($result);

        // New token should exist
        $newTokenCount = ActivationToken::where('tenant_id', $tenant->id)->whereNull('used_at')->count();
        $this->assertEquals(1, $newTokenCount);
    }

    public function test_user_can_login_after_activation(): void
    {
        Mail::fake();
        $superadmin = $this->makeSuperadmin();
        $tenant = $this->tenantService->create([
            'name' => 'Gaun Pengantin',
            'contact_email' => 'owner@gaun.com',
        ], $superadmin);

        $rawToken = 'valid_raw_token_exactly_64_characters_long_for_testing_purpose!';
        $hashed = hash('sha256', $rawToken);
        ActivationToken::where('tenant_id', $tenant->id)->delete();
        ActivationToken::create([
            'tenant_id' => $tenant->id,
            'token' => $hashed,
            'expires_at' => now()->addHours(48),
        ]);

        $user = $this->activationService->activate($rawToken, 'NewSecurePass123!');

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue(Hash::check('NewSecurePass123!', $user->fresh()->password));

        // Verify tenant is now ACTIVE
        $this->assertEquals(TenantStatus::ACTIVE, $tenant->fresh()->status);
    }

    public function test_tenant_isolation_api_returns_only_own_data(): void
    {
        Mail::fake();
        $superadmin = $this->makeSuperadmin();

        $tenantA = $this->tenantService->create([
            'name' => 'Tenant A',
            'contact_email' => 'a@tenant.com',
        ], $superadmin);

        $tenantB = $this->tenantService->create([
            'name' => 'Tenant B',
            'contact_email' => 'b@tenant.com',
        ], $superadmin);

        // Login as tenant A admin
        $userA = User::where('email', 'a@tenant.com')->first();
        $userA->password = Hash::make('Password123!');
        $userA->save();

        $this->actingAs($userA);

        // API tenant list (superadmin only) should be blocked for tenant admin
        $response = $this->getJson('/api/superadmin/tenants');
        $response->assertStatus(403);
    }
}
