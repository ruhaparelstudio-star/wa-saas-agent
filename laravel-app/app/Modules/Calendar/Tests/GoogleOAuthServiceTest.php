<?php

namespace App\Modules\Calendar\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Calendar\Services\GoogleOAuthService;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Models\TenantSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GoogleOAuthServiceTest extends TestCase
{
    use RefreshDatabase;

    private GoogleOAuthService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(GoogleOAuthService::class);

        config([
            'services.google_oauth.client_id'     => 'test-client-id',
            'services.google_oauth.client_secret' => 'test-secret',
            'services.google_oauth.redirect_uri'  => 'http://localhost/callback',
            'services.google_oauth.scopes'        => ['https://www.googleapis.com/auth/calendar.events'],
        ]);

        $suffix     = Str::random(6);
        $superadmin = User::create([
            'name'      => 'Super',
            'email'     => "super-{$suffix}@oauth-test.com",
            'password'  => Hash::make('password'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $tenant = Tenant::create([
            'name'          => 'OAuth Test Vendor',
            'slug'          => "oauth-test-{$suffix}",
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => "oauth-{$suffix}@test.com",
            'created_by_id' => $superadmin->id,
        ]);

        $this->tenantId = $tenant->id;
    }

    public function test_get_authorization_url_contains_required_params(): void
    {
        $url = $this->service->getAuthorizationUrl($this->tenantId);

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('test-client-id', $url);
        $this->assertStringContainsString('calendar.events', $url);
        $this->assertStringContainsString('redirect_uri', $url);
        $this->assertStringContainsString(base64_encode($this->tenantId), $url);
    }

    public function test_exchange_code_stores_token_in_tenant_settings(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token'  => 'ya29.test-access',
                'refresh_token' => 'refresh-token-123',
                'expires_in'    => 3600,
                'token_type'    => 'Bearer',
            ], 200),
        ]);

        $state  = base64_encode($this->tenantId);
        $result = $this->service->exchangeCode('auth-code-123', $state);

        $this->assertNotNull($result);
        $this->assertEquals('ya29.test-access', $result['access_token']);

        $setting = TenantSetting::where('tenant_id', $this->tenantId)->first();
        $this->assertNotNull($setting);
        $tokenData = json_decode($setting->google_oauth_token, true);
        $this->assertEquals('ya29.test-access', $tokenData['access_token']);
        $this->assertEquals('refresh-token-123', $tokenData['refresh_token']);
        $this->assertNotEmpty($tokenData['expires_at']);
    }

    public function test_refresh_access_token_updates_stored_token(): void
    {
        TenantSetting::create([
            'tenant_id' => $this->tenantId,
            'google_oauth_token' => json_encode([
                'access_token'  => 'old-token',
                'refresh_token' => 'refresh-token-456',
                'expires_at'    => Carbon::now()->subHour()->toIso8601String(),
            ]),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access-token',
                'expires_in'   => 3600,
            ], 200),
        ]);

        $newToken = $this->service->refreshAccessToken($this->tenantId);

        $this->assertEquals('new-access-token', $newToken);

        $setting = TenantSetting::where('tenant_id', $this->tenantId)->first();
        $data    = json_decode($setting->google_oauth_token, true);
        $this->assertEquals('new-access-token', $data['access_token']);
        $this->assertEquals('refresh-token-456', $data['refresh_token']); // preserved
    }

    public function test_get_valid_token_returns_token_when_still_valid(): void
    {
        Http::fake(); // should not be called

        TenantSetting::create([
            'tenant_id' => $this->tenantId,
            'google_oauth_token' => json_encode([
                'access_token'  => 'still-valid-token',
                'refresh_token' => 'refresh-xyz',
                'expires_at'    => Carbon::now()->addHour()->toIso8601String(),
            ]),
        ]);

        $token = $this->service->getValidToken($this->tenantId);

        $this->assertEquals('still-valid-token', $token);
        Http::assertNothingSent();
    }

    public function test_get_valid_token_refreshes_when_near_expiry(): void
    {
        TenantSetting::create([
            'tenant_id' => $this->tenantId,
            'google_oauth_token' => json_encode([
                'access_token'  => 'expiring-soon',
                'refresh_token' => 'refresh-abc',
                'expires_at'    => Carbon::now()->addSeconds(200)->toIso8601String(), // < 5 min
            ]),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-token',
                'expires_in'   => 3600,
            ], 200),
        ]);

        $token = $this->service->getValidToken($this->tenantId);

        $this->assertEquals('fresh-token', $token);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth2.googleapis.com/token'));
    }

    public function test_refresh_access_token_returns_null_on_failure(): void
    {
        TenantSetting::create([
            'tenant_id' => $this->tenantId,
            'google_oauth_token' => json_encode([
                'access_token'  => 'old',
                'refresh_token' => 'bad-refresh',
                'expires_at'    => Carbon::now()->subMinute()->toIso8601String(),
            ]),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $result = $this->service->refreshAccessToken($this->tenantId);

        $this->assertNull($result);
    }

    public function test_revoke_token_clears_stored_token(): void
    {
        TenantSetting::create([
            'tenant_id' => $this->tenantId,
            'google_oauth_token' => json_encode([
                'access_token'  => 'token-to-revoke',
                'refresh_token' => 'refresh-to-revoke',
            ]),
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/revoke' => Http::response([], 200),
        ]);

        $this->service->revokeToken($this->tenantId);

        Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth2.googleapis.com/revoke'));

        $setting = TenantSetting::where('tenant_id', $this->tenantId)->first();
        $this->assertNull($setting->google_oauth_token);
    }
}
