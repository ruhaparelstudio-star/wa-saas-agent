<?php

namespace Tests\Feature;

use App\Modules\Auth\Models\User;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Models\TenantSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GoogleOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User   $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google_oauth.client_id'     => 'test-client-id',
            'services.google_oauth.client_secret' => 'test-secret',
            'services.google_oauth.redirect_uri'  => 'http://localhost/callback',
            'services.google_oauth.scopes'        => ['https://www.googleapis.com/auth/calendar.events'],
        ]);

        $superadmin = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Super',
            'email'     => 'super@platform.com',
            'password'  => Hash::make('password'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'id'        => Str::uuid()->toString(),
            'code'      => 'test-plan',
            'name'      => 'Test',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        foreach (FeatureKey::cases() as $key) {
            PlanFeature::create([
                'id'            => Str::uuid()->toString(),
                'plan_id'       => $plan->id,
                'feature_key'   => $key->value,
                'feature_value' => match ($key) {
                    FeatureKey::MAX_WA_AGENTS    => '3',
                    FeatureKey::MONTHLY_LEAD_LIMIT => '-1',
                    default                       => 'false',
                },
            ]);
        }

        $this->tenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'OAuth Flow Vendor',
            'slug'          => 'oauth-flow-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'oauth@vendor.com',
            'created_by_id' => $superadmin->id,
        ]);

        TenantSubscription::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $this->tenant->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'starts_at' => Carbon::now()->subDays(15),
            'ends_at'   => Carbon::now()->addDays(15),
        ]);

        $this->tenantAdmin = User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Tenant Admin',
            'email'     => 'admin@oauth-vendor.com',
            'password'  => Hash::make('password'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
        ]);
    }

    public function test_redirect_endpoint_redirects_to_google(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/calendar/oauth/redirect');

        $response->assertStatus(302);
        $this->assertStringContainsString('accounts.google.com', $response->headers->get('Location'));
    }

    public function test_callback_with_valid_code_stores_token_and_redirects(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token'  => 'ya29.test-token',
                'refresh_token' => 'refresh-test',
                'expires_in'    => 3600,
            ], 200),
        ]);

        $state    = base64_encode($this->tenant->id);
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/calendar/oauth/callback?code=valid-code&state=' . $state);

        // Should redirect to calendar settings
        $response->assertStatus(302);

        $setting = TenantSetting::where('tenant_id', $this->tenant->id)->first();
        $this->assertNotNull($setting);
        $tokenData = json_decode($setting->google_oauth_token, true);
        $this->assertEquals('ya29.test-token', $tokenData['access_token']);
    }

    public function test_callback_with_invalid_state_returns_400(): void
    {
        $wrongTenantId = Str::uuid()->toString();
        $state         = base64_encode($wrongTenantId);

        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/calendar/oauth/callback?code=some-code&state=' . $state);

        $response->assertStatus(400);
    }

    public function test_callback_without_code_redirects_with_error(): void
    {
        $response = $this->actingAs($this->tenantAdmin)
            ->get('/app/calendar/oauth/callback?state=' . base64_encode($this->tenant->id));

        $response->assertStatus(302);
    }
}
