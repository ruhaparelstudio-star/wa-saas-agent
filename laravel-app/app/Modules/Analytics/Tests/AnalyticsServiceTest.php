<?php

namespace App\Modules\Analytics\Tests;

use App\Modules\Analytics\Services\AnalyticsService;
use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnalyticsService $service;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));
        Cache::flush();

        $superadmin = $this->makeSuperadmin();

        $planAdvanced = $this->makePlan('growth', [
            FeatureKey::ANALYTICS_ADVANCED->value => 'true',
            FeatureKey::MAX_WA_AGENTS->value       => '2',
            FeatureKey::MONTHLY_LEAD_LIMIT->value  => '-1',
        ]);

        $planBasic = $this->makePlan('starter', [
            FeatureKey::ANALYTICS_ADVANCED->value => 'false',
            FeatureKey::MAX_WA_AGENTS->value       => '1',
            FeatureKey::MONTHLY_LEAD_LIMIT->value  => '100',
        ]);

        $this->tenantA = $this->makeTenant('Vendor A', $superadmin, $planAdvanced);
        $this->tenantB = $this->makeTenant('Vendor B', $superadmin, $planBasic);

        $this->service = app(AnalyticsService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    // ─── getLeadFunnel ────────────────────────────────────────────────────────

    public function test_get_lead_funnel_reflects_stage_distribution(): void
    {
        $period = $this->service->makePeriod('last_30_days');

        $this->makeConversation($this->tenantA->id, ConversationStage::NEW_LEAD);
        $this->makeConversation($this->tenantA->id, ConversationStage::NEW_LEAD);
        $this->makeConversation($this->tenantA->id, ConversationStage::NEW_LEAD);
        $this->makeConversation($this->tenantA->id, ConversationStage::QUALIFICATION);
        $this->makeConversation($this->tenantA->id, ConversationStage::QUALIFICATION);

        $funnel = $this->service->getLeadFunnel($this->tenantA->id, $period);

        $this->assertNotEmpty($funnel);

        $byStage = collect($funnel)->keyBy('stage');
        $this->assertEquals(3, $byStage[ConversationStage::NEW_LEAD->value]->count);
        $this->assertEquals(2, $byStage[ConversationStage::QUALIFICATION->value]->count);
        $this->assertEqualsWithDelta(60.0, $byStage[ConversationStage::NEW_LEAD->value]->percentage, 0.1);
    }

    public function test_get_lead_funnel_empty_when_no_conversations(): void
    {
        $period = $this->service->makePeriod('last_30_days');
        $funnel = $this->service->getLeadFunnel($this->tenantA->id, $period);
        $this->assertEmpty($funnel);
    }

    // ─── getRevenue ───────────────────────────────────────────────────────────

    public function test_get_revenue_sums_only_paid_invoices(): void
    {
        $period = $this->service->makePeriod('last_30_days');

        $conv    = $this->makeConversation($this->tenantA->id, ConversationStage::BOOKING);
        $booking = $this->makeBooking($this->tenantA->id, $conv->id);

        // 2 paid invoices
        $this->makeInvoice($this->tenantA->id, $booking->id, [
            'status'  => InvoiceStatus::PAID->value,
            'amount'  => 5_000_000,
            'type'    => InvoiceType::DP->value,
            'paid_at' => Carbon::now()->subDays(5),
        ]);
        $this->makeInvoice($this->tenantA->id, $booking->id, [
            'status'  => InvoiceStatus::PAID->value,
            'amount'  => 10_000_000,
            'type'    => InvoiceType::PELUNASAN->value,
            'paid_at' => Carbon::now()->subDays(2),
        ]);
        // 1 sent (not paid)
        $this->makeInvoice($this->tenantA->id, $booking->id, [
            'status' => InvoiceStatus::SENT->value,
            'amount' => 3_000_000,
        ]);

        $revenue = $this->service->getRevenue($this->tenantA->id, $period);

        $this->assertEquals(15_000_000, $revenue->total_revenue);
        $this->assertEquals(5_000_000, $revenue->dp_revenue);
        $this->assertEquals(10_000_000, $revenue->pelunasan_revenue);
        $this->assertEquals(2, $revenue->paid_count);
    }

    public function test_get_revenue_zero_when_no_paid_invoices(): void
    {
        $period  = $this->service->makePeriod('last_30_days');
        $revenue = $this->service->getRevenue($this->tenantA->id, $period);
        $this->assertEquals(0, $revenue->total_revenue);
    }

    // ─── getConversion ────────────────────────────────────────────────────────

    public function test_get_conversion_rate_calculation(): void
    {
        $period = $this->service->makePeriod('last_30_days');

        // 10 conversations, 4 have confirmed bookings
        for ($i = 0; $i < 10; $i++) {
            $conv = $this->makeConversation($this->tenantA->id, ConversationStage::EXPLORATION);
            if ($i < 4) {
                $this->makeBooking($this->tenantA->id, $conv->id, [
                    'status' => BookingStatus::CONFIRMED->value,
                ]);
            }
        }

        $conversion = $this->service->getConversion($this->tenantA->id, $period);

        $this->assertEquals(10, $conversion->total_leads);
        $this->assertEquals(4, $conversion->leads_to_booking);
        $this->assertEqualsWithDelta(40.0, $conversion->conversion_rate, 0.1);
    }

    public function test_get_conversion_excludes_draft_bookings(): void
    {
        $period = $this->service->makePeriod('last_30_days');

        $conv = $this->makeConversation($this->tenantA->id, ConversationStage::EXPLORATION);
        $this->makeBooking($this->tenantA->id, $conv->id, [
            'status' => BookingStatus::DRAFT->value,
        ]);

        $conversion = $this->service->getConversion($this->tenantA->id, $period);

        $this->assertEquals(0, $conversion->leads_to_booking);
    }

    // ─── getTopPackages ───────────────────────────────────────────────────────

    public function test_get_top_packages_sorted_by_booking_count(): void
    {
        $period  = $this->service->makePeriod('last_30_days');
        $packageA = $this->makePackage($this->tenantA->id, 'Paket Premium');
        $packageB = $this->makePackage($this->tenantA->id, 'Paket Basic');

        $conv = $this->makeConversation($this->tenantA->id, ConversationStage::BOOKING);

        // 3 bookings for package A, 1 for package B
        for ($i = 0; $i < 3; $i++) {
            $this->makeBooking($this->tenantA->id, $conv->id, [
                'package_id' => $packageA->id,
                'status'     => BookingStatus::CONFIRMED->value,
            ]);
        }
        $this->makeBooking($this->tenantA->id, $conv->id, [
            'package_id' => $packageB->id,
            'status'     => BookingStatus::CONFIRMED->value,
        ]);

        $top = $this->service->getTopPackages($this->tenantA->id, $period);

        $this->assertNotEmpty($top);
        $this->assertEquals('Paket Premium', $top[0]['package_name']);
        $this->assertEquals(3, $top[0]['booking_count']);
    }

    // ─── getSummary ───────────────────────────────────────────────────────────

    public function test_get_summary_with_advanced_feature_enabled(): void
    {
        $period = $this->service->makePeriod('last_30_days');

        $this->makeConversation($this->tenantA->id, ConversationStage::NEW_LEAD);

        $summary = $this->service->getSummary($this->tenantA->id, $period);

        $this->assertTrue($summary->is_advanced);
        $this->assertInstanceOf(\App\Modules\Shared\DTOs\RevenueMetricDTO::class, $summary->revenue);
        $this->assertInstanceOf(\App\Modules\Shared\DTOs\ConversionMetricDTO::class, $summary->conversion);
    }

    public function test_get_summary_with_advanced_feature_disabled_returns_basic(): void
    {
        $period = $this->service->makePeriod('last_30_days');

        $this->makeConversation($this->tenantB->id, ConversationStage::NEW_LEAD);

        $summary = $this->service->getSummary($this->tenantB->id, $period);

        $this->assertFalse($summary->is_advanced);
        $this->assertEmpty($summary->lead_funnel);
        $this->assertEquals(0, $summary->revenue->total_revenue);
        $this->assertEquals(1, $summary->conversion->total_leads); // basic: total leads still counted
    }

    // ─── makePeriod ───────────────────────────────────────────────────────────

    public function test_make_period_last_30_days(): void
    {
        $period = $this->service->makePeriod('last_30_days');

        $this->assertEquals('last_30_days', $period->label);
        $this->assertEqualsWithDelta(30, $period->start_date->diffInDays($period->end_date), 1);
    }

    public function test_make_period_this_month(): void
    {
        $period = $this->service->makePeriod('this_month');

        $this->assertEquals('this_month', $period->label);
        $this->assertEquals(Carbon::now()->startOfMonth()->toDateString(), $period->start_date->toDateString());
    }

    // ─── Tenant Isolation ─────────────────────────────────────────────────────

    public function test_tenant_isolation_revenue_does_not_mix(): void
    {
        $period = $this->service->makePeriod('last_30_days');

        $convA   = $this->makeConversation($this->tenantA->id, ConversationStage::BOOKING);
        $bookingA = $this->makeBooking($this->tenantA->id, $convA->id);
        $this->makeInvoice($this->tenantA->id, $bookingA->id, [
            'status'  => InvoiceStatus::PAID->value,
            'amount'  => 10_000_000,
            'paid_at' => Carbon::now()->subDay(),
        ]);

        $revenueA = $this->service->getRevenue($this->tenantA->id, $period);
        $revenueB = $this->service->getRevenue($this->tenantB->id, $period);

        $this->assertEquals(10_000_000, $revenueA->total_revenue);
        $this->assertEquals(0, $revenueB->total_revenue);
    }

    public function test_tenant_isolation_funnel_does_not_mix(): void
    {
        $period = $this->service->makePeriod('last_30_days');

        $this->makeConversation($this->tenantA->id, ConversationStage::NEW_LEAD);
        $this->makeConversation($this->tenantA->id, ConversationStage::NEW_LEAD);

        $funnelA = $this->service->getLeadFunnel($this->tenantA->id, $period);
        $funnelB = $this->service->getLeadFunnel($this->tenantB->id, $period);

        $this->assertNotEmpty($funnelA);
        $this->assertEmpty($funnelB);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

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
            'id'         => Str::uuid()->toString(),
            'code'       => $code . '-' . Str::random(4),
            'name'       => ucfirst($code),
            'is_active'  => true,
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

    private function makeConversation(string $tenantId, ConversationStage $stage): Conversation
    {
        return Conversation::create([
            'id'               => Str::uuid()->toString(),
            'tenant_id'        => $tenantId,
            'wa_account_id'    => null,
            'customer_phone'   => '+6281112' . rand(100000, 999999),
            'stage'            => $stage->value,
            'agent_mode'       => AgentMode::ACTIVE->value,
            'memory_mode'      => MemoryMode::ACTIVE->value,
            'lead_temperature' => LeadTemperature::WARM->value,
            'last_message_at'  => Carbon::now()->subHour(),
        ]);
    }

    private function makeBooking(string $tenantId, string $conversationId, array $overrides = []): Booking
    {
        $code = 'BKG-' . Carbon::now()->format('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return Booking::create(array_merge([
            'id'              => Str::uuid()->toString(),
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversationId,
            'booking_code'    => $code,
            'status'          => BookingStatus::CONFIRMED->value,
            'event_date'      => Carbon::now()->addMonths(3)->toDateString(),
            'customer_name'   => 'Test Customer',
            'customer_phone'  => '+628111222333',
            'total_amount'    => 15_000_000,
            'dp_amount'       => 5_000_000,
            'metadata'        => [],
        ], $overrides));
    }

    private function makeInvoice(string $tenantId, string $bookingId, array $overrides = []): Invoice
    {
        $num = 'INV-' . Carbon::now()->format('Ym') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        return Invoice::create(array_merge([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $tenantId,
            'booking_id'     => $bookingId,
            'invoice_number' => $num,
            'type'           => InvoiceType::DP->value,
            'status'         => InvoiceStatus::ISSUED->value,
            'amount'         => 5_000_000,
            'due_date'       => Carbon::now()->addDays(7)->toDateString(),
            'sent_count'     => 0,
            'metadata'       => [],
        ], $overrides));
    }

    private function makePackage(string $tenantId, string $name): Package
    {
        return Package::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'name'      => $name,
            'slug'      => Str::slug($name) . '-' . Str::random(4),
            'is_active' => true,
        ]);
    }
}
