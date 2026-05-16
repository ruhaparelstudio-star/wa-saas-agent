<?php

namespace App\Modules\Shared\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Adapters\EmailGatewayAdapter;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Services\ChannelRegistry;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Adapters\WhatsAppGatewayAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ChannelRegistryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $superadmin = User::create([
            'name'      => 'Super',
            'email'     => 'super-' . Str::random(6) . '@test.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $this->tenant   = Tenant::create([
            'name'          => 'MCTest',
            'slug'          => 'mc-' . Str::random(6),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'mc@test.com',
            'created_by_id' => $superadmin->id,
        ]);
        $this->tenantId = $this->tenant->id;
    }

    private function enableMultiChannel(): void
    {
        $suffix = Str::random(4);
        $plan = Plan::create([
            'code'  => 'MULTI-' . $suffix,
            'name'  => 'Multi-' . $suffix,
            'slug'  => 'multi-' . $suffix,
            'price' => 0,
        ]);

        PlanFeature::create([
            'plan_id'       => $plan->id,
            'feature_key'   => FeatureKey::MULTI_CHANNEL->value,
            'feature_value' => '1',
        ]);

        TenantSubscription::create([
            'tenant_id'            => $this->tenantId,
            'plan_id'              => $plan->id,
            'status'               => 'active',
            'starts_at'            => now()->subDay(),
            'ends_at'              => now()->addMonth(),
            'current_period_start' => now()->subDay(),
            'current_period_end'   => now()->addMonth(),
        ]);
    }

    public function test_multi_channel_disabled_email_returns_whatsapp_adapter(): void
    {
        $registry = app(ChannelRegistry::class);
        $adapter  = $registry->getAdapter('email', $this->tenantId);

        $this->assertInstanceOf(WhatsAppGatewayAdapter::class, $adapter);
    }

    public function test_multi_channel_disabled_whatsapp_returns_whatsapp_adapter(): void
    {
        $registry = app(ChannelRegistry::class);
        $adapter  = $registry->getAdapter('whatsapp', $this->tenantId);

        $this->assertInstanceOf(WhatsAppGatewayAdapter::class, $adapter);
    }

    public function test_multi_channel_enabled_email_returns_email_adapter(): void
    {
        $this->enableMultiChannel();

        $registry = app(ChannelRegistry::class);
        $adapter  = $registry->getAdapter('email', $this->tenantId);

        $this->assertInstanceOf(EmailGatewayAdapter::class, $adapter);
    }

    public function test_multi_channel_enabled_whatsapp_returns_whatsapp_adapter(): void
    {
        $this->enableMultiChannel();

        $registry = app(ChannelRegistry::class);
        $adapter  = $registry->getAdapter('whatsapp', $this->tenantId);

        $this->assertInstanceOf(WhatsAppGatewayAdapter::class, $adapter);
    }

    public function test_email_adapter_send_text_calls_resend_api(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::response(['id' => 'msg-abc123'], 200),
        ]);

        $adapter = app(EmailGatewayAdapter::class);
        $result  = $adapter->sendText('', 'customer@test.com', 'Hello Kak!');

        $this->assertTrue($result);
        Http::assertSent(fn($req) => str_contains($req->url(), 'resend.com'));
    }

    public function test_email_adapter_send_file_includes_download_link(): void
    {
        Http::fake([
            'api.resend.com/*' => Http::response(['id' => 'msg-xyz'], 200),
        ]);

        $adapter = app(EmailGatewayAdapter::class);
        $result  = $adapter->sendFile('', 'customer@test.com', 'https://storage.example.com/invoice.pdf', 'Invoice Kak');

        $this->assertTrue($result);
        Http::assertSent(function ($req) {
            $body = $req->data();
            return str_contains($body['html'] ?? '', 'Download Invoice')
                && str_contains($body['html'] ?? '', 'https://storage.example.com/invoice.pdf');
        });
    }
}
