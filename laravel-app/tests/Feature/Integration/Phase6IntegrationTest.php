<?php

namespace Tests\Feature\Integration;

use App\Modules\Analytics\Services\AnalyticsService;
use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Shared\Contracts\CalendarProviderInterface;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Models\TenantSetting;
use App\Modules\WhatsApp\Models\WaAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase6IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant    $tenant;
    private string    $tenantId;
    private WaAccount $waAccount;
    private Package   $package;
    private User      $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-17 03:00:00', 'UTC'));

        config([
            'cache.default'                       => 'array',
            'queue.default'                       => 'sync',
            'services.wa_gateway.internal_secret' => 'test-secret-p6',
            'services.wa_gateway.secret'          => 'test-secret-p6',
            'services.wa_gateway.url'             => 'http://wa-gateway:3001',
            'services.calendar.provider'          => 'null',
        ]);

        $this->mock = new MockLlmAdapter();
        app()->instance(LlmClientInterface::class, $this->mock);

        Http::fake([
            '*/dispatch'  => Http::response(['success' => true, 'provider_message_id' => 'fake-p6'], 200),
            '*/status/*'  => Http::response(['status' => 'connected'], 200),
        ]);

        $superadmin = User::create([
            'name'      => 'Super P6',
            'email'     => 'super-p6-' . Str::random(5) . '@test.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);

        $plan = Plan::create([
            'code'  => 'PRO-P6',
            'name'  => 'Pro P6',
            'slug'  => 'pro-p6',
            'price' => 0,
        ]);

        $this->tenant   = Tenant::create([
            'name'          => 'P6 Vendor',
            'slug'          => 'p6-v-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'p6@vendor.com',
            'created_by_id' => $superadmin->id,
        ]);
        $this->tenantId = $this->tenant->id;

        $this->adminUser = User::create([
            'name'      => 'Admin P6',
            'email'     => 'admin-p6-' . Str::random(5) . '@vendor.com',
            'password'  => bcrypt('pw'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $this->tenantId,
            'is_active' => true,
        ]);

        $this->waAccount = WaAccount::create([
            'tenant_id' => $this->tenantId,
            'status'    => WaAccountStatus::CONNECTED->value,
            'metadata'  => [],
        ]);
        $this->waAccount->markConnected('+6285500001111');

        $this->package = Package::create([
            'tenant_id'   => $this->tenantId,
            'name'        => 'Paket P6',
            'slug'        => 'pkg-p6',
            'price'       => 10000000,
            'description' => 'desc',
            'is_active'   => true,
        ]);

        $this->enableFeature($plan, $this->tenantId, FeatureKey::ANALYTICS_ADVANCED);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ─── Test 1: Analytics lead funnel reflects real data ────────────────────

    public function test_analytics_lead_funnel_reflects_real_data(): void
    {
        $stages = [
            ['stage' => 'new_lead', 'count' => 3],
            ['stage' => 'qualification', 'count' => 2],
        ];

        foreach ($stages as $s) {
            for ($i = 0; $i < $s['count']; $i++) {
                Conversation::create([
                    'tenant_id'      => $this->tenantId,
                    'customer_phone' => '+628' . Str::random(8),
                    'stage'          => $s['stage'],
                    'agent_mode'     => 'active',
                    'memory_mode'    => 'active',
                    'temperature'    => 'warm',
                ]);
            }
        }

        $service = app(AnalyticsService::class);
        $period  = $service->makePeriod('last_30_days');
        $funnel  = $service->getLeadFunnel($this->tenantId, $period);

        $byStage = collect($funnel)->keyBy('stage');

        $this->assertEquals(3, $byStage['new_lead']->count ?? 0);
        $this->assertEquals(2, $byStage['qualification']->count ?? 0);

        // Percentages: 3/(3+2)*100 = 60%, 2/5*100 = 40%
        $this->assertEquals(60.0, $byStage['new_lead']->percentage ?? 0);
        $this->assertEquals(40.0, $byStage['qualification']->percentage ?? 0);
    }

    // ─── Test 2: Revenue sums only PAID invoices ─────────────────────────────

    public function test_analytics_revenue_sums_paid_invoices_only(): void
    {
        $booking = $this->makeBooking();

        $paidAmounts = [3000000, 5000000];
        foreach ($paidAmounts as $amount) {
            $inv = Invoice::create([
                'tenant_id'      => $this->tenantId,
                'booking_id'     => $booking->id,
                'invoice_number' => 'INV-R-' . Str::random(4),
                'type'           => InvoiceType::DP->value,
                'status'         => InvoiceStatus::PAID->value,
                'amount'         => $amount,
                'due_date'       => now()->subDay(),
                'sent_count'     => 1,
                'paid_at'        => now()->subHour(),
            ]);
        }

        // SENT invoice — should NOT be included
        Invoice::create([
            'tenant_id'      => $this->tenantId,
            'booking_id'     => $booking->id,
            'invoice_number' => 'INV-S-' . Str::random(4),
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::SENT->value,
            'amount'         => 2000000,
            'due_date'       => now()->addDays(3),
            'sent_count'     => 1,
        ]);

        $service  = app(AnalyticsService::class);
        $period   = $service->makePeriod('last_30_days');
        $revenue  = $service->getRevenue($this->tenantId, $period);

        $this->assertEquals(8000000, $revenue->total_revenue);
    }

    // ─── Test 3: Analytics without ANALYTICS_ADVANCED → basic mode ───────────

    public function test_analytics_feature_flag_basic_mode(): void
    {
        // Create a NEW tenant without ANALYTICS_ADVANCED
        $superadmin = User::withoutGlobalScopes()->where('role', UserRole::SUPERADMIN->value)->first();
        $tenant2 = Tenant::create([
            'name'          => 'Basic Tenant',
            'slug'          => 'basic-' . Str::random(4),
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => 'basic@t.com',
            'created_by_id' => $superadmin->id,
        ]);

        $service = app(AnalyticsService::class);
        $period  = $service->makePeriod('last_30_days');
        $summary = $service->getSummary($tenant2->id, $period);

        $this->assertFalse($summary->is_advanced);
        $this->assertEmpty($summary->lead_funnel);
    }

    // ─── Test 4: PDF invoice generated and stored ─────────────────────────────

    public function test_pdf_invoice_generated_and_stored(): void
    {
        Storage::fake();

        $booking = $this->makeBooking();
        $invoice = Invoice::create([
            'tenant_id'      => $this->tenantId,
            'booking_id'     => $booking->id,
            'invoice_number' => 'INV-PDF-001',
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::ISSUED->value,
            'amount'         => 3000000,
            'due_date'       => now()->addDays(7),
            'sent_count'     => 0,
        ]);

        $pdfService = app(\App\Modules\Invoice\Services\InvoicePdfService::class);
        $url        = $pdfService->generate($invoice);

        $this->assertNotEmpty($url);
        $this->assertNotNull($invoice->fresh()->pdf_url);
        $this->assertEquals($url, $invoice->fresh()->pdf_url);
    }

    // ─── Test 5: Email channel invoice sent via Resend ────────────────────────

    public function test_email_channel_invoice_sent_via_resend(): void
    {
        $this->enableFeature($this->getActivePlan(), $this->tenantId, FeatureKey::MULTI_CHANNEL);

        Http::fake([
            'api.resend.com/*' => Http::response(['id' => 'resend-p6-001'], 200),
            '*/dispatch'       => Http::response(['success' => true, 'provider_message_id' => 'wa-p6'], 200),
        ]);

        $conversation = Conversation::create([
            'tenant_id'      => $this->tenantId,
            'customer_phone' => '+6281200001111',
            'customer_email' => 'customer-p6@test.com',
            'channel'        => 'email',
            'stage'          => 'booking',
            'agent_mode'     => 'active',
            'memory_mode'    => 'active',
            'temperature'    => 'warm',
        ]);

        $booking = Booking::create([
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $conversation->id,
            'package_id'      => $this->package->id,
            'booking_code'    => 'BKG-P6-EMAIL',
            'customer_name'   => 'Email Customer',
            'customer_phone'  => '+6281200001111',
            'event_date'      => '2026-10-01',
            'event_type'      => 'resepsi',
            'location'        => 'Jakarta',
            'status'          => BookingStatus::CONFIRMED->value,
            'total_amount'    => 10000000,
            'dp_amount'       => 3000000,
        ]);

        $invoice = Invoice::create([
            'tenant_id'      => $this->tenantId,
            'booking_id'     => $booking->id,
            'invoice_number' => 'INV-P6-EMAIL',
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::ISSUED->value,
            'amount'         => 3000000,
            'due_date'       => now()->addDays(7),
            'sent_count'     => 0,
            'pdf_url'        => 'https://storage.null/null/invoices/p6-email.pdf',
        ]);

        $service = app(InvoiceService::class);
        $result  = $service->send($invoice->fresh());

        $this->assertTrue($result);
        Http::assertSent(fn($req) => str_contains($req->url(), 'resend.com'));
    }

    // ─── Test 6: Multi-channel disabled → WA even for email channel ──────────

    public function test_multi_channel_disabled_uses_wa_for_email_channel(): void
    {
        Http::fake([
            '*/dispatch'       => Http::response(['success' => true, 'provider_message_id' => 'wa-fallback'], 200),
        ]);

        $conversation = Conversation::create([
            'tenant_id'      => $this->tenantId,
            'customer_phone' => '+6281200002222',
            'customer_email' => 'customer-p6b@test.com',
            'channel'        => 'email',
            'stage'          => 'booking',
            'agent_mode'     => 'active',
            'memory_mode'    => 'active',
            'temperature'    => 'warm',
        ]);

        $booking = Booking::create([
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $conversation->id,
            'package_id'      => $this->package->id,
            'booking_code'    => 'BKG-P6-WA-FB',
            'customer_name'   => 'WA Fallback',
            'customer_phone'  => '+6281200002222',
            'event_date'      => '2026-10-02',
            'event_type'      => 'akad',
            'location'        => 'Bandung',
            'status'          => BookingStatus::CONFIRMED->value,
            'total_amount'    => 10000000,
            'dp_amount'       => 3000000,
        ]);

        $invoice = Invoice::create([
            'tenant_id'      => $this->tenantId,
            'booking_id'     => $booking->id,
            'invoice_number' => 'INV-P6-WA-FB',
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::ISSUED->value,
            'amount'         => 3000000,
            'due_date'       => now()->addDays(7),
            'sent_count'     => 0,
            'pdf_url'        => 'https://storage.null/null/invoices/p6-wafb.pdf',
        ]);

        $service = app(InvoiceService::class);
        $result  = $service->send($invoice->fresh());

        $this->assertTrue($result);
        Http::assertSent(fn($req) => str_contains($req->url(), 'dispatch'));
    }

    // ─── Test 7: Export bookings CSV correct columns ──────────────────────────

    public function test_export_booking_csv_correct_columns(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            Booking::create([
                'tenant_id'      => $this->tenantId,
                'package_id'     => $this->package->id,
                'booking_code'   => 'BKG-CSV-00' . $i,
                'customer_name'  => 'Customer ' . $i,
                'customer_phone' => '+628100' . str_pad($i, 5, '0', STR_PAD_LEFT),
                'event_date'     => '2026-1' . $i . '-01',
                'event_type'     => 'resepsi',
                'location'       => 'Kota ' . $i,
                'status'         => BookingStatus::CONFIRMED->value,
                'total_amount'   => 5000000,
                'dp_amount'      => 1500000,
            ]);
        }

        $response = $this->actingAs($this->adminUser)->get(route('export.bookings'));

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        $lines   = array_filter(explode("\n", ltrim($content, "\xEF\xBB\xBF")));

        $this->assertStringContainsString('booking_code', $lines[array_key_first($lines)]);
        $this->assertStringContainsString('customer_name', $lines[array_key_first($lines)]);
        // 3 data rows + 1 header = 4 lines minimum
        $this->assertGreaterThanOrEqual(4, count($lines));
    }

    // ─── Test 8: Google OAuth token refresh when token is expired ────────────

    public function test_google_oauth_token_refresh_when_expired(): void
    {
        $expiredToken = json_encode([
            'access_token'  => 'expired-access-token',
            'refresh_token' => 'valid-refresh-token',
            'expires_at'    => now()->subMinutes(10)->toIso8601String(),
        ]);

        TenantSetting::updateOrCreate(
            ['tenant_id' => $this->tenantId],
            ['google_oauth_token' => $expiredToken]
        );

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-refreshed-access-token',
                'expires_in'   => 3600,
            ], 200),
        ]);

        $oauthService = app(\App\Modules\Calendar\Services\GoogleOAuthService::class);
        $token        = $oauthService->getValidToken($this->tenantId);

        // Token should have been refreshed
        $this->assertNotNull($token);
        $this->assertEquals('new-refreshed-access-token', $token);

        Http::assertSent(fn($req) => str_contains($req->url(), 'oauth2.googleapis.com/token'));

        // Stored token should be updated
        $setting = TenantSetting::where('tenant_id', $this->tenantId)->first();
        $stored  = json_decode($setting->google_oauth_token, true);
        $this->assertEquals('new-refreshed-access-token', $stored['access_token']);
    }

    // ─── Private helpers ─────────────────────────────────────────────────────

    private function makeBooking(): Booking
    {
        return Booking::create([
            'tenant_id'      => $this->tenantId,
            'package_id'     => $this->package->id,
            'booking_code'   => 'BKG-P6-' . Str::random(4),
            'customer_name'  => 'Test Customer',
            'customer_phone' => '+6281200009' . rand(100, 999),
            'event_date'     => '2026-11-01',
            'event_type'     => 'resepsi',
            'location'       => 'Jakarta',
            'status'         => BookingStatus::CONFIRMED->value,
            'total_amount'   => 10000000,
            'dp_amount'      => 3000000,
        ]);
    }

    private function makeBookingDraft(): Booking
    {
        return Booking::create([
            'tenant_id'      => $this->tenantId,
            'package_id'     => $this->package->id,
            'booking_code'   => 'BKG-P6D-' . Str::random(4),
            'customer_name'  => 'Draft Customer',
            'customer_phone' => '+6281200008' . rand(100, 999),
            'event_date'     => '2026-12-01',
            'event_type'     => 'akad',
            'location'       => 'Surabaya',
            'status'         => BookingStatus::DRAFT->value,
            'total_amount'   => 10000000,
            'dp_amount'      => 3000000,
        ]);
    }

    private function enableFeature(Plan $plan, string $tenantId, FeatureKey $key): void
    {
        PlanFeature::firstOrCreate(
            ['plan_id' => $plan->id, 'feature_key' => $key->value],
            ['feature_value' => '1']
        );

        TenantSubscription::updateOrCreate(
            ['tenant_id' => $tenantId, 'plan_id' => $plan->id],
            [
                'status'               => 'active',
                'starts_at'            => now()->subDay(),
                'ends_at'              => now()->addMonth(),
                'current_period_start' => now()->subDay(),
                'current_period_end'   => now()->addMonth(),
            ]
        );
    }

    private function getActivePlan(): Plan
    {
        return Plan::where('code', 'PRO-P6')->first();
    }
}
