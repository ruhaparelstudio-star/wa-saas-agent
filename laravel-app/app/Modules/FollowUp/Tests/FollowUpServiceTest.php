<?php

namespace App\Modules\FollowUp\Tests;

use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Repositories\BookingRepository;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\FollowUp\Jobs\FollowUpJob;
use App\Modules\FollowUp\Models\FollowUpLog;
use App\Modules\FollowUp\Services\FollowUpService;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Repositories\InvoiceRepository;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\FollowUpReason;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Adapters\WhatsAppGatewayAdapter;
use App\Modules\WhatsApp\Models\WaAccount;
use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FollowUpServiceTest extends TestCase
{
    use RefreshDatabase;

    private FollowUpService  $service;
    private FeatureGateService $featureGateService;
    private Tenant   $tenantA;
    private Tenant   $tenantB;
    private WaAccount $waAccountA;
    private Plan $planWithFollowUp;
    private Plan $planWithoutFollowUp;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-16 10:00:00', 'UTC'));
        Cache::flush();

        config(['services.wa_gateway.url' => 'http://wa-gateway-test:3001']);

        Http::fake([
            '*/dispatch' => Http::response(['success' => true, 'provider_message_id' => 'fu-msg-1'], 200),
        ]);

        $superadmin = $this->makeSuperadmin();

        $this->planWithFollowUp = $this->makePlan('growth', [
            FeatureKey::MAX_WA_AGENTS->value         => '2',
            FeatureKey::MONTHLY_LEAD_LIMIT->value     => '500',
            FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'true',
            FeatureKey::FOLLOW_UP_AUTOMATION->value   => 'true',
            FeatureKey::ANALYTICS_ADVANCED->value     => 'false',
        ]);

        $this->planWithoutFollowUp = $this->makePlan('starter', [
            FeatureKey::MAX_WA_AGENTS->value         => '1',
            FeatureKey::MONTHLY_LEAD_LIMIT->value     => '100',
            FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'false',
            FeatureKey::FOLLOW_UP_AUTOMATION->value   => 'false',
            FeatureKey::ANALYTICS_ADVANCED->value     => 'false',
        ]);

        $this->tenantA    = $this->makeTenant('Vendor A', $superadmin, $this->planWithFollowUp);
        $this->tenantB    = $this->makeTenant('Vendor B', $superadmin, $this->planWithoutFollowUp);
        $this->waAccountA = $this->makeWaAccount($this->tenantA->id);

        $this->featureGateService = app(FeatureGateService::class);

        $this->service = new FollowUpService(
            $this->featureGateService,
            new WhatsAppGatewayAdapter(),
            app(ConversationRepository::class),
            app(BookingRepository::class),
            app(InvoiceRepository::class),
            app(WaAccountRepository::class),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    // ─── findCandidates: STALE_LEAD ──────────────────────────────────────────

    public function test_find_candidates_stale_lead_warm_over_24h_returns_candidate(): void
    {
        $this->makeConversation($this->tenantA->id, $this->waAccountA->id, [
            'lead_temperature' => LeadTemperature::WARM->value,
            'last_message_at'  => Carbon::now()->subHours(26),
        ]);

        $candidates = $this->service->findCandidates($this->tenantA->id);

        $reasons = array_column($candidates, 'reason');
        $this->assertContains(FollowUpReason::STALE_LEAD->value, $reasons);
    }

    public function test_find_candidates_stale_lead_warm_under_24h_excluded(): void
    {
        $this->makeConversation($this->tenantA->id, $this->waAccountA->id, [
            'lead_temperature' => LeadTemperature::WARM->value,
            'last_message_at'  => Carbon::now()->subHours(20),
        ]);

        $candidates = $this->service->findCandidates($this->tenantA->id);

        $reasons = array_column($candidates, 'reason');
        $this->assertNotContains(FollowUpReason::STALE_LEAD->value, $reasons);
    }

    public function test_find_candidates_cold_lead_excluded(): void
    {
        $this->makeConversation($this->tenantA->id, $this->waAccountA->id, [
            'lead_temperature' => LeadTemperature::COLD->value,
            'last_message_at'  => Carbon::now()->subHours(48),
        ]);

        $candidates = $this->service->findCandidates($this->tenantA->id);

        $reasons = array_column($candidates, 'reason');
        $this->assertNotContains(FollowUpReason::STALE_LEAD->value, $reasons);
    }

    // ─── findCandidates: BOOKING_PENDING_DP ──────────────────────────────────

    public function test_find_candidates_pending_dp_over_48h_returns_candidate(): void
    {
        $conv    = $this->makeConversation($this->tenantA->id, $this->waAccountA->id);
        $booking = $this->makeBooking($this->tenantA->id, $conv->id, [
            'status'         => BookingStatus::AWAITING_DP->value,
            'customer_phone' => '+628111222444',
        ]);
        // Force updated_at to 50h ago (Eloquent always sets it to now() on create)
        \Illuminate\Support\Facades\DB::table('bookings')
            ->where('id', $booking->id)
            ->update(['updated_at' => Carbon::now()->subHours(50)]);

        $candidates = $this->service->findCandidates($this->tenantA->id);

        $reasons = array_column($candidates, 'reason');
        $this->assertContains(FollowUpReason::BOOKING_PENDING_DP->value, $reasons);
    }

    public function test_find_candidates_pending_dp_under_48h_excluded(): void
    {
        $conv = $this->makeConversation($this->tenantA->id, $this->waAccountA->id);
        // Do NOT override updated_at — fresh create = under 48h
        $this->makeBooking($this->tenantA->id, $conv->id, [
            'status'         => BookingStatus::AWAITING_DP->value,
            'customer_phone' => '+628111222444',
        ]);

        $candidates = $this->service->findCandidates($this->tenantA->id);

        $reasons = array_column($candidates, 'reason');
        $this->assertNotContains(FollowUpReason::BOOKING_PENDING_DP->value, $reasons);
    }

    // ─── findCandidates: INVOICE_OVERDUE ─────────────────────────────────────

    public function test_find_candidates_invoice_overdue_returns_candidate(): void
    {
        $conv    = $this->makeConversation($this->tenantA->id, $this->waAccountA->id);
        $booking = $this->makeBooking($this->tenantA->id, $conv->id, [
            'status'         => BookingStatus::AWAITING_DP->value,
            'customer_phone' => '+628111222555',
        ]);

        $this->makeInvoice($this->tenantA->id, $booking->id, [
            'status'   => InvoiceStatus::SENT->value,
            'due_date' => Carbon::now()->subDays(3)->toDateString(),
        ]);

        $candidates = $this->service->findCandidates($this->tenantA->id);

        $reasons = array_column($candidates, 'reason');
        $this->assertContains(FollowUpReason::INVOICE_OVERDUE->value, $reasons);
    }

    // ─── findCandidates: EVENT_REMINDER_H7 ───────────────────────────────────

    public function test_find_candidates_h7_reminder_for_confirmed_booking(): void
    {
        $conv = $this->makeConversation($this->tenantA->id, $this->waAccountA->id);

        $this->makeBooking($this->tenantA->id, $conv->id, [
            'status'         => BookingStatus::CONFIRMED->value,
            'event_date'     => Carbon::now()->addDays(7)->toDateString(),
            'customer_phone' => '+628111222666',
        ]);

        $candidates = $this->service->findCandidates($this->tenantA->id);

        $reasons = array_column($candidates, 'reason');
        $this->assertContains(FollowUpReason::EVENT_REMINDER_H7->value, $reasons);
    }

    // ─── sendFollowUp ────────────────────────────────────────────────────────

    public function test_send_follow_up_saves_log_and_calls_gateway(): void
    {
        $conv = $this->makeConversation($this->tenantA->id, $this->waAccountA->id, [
            'lead_temperature' => LeadTemperature::WARM->value,
            'last_message_at'  => Carbon::now()->subHours(26),
        ]);

        $candidates = $this->service->findCandidates($this->tenantA->id);
        $this->assertNotEmpty($candidates);

        $result = $this->service->sendFollowUp($candidates[0]);

        $this->assertTrue($result);

        $this->assertDatabaseHas('follow_up_logs', [
            'tenant_id'       => $this->tenantA->id,
            'conversation_id' => $conv->id,
            'reason'          => FollowUpReason::STALE_LEAD->value,
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/dispatch');
        });
    }

    public function test_send_follow_up_duplicate_within_24h_is_blocked(): void
    {
        $conv = $this->makeConversation($this->tenantA->id, $this->waAccountA->id, [
            'lead_temperature' => LeadTemperature::HOT->value,
            'last_message_at'  => Carbon::now()->subHours(30),
        ]);

        $candidates = $this->service->findCandidates($this->tenantA->id);
        $this->assertNotEmpty($candidates);

        $first  = $this->service->sendFollowUp($candidates[0]);
        $second = $this->service->sendFollowUp($candidates[0]);

        $this->assertTrue($first);
        $this->assertFalse($second);

        $this->assertSame(1, FollowUpLog::where('conversation_id', $conv->id)->count());
    }

    public function test_send_follow_up_different_reasons_both_sent(): void
    {
        $conv = $this->makeConversation($this->tenantA->id, $this->waAccountA->id, [
            'lead_temperature' => LeadTemperature::HOT->value,
            'last_message_at'  => Carbon::now()->subHours(30),
            'customer_phone'   => '+628111222777',
        ]);

        $booking = $this->makeBooking($this->tenantA->id, $conv->id, [
            'status'         => BookingStatus::AWAITING_DP->value,
            'customer_phone' => '+628111222777',
        ]);
        // Force updated_at to 50h ago so the pending-DP guard fires
        \Illuminate\Support\Facades\DB::table('bookings')
            ->where('id', $booking->id)
            ->update(['updated_at' => Carbon::now()->subHours(50)]);

        $candidates = $this->service->findCandidates($this->tenantA->id);

        $reasons = array_column($candidates, 'reason');
        $this->assertContains(FollowUpReason::STALE_LEAD->value, $reasons);
        $this->assertContains(FollowUpReason::BOOKING_PENDING_DP->value, $reasons);

        foreach ($candidates as $candidate) {
            $this->service->sendFollowUp($candidate);
        }

        $this->assertSame(2, FollowUpLog::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantA->id)
            ->count());
    }

    // ─── FollowUpJob + feature flag ───────────────────────────────────────────

    public function test_follow_up_job_skips_when_feature_disabled(): void
    {
        // tenantB has FOLLOW_UP_AUTOMATION=false
        $waB = $this->makeWaAccount($this->tenantB->id);
        $this->makeConversation($this->tenantB->id, $waB->id, [
            'lead_temperature' => LeadTemperature::WARM->value,
            'last_message_at'  => Carbon::now()->subHours(30),
        ]);

        $job = new FollowUpJob($this->tenantB->id);
        $job->handle($this->service, $this->featureGateService);

        $this->assertSame(0, FollowUpLog::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantB->id)
            ->count());

        Http::assertNothingSent();
    }

    // ─── Tenant isolation ─────────────────────────────────────────────────────

    public function test_tenant_isolation_candidates_only_from_own_tenant(): void
    {
        $waB = $this->makeWaAccount($this->tenantB->id);

        // Create stale lead for tenantB
        $this->makeConversation($this->tenantB->id, $waB->id, [
            'lead_temperature' => LeadTemperature::WARM->value,
            'last_message_at'  => Carbon::now()->subHours(30),
        ]);

        // tenantA has no leads — candidates should be empty
        $candidates = $this->service->findCandidates($this->tenantA->id);

        $this->assertEmpty($candidates);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin',
            'email'     => 'admin-' . Str::random(6) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makePlan(string $code, array $features): Plan
    {
        $plan = Plan::create([
            'id'        => Str::uuid()->toString(),
            'code'      => $code . '-' . Str::random(4),
            'name'      => ucfirst($code),
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
        $slug   = Str::slug($name) . '-' . Str::random(4);
        $tenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => $name,
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE->value,
            'industry'      => 'wedding',
            'contact_email' => $slug . '@example.com',
            'created_by_id' => $createdBy->id,
        ]);

        TenantSubscription::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'plan_id'   => $plan->id,
            'status'    => 'active',
            'starts_at' => Carbon::now()->subDays(15),
            'ends_at'   => Carbon::now()->addDays(15),
        ]);

        return $tenant;
    }

    private function makeWaAccount(string $tenantId): WaAccount
    {
        return WaAccount::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'status'    => WaAccountStatus::CONNECTED->value,
            'metadata'  => [],
        ]);
    }

    private function makeConversation(string $tenantId, string $waAccountId, array $overrides = []): Conversation
    {
        return Conversation::create(array_merge([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $tenantId,
            'wa_account_id'  => $waAccountId,
            'customer_phone' => '+6281112223' . rand(10, 99),
            'stage'          => ConversationStage::EXPLORATION->value,
            'agent_mode'     => AgentMode::ACTIVE->value,
            'memory_mode'    => MemoryMode::ACTIVE->value,
            'lead_temperature' => LeadTemperature::WARM->value,
            'last_message_at'  => Carbon::now()->subHours(26),
        ], $overrides));
    }

    private function makeBooking(string $tenantId, string $conversationId, array $overrides = []): Booking
    {
        $code = 'BKG-' . Carbon::now()->format('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return Booking::create(array_merge([
            'id'              => Str::uuid()->toString(),
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversationId,
            'booking_code'    => $code,
            'status'          => BookingStatus::DRAFT->value,
            'event_date'      => Carbon::now()->addMonths(3)->toDateString(),
            'customer_phone'  => '+628111222333',
            'total_amount'    => 15_000_000,
            'dp_amount'       => 5_000_000,
            'metadata'        => [],
        ], $overrides));
    }

    private function makeInvoice(string $tenantId, string $bookingId, array $overrides = []): Invoice
    {
        $prefix = 'INV-' . Carbon::now()->format('Ym') . '-';
        $num    = $prefix . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return Invoice::create(array_merge([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $tenantId,
            'booking_id'     => $bookingId,
            'invoice_number' => $num,
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::ISSUED->value,
            'amount'         => 5_000_000,
            'due_date'       => Carbon::now()->addDays(7)->toDateString(),
            'sent_count'     => 1,
            'metadata'       => [],
        ], $overrides));
    }
}
