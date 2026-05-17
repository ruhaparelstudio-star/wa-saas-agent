<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private User   $user;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset all rate limit counters (array driver is in-memory, flushing is safe)
        Cache::flush();

        $superadmin = User::create([
            'name'      => 'Super RL',
            'email'     => 'super-rl-' . Str::random(5) . '@test.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $this->tenant = Tenant::create([
            'name'          => 'RL Vendor',
            'slug'          => 'rl-v-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'rl@vendor.com',
            'created_by_id' => $superadmin->id,
        ]);

        $this->user = User::create([
            'name'      => 'Admin RL',
            'email'     => 'admin-rl-' . Str::random(5) . '@vendor.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
    }

    public function test_login_throttle_blocks_after_5_attempts(): void
    {
        // Exhaust the 5-attempt limit
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'x@x.com', 'password' => 'wrong']);
        }

        // 6th request must be rate-limited
        $response = $this->postJson('/api/auth/login', ['email' => 'x@x.com', 'password' => 'wrong']);

        $response->assertStatus(429);
        $this->assertEquals('Too many login attempts', $response->json('error'));
    }

    public function test_export_throttle_blocks_after_10_requests(): void
    {
        $this->actingAs($this->user);

        // Exhaust the 10-request-per-hour limit
        for ($i = 0; $i < 10; $i++) {
            $this->get(route('export.bookings'));
        }

        // 11th request must be rate-limited
        $response = $this->get(route('export.bookings'));

        $response->assertStatus(429);
    }

    public function test_webhook_throttle_blocks_after_30_requests(): void
    {
        $accountId = 'webhook-throttle-' . Str::random(8);

        // Exhaust the 30-request-per-minute limit
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/webhook/inbound', ['wa_account_id' => $accountId]);
        }

        // 31st request from the same account must be throttled
        $response = $this->postJson('/webhook/inbound', ['wa_account_id' => $accountId]);

        $response->assertStatus(429);
        $this->assertEquals('Too many requests', $response->json('error'));
    }

    public function test_webhook_rate_limit_headers_present(): void
    {
        $accountId = 'header-test-' . Str::random(6);

        $response = $this->postJson('/webhook/inbound', ['wa_account_id' => $accountId]);

        // Laravel ThrottleRequests adds these headers on every response
        $hasLimit     = $response->headers->has('X-RateLimit-Limit')
                     || $response->headers->has('x-ratelimit-limit');
        $hasRemaining = $response->headers->has('X-RateLimit-Remaining')
                     || $response->headers->has('x-ratelimit-remaining');

        $this->assertTrue($hasLimit, 'X-RateLimit-Limit header must be present');
        $this->assertTrue($hasRemaining, 'X-RateLimit-Remaining header must be present');
    }

    public function test_webhook_throttle_is_per_account_not_global(): void
    {
        // Exhaust limit for account A
        $accountA = 'acct-a-' . Str::random(6);
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/webhook/inbound', ['wa_account_id' => $accountA]);
        }

        // Account A is throttled
        $this->postJson('/webhook/inbound', ['wa_account_id' => $accountA])
            ->assertStatus(429);

        // Account B is NOT throttled — different rate limit key
        $accountB    = 'acct-b-' . Str::random(6);
        $responseB   = $this->postJson('/webhook/inbound', ['wa_account_id' => $accountB]);
        $this->assertNotEquals(429, $responseB->status());
    }

    public function test_rate_limit_resets_after_window_cleared(): void
    {
        // Exhaust the login limit
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'x@x.com', 'password' => 'wrong']);
        }
        $this->postJson('/api/auth/login', ['email' => 'x@x.com', 'password' => 'wrong'])
            ->assertStatus(429);

        // Simulate the rate limit window expiring (flush array cache in test env)
        Cache::flush();

        // Should no longer be throttled
        $response = $this->postJson('/api/auth/login', ['email' => 'x@x.com', 'password' => 'wrong']);
        $this->assertNotEquals(429, $response->status());
    }

    public function test_export_throttle_scoped_per_user(): void
    {
        // Exhaust limit for the first user
        $this->actingAs($this->user);
        for ($i = 0; $i < 10; $i++) {
            $this->get(route('export.bookings'));
        }
        $this->get(route('export.bookings'))->assertStatus(429);

        // A second user should not be throttled (different rate limit key)
        $user2 = User::create([
            'name'      => 'Admin RL2',
            'email'     => 'admin-rl2-' . Str::random(5) . '@vendor.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);

        $this->actingAs($user2);
        $response2 = $this->get(route('export.bookings'));
        $this->assertNotEquals(429, $response2->getStatusCode());
    }
}
