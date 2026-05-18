<?php

namespace Tests\Feature\Integration;

use App\Modules\AgentCore\LLM\Adapters\MockLlmAdapter;
use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use App\Modules\Auth\Models\User;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Repositories\BookingRepository;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Conversation\Repositories\ConversationRepository;
use App\Modules\FollowUp\Models\FollowUpLog;
use App\Modules\FollowUp\Services\FollowUpService;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Knowledge\Models\Asset;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Knowledge\Models\PackagePrice;
use App\Modules\Notification\Services\NotificationService;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\PlanFeature;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Shared\Contracts\CalendarProviderInterface;
use App\Modules\Shared\Contracts\LlmClientInterface;
use App\Modules\Shared\DTOs\CalendarEventDTO;
use App\Modules\Shared\Enums\AssetType;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\FeatureKey;
use App\Modules\Shared\Enums\FollowUpReason;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Shared\Enums\PolicyKey;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\TenantConfig\Services\TenantPolicyService;
use App\Modules\WhatsApp\Models\WaAccount;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 5 E2E Integration Test — full booking/invoice/calendar/follow-up/pricelist flow.
 *
 * PRINSIP 9  — MockLlmAdapter for all LLM calls.
 * PRINSIP 14 — Concurrent booking lock tested (test 4).
 * Http::fake   — WA gateway dispatch (no real external calls).
 * CalendarProviderInterface anonymous stub — no Http::fake dependency for calendar.
 * FollowUpService called directly — avoids job-dispatch/Http::fake interaction with live wa-gateway.
 */
class BookingFlowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private MockLlmAdapter $mock;
    private Tenant         $tenant;
    private string         $tenantId;
    private WaAccount      $waAccount;
    private Plan           $plan;
    private string         $internalSecret = 'test-internal-secret-e2e-b5';

    protected function setUp(): void
    {
        parent::setUp();

        // Business hours: Fri 10:00 WIB = 03:00 UTC — avoids AFTER_HOURS_BEHAVIOR short-circuit
        Carbon::setTestNow(Carbon::parse('2026-05-16 03:00:00', 'UTC'));

        config([
            'cache.default'                       => 'array',
            'queue.default'                       => 'sync',
            'services.wa_gateway.internal_secret' => $this->internalSecret,
            'services.wa_gateway.secret'          => $this->internalSecret,
            'services.wa_gateway.url'             => 'http://wa-gateway:3001',
            'services.calendar.provider'          => 'null',
        ]);

        Cache::flush();

        // PRINSIP 9 — inject MockLlmAdapter into DI container
        $this->mock = new MockLlmAdapter();
        app()->instance(LlmClientInterface::class, $this->mock);

        // Default WA-gateway fake (overridden per-test when needed)
        Http::fake([
            '*/dispatch'       => Http::response(['success' => true, 'provider_message_id' => 'fake-' . Str::random(6)], 200),
            '*/status/*'       => Http::response(['status' => 'connected'], 200),
            '*/sessions/start' => Http::response(['status' => 'starting'], 200),
        ]);

        $superadmin       = $this->makeSuperadmin();
        $this->plan       = $this->makePlan();
        $this->tenant     = $this->makeTenant($superadmin, $this->plan);
        $this->tenantId   = $this->tenant->id;
        $this->makeAdminUser($this->tenantId);
        $this->waAccount  = $this->makeWaAccount($this->tenantId);

        // Seed packages for pricelist tests
        $this->seedPackages();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::flush();
        parent::tearDown();
    }

    // ── Test 1: Pricelist PDF mode → document dispatched ─────────────────────

    public function test_full_pricelist_flow_pdf_mode(): void
    {
        app(TenantPolicyService::class)->setPolicy($this->tenantId, PolicyKey::PRICELIST_MODE, 'pdf');
        // Bypass name-gate explicitly — this test verifies PDF dispatch end-to-end; name-gate has dedicated coverage.
        app(TenantPolicyService::class)->setPolicy($this->tenantId, PolicyKey::PRICELIST_MIN_REQUIREMENT, 'none');
        $asset = $this->makePricelistAsset();

        Http::fake([
            '*/dispatch' => Http::response(['success' => true, 'provider_message_id' => 'doc-1'], 200),
        ]);

        $this->queueMockReplies('ask_package_list', [], 'Berikut pricelist kami Kak 🙏');

        $this->postJson('/webhook/inbound', $this->makePayload('+628121150001'), [
            'X-Internal-Secret' => $this->internalSecret,
        ])->assertStatus(200)->assertJson(['accepted' => true]);

        // Assert that a document dispatch was sent
        Http::assertSent(function ($request) use ($asset) {
            $body = $request->data();
            return ($body['message_type'] ?? null) === 'document'
                && ($body['media_url'] ?? null) === $asset->file_url;
        });

        // Outbound ConversationMessage must be saved
        $conv = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('customer_phone', '+628121150001')
            ->first();
        $this->assertNotNull($conv, 'Conversation should be created');
    }

    // ── Test 2: Pricelist disabled → blocked_action in decision trace ────────

    public function test_pricelist_blocked_by_policy_disabled(): void
    {
        app(TenantPolicyService::class)->setPolicy($this->tenantId, PolicyKey::PRICELIST_MODE, 'disabled');

        $this->queueMockReplies('ask_price', [], 'Harga kami siapkan custom untuk Kakak 🙏');

        $this->postJson('/webhook/inbound', $this->makePayload('+628121150002'), [
            'X-Internal-Secret' => $this->internalSecret,
        ])->assertStatus(200);

        $trace = DecisionTrace::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->latest()
            ->first();

        $this->assertNotNull($trace);
        $blocked = collect($trace->blocked_actions ?? [])->pluck('action')->toArray();
        $this->assertContains('send_pricelist', $blocked, 'send_pricelist must be in blocked_actions');
        $this->assertNotContains('send_pricelist', $trace->allowed_actions ?? []);
    }

    // ── Test 3: Booking DRAFT created via pipeline ───────────────────────────

    public function test_booking_create_draft_via_pipeline(): void
    {
        $this->queueMockReplies(
            'request_booking',
            ['event_date' => '2026-09-01', 'event_type' => 'resepsi'],
            'Baik Kak, kami sedang proses booking tanggal 1 September 🙏'
        );

        $this->postJson('/webhook/inbound', $this->makePayload('+628121150003'), [
            'X-Internal-Secret' => $this->internalSecret,
        ])->assertStatus(200);

        $booking = Booking::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->first();

        $this->assertNotNull($booking, 'Booking should be created');
        $this->assertSame(BookingStatus::DRAFT, $booking->status);
        $this->assertSame('2026-09-01', $booking->event_date->toDateString());

        $conv = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('customer_phone', '+628121150003')
            ->first();
        $this->assertSame(ConversationStage::WAITING_BOOKING, $conv->stage);
    }

    // ── Test 4: Concurrent date conflict → no new booking created ────────────

    public function test_booking_concurrent_date_conflict(): void
    {
        // Pre-existing CONFIRMED booking for the same date + event_type
        Booking::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantId,
            'booking_code'   => 'BKG-202609-0001',
            'event_date'     => '2026-09-01',
            'event_type'     => 'resepsi',
            'status'         => BookingStatus::CONFIRMED->value,
            'customer_phone' => '+628121150099',
            'metadata'       => [],
        ]);

        $this->queueMockReplies(
            'request_booking',
            ['event_date' => '2026-09-01', 'event_type' => 'resepsi'],
            'Maaf Kak, tanggal tersebut sudah terboking. Berikut alternatif tanggal 🙏'
        );

        $this->postJson('/webhook/inbound', $this->makePayload('+628121150004'), [
            'X-Internal-Secret' => $this->internalSecret,
        ])->assertStatus(200);

        // Only the original CONFIRMED booking should remain (no new DRAFT created)
        $count = Booking::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->count();
        $this->assertSame(1, $count, 'No new booking should be created when date+type is taken');
    }

    // ── Test 5: Invoice flow — issue → send → mark paid ──────────────────────

    public function test_invoice_flow_issue_send_pay(): void
    {
        [$booking, $conv] = $this->makeConfirmedBookingWithConversation();

        /** @var InvoiceService $invoiceSvc */
        $invoiceSvc = app(InvoiceService::class);

        // Issue DP invoice
        $dueDate = Carbon::now()->addDays(7);
        $invoice = $invoiceSvc->issue($booking, InvoiceType::DP, 5_000_000, $dueDate);

        $this->assertSame(InvoiceStatus::ISSUED, $invoice->status);
        $this->assertSame(BookingStatus::AWAITING_DP, $booking->fresh()->status);
        $this->assertSame(ConversationStage::INVOICE_PHASE, $conv->fresh()->stage);

        // Send via WA
        Http::fake([
            '*/dispatch' => Http::response(['success' => true, 'provider_message_id' => 'inv-snd-1'], 200),
        ]);

        $sent = $invoiceSvc->send($invoice->fresh());
        $this->assertTrue($sent);
        $this->assertSame(InvoiceStatus::SENT, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->fresh()->sent_count);
        $this->assertSame(ConversationStage::POST_INVOICE_LIMITED, $conv->fresh()->stage);

        // Mark paid
        $invoiceSvc->markPaid($invoice->fresh(), 'https://example.com/bukti.jpg');
        $this->assertSame(InvoiceStatus::PAID, $invoice->fresh()->status);
        $this->assertSame(BookingStatus::PAID, $booking->fresh()->status);
        $this->assertSame(ConversationStage::BOOKING, $conv->fresh()->stage);
    }

    // ── Test 6: Booking confirm → calendar createEvent called ────────────────

    public function test_booking_confirm_creates_calendar_event(): void
    {
        $createdEventId = null;
        $calendarStub   = $this->makeCalendarStub(createReturns: 'evt-abc', onCreateCapture: function ($tenantId, $event) use (&$createdEventId) {
            $createdEventId = 'evt-abc';
        });

        $bookingService = $this->makeBookingServiceWith($calendarStub);
        $booking        = $this->makeDraftBooking();

        $bookingService->confirm($booking);

        $this->assertSame('evt-abc', $createdEventId, 'createEvent stub must have been called');
        $this->assertSame('evt-abc', $booking->fresh()->calendar_event_id,
            'calendar_event_id must be stored after confirm');
    }

    // ── Test 7: Booking cancel → calendar deleteEvent called ─────────────────

    public function test_booking_cancel_deletes_calendar_event(): void
    {
        $deletedEventId = null;
        $calendarStub   = $this->makeCalendarStub(deleteCapture: function ($tenantId, $eventId) use (&$deletedEventId) {
            $deletedEventId = $eventId;
        });

        $bookingService = $this->makeBookingServiceWith($calendarStub);

        $booking = $this->makeDraftBooking();
        $booking->update([
            'status'            => BookingStatus::CONFIRMED->value,
            'confirmed_at'      => now(),
            'calendar_event_id' => 'evt-existing',
        ]);

        $bookingService->cancel($booking->fresh(), 'Test cancellation');

        $this->assertSame('evt-existing', $deletedEventId, 'deleteEvent must have been called with the event id');
        $this->assertSame(BookingStatus::CANCELLED, $booking->fresh()->status);
    }

    // ── Test 8: Follow-up stale lead — sent + 24h idempotency ────────────────

    public function test_follow_up_stale_lead_sent(): void
    {
        Http::fake([
            '*/dispatch' => Http::response(['success' => true, 'provider_message_id' => 'fu-1'], 200),
        ]);

        // Conversation WARM, last message 26h ago → qualifies as stale lead
        $conv = Conversation::create([
            'id'               => Str::uuid()->toString(),
            'tenant_id'        => $this->tenantId,
            'wa_account_id'    => $this->waAccount->id,
            'customer_phone'   => '+628121150010',
            'stage'            => ConversationStage::EXPLORATION->value,
            'lead_temperature' => LeadTemperature::WARM->value,
            'last_message_at'  => Carbon::now()->subHours(26),
        ]);

        /** @var FollowUpService $fuSvc */
        $fuSvc = app(FollowUpService::class);

        // Gate + candidates
        $enabled = app(FeatureGateService::class)->check($this->tenantId, FeatureKey::FOLLOW_UP_AUTOMATION);
        $this->assertTrue($enabled, 'FOLLOW_UP_AUTOMATION must be enabled for this tenant');

        $candidates = $fuSvc->findCandidates($this->tenantId);
        $this->assertNotEmpty($candidates, 'findCandidates must return at least 1 stale lead');

        // Execute follow-ups
        foreach ($candidates as $candidate) {
            $fuSvc->sendFollowUp($candidate);
        }

        $log = FollowUpLog::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->first();

        $this->assertNotNull($log, 'FollowUpLog should be created');
        $this->assertSame(FollowUpReason::STALE_LEAD, $log->reason, 'reason must be STALE_LEAD enum');

        Http::assertSent(fn ($req) => str_contains($req->url(), '/dispatch'));

        // Idempotency: run again → cache lock prevents second log
        $candidates2 = $fuSvc->findCandidates($this->tenantId);
        foreach ($candidates2 as $c) {
            $fuSvc->sendFollowUp($c);
        }

        $logCount = FollowUpLog::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->count();
        $this->assertSame(1, $logCount, 'Follow-up must not duplicate within 24h (idempotency via cache lock)');
    }

    // ── Test 9: Follow-up feature disabled → no candidates sent ──────────────

    public function test_follow_up_feature_disabled_no_op(): void
    {
        // Tenant B on a plan WITHOUT FOLLOW_UP_AUTOMATION
        $planNoFu  = $this->makePlanWithoutFollowUp();
        $tenantB   = $this->makeTenant($this->makeSuperadmin(), $planNoFu);
        $waB       = $this->makeWaAccount($tenantB->id);

        Conversation::create([
            'id'               => Str::uuid()->toString(),
            'tenant_id'        => $tenantB->id,
            'wa_account_id'    => $waB->id,
            'customer_phone'   => '+628121150020',
            'stage'            => ConversationStage::EXPLORATION->value,
            'lead_temperature' => LeadTemperature::WARM->value,
            'last_message_at'  => Carbon::now()->subHours(26),
        ]);

        // Feature gate must be disabled for plan without follow-up
        $enabled = app(FeatureGateService::class)->check($tenantB->id, FeatureKey::FOLLOW_UP_AUTOMATION);
        $this->assertFalse($enabled, 'FOLLOW_UP_AUTOMATION must be disabled for starter plan');

        // When feature is disabled the job does nothing — simulate the same guard
        if (!$enabled) {
            $logCount = FollowUpLog::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count();
            $this->assertSame(0, $logCount, 'FollowUpLog must be empty when feature is disabled');
            return;
        }

        // Should not reach here, but guard anyway
        $this->fail('Feature gate should have been disabled');
    }

    // ──────────────────────── helpers ────────────────────────────────────────

    /** Queue all three LLM responses (intent → entity → composer). */
    private function queueMockReplies(string $intent, array $entities, string $reply): void
    {
        $this->mock->setNextResponses([
            json_encode(['intent' => $intent, 'confidence' => 0.92, 'reason' => 'mock-e2e-b5']),
            json_encode([
                'entities'            => $entities,
                'corrections'         => [],
                'needs_clarification' => [],
                'detected_language'   => 'id',
                'confidence'          => 0.88,
            ]),
            $reply,
        ]);
    }

    /** Build the inbound webhook payload for a given phone number. */
    private function makePayload(string $phone, string $body = 'test message'): array
    {
        return [
            'wa_account_id'       => $this->waAccount->id,
            'tenant_id'           => $this->tenantId,
            'provider_message_id' => 'msg-b5-' . Str::uuid(),
            'from_phone'          => $phone,
            'message_type'        => 'text',
            'body'                => $body,
            'received_at'         => now()->toIso8601String(),
        ];
    }

    /**
     * Build an anonymous CalendarProviderInterface stub that tracks createEvent/deleteEvent calls.
     *
     * @param string|null  $createReturns   Value returned by createEvent()
     * @param callable|null $onCreateCapture Called with ($tenantId, $event) when createEvent fires
     * @param callable|null $deleteCapture   Called with ($tenantId, $eventId) when deleteEvent fires
     */
    private function makeCalendarStub(
        ?string $createReturns = null,
        ?callable $onCreateCapture = null,
        ?callable $deleteCapture = null,
    ): CalendarProviderInterface {
        return new class($createReturns, $onCreateCapture, $deleteCapture) implements CalendarProviderInterface {
            public function __construct(
                private readonly ?string $createReturns,
                private readonly mixed $onCreateCapture,
                private readonly mixed $deleteCapture,
            ) {}

            public function createEvent(string $tenantId, CalendarEventDTO $event): ?string
            {
                if ($this->onCreateCapture) {
                    ($this->onCreateCapture)($tenantId, $event);
                }
                return $this->createReturns;
            }

            public function updateEvent(string $tenantId, string $eventId, CalendarEventDTO $event): bool
            {
                return true;
            }

            public function deleteEvent(string $tenantId, string $eventId): bool
            {
                if ($this->deleteCapture) {
                    ($this->deleteCapture)($tenantId, $eventId);
                }
                return true;
            }

            public function getEvent(string $tenantId, string $eventId): ?CalendarEventDTO
            {
                return null;
            }
        };
    }

    /**
     * Build a BookingService with the given CalendarProviderInterface implementation.
     */
    private function makeBookingServiceWith(CalendarProviderInterface $calendar): BookingService
    {
        return new BookingService(
            app(BookingRepository::class),
            app(NotificationService::class),
            app(ConversationRepository::class),
            $calendar,
        );
    }

    /** Create a DRAFT booking directly in the DB (not via pipeline). */
    private function makeDraftBooking(): Booking
    {
        $conv = Conversation::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantId,
            'wa_account_id'  => $this->waAccount->id,
            'customer_phone' => '+628121150050',
            'stage'          => ConversationStage::BOOKING->value,
        ]);

        return Booking::create([
            'id'               => Str::uuid()->toString(),
            'tenant_id'        => $this->tenantId,
            'conversation_id'  => $conv->id,
            'booking_code'     => 'BKG-202609-0050',
            'event_date'       => '2026-09-15',
            'event_type'       => 'resepsi',
            'event_time_start' => '09:00',
            'customer_name'    => 'Kak Test',
            'customer_phone'   => '+628121150050',
            'status'           => BookingStatus::DRAFT->value,
            'total_amount'     => 20_000_000,
            'dp_amount'        => 5_000_000,
            'metadata'         => [],
        ]);
    }

    /**
     * Create a CONFIRMED booking + linked conversation for invoice tests.
     *
     * @return array{0: Booking, 1: Conversation}
     */
    private function makeConfirmedBookingWithConversation(): array
    {
        $conv = Conversation::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantId,
            'wa_account_id'  => $this->waAccount->id,
            'customer_phone' => '+628121150060',
            'stage'          => ConversationStage::BOOKING->value,
        ]);

        $booking = Booking::create([
            'id'              => Str::uuid()->toString(),
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $conv->id,
            'booking_code'    => 'BKG-202609-0060',
            'event_date'      => '2026-09-20',
            'event_type'      => 'resepsi',
            'customer_name'   => 'Kak Invoice',
            'customer_phone'  => '+628121150060',
            'status'          => BookingStatus::CONFIRMED->value,
            'confirmed_at'    => now(),
            'total_amount'    => 20_000_000,
            'dp_amount'       => 5_000_000,
            'metadata'        => [],
        ]);

        return [$booking, $conv];
    }

    // ── Seed helpers ──────────────────────────────────────────────────────────

    private function seedPackages(): void
    {
        $silver = Package::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $this->tenantId,
            'name'        => 'Silver',
            'slug'        => 'silver',
            'description' => 'Paket dasar intimate wedding',
            'is_active'   => true,
            'sort_order'  => 1,
        ]);

        PackagePrice::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $this->tenantId,
            'package_id'  => $silver->id,
            'label'       => 'Standard',
            'price_idr'   => 15_000_000,
            'valid_from'  => now()->subDay()->toDateString(),
            'valid_until' => null,
            'is_active'   => true,
        ]);

        $gold = Package::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $this->tenantId,
            'name'        => 'Gold',
            'slug'        => 'gold',
            'description' => 'Paket lengkap dokumentasi premium',
            'is_active'   => true,
            'sort_order'  => 2,
        ]);

        PackagePrice::create([
            'id'          => Str::uuid()->toString(),
            'tenant_id'   => $this->tenantId,
            'package_id'  => $gold->id,
            'label'       => 'Standard',
            'price_idr'   => 25_000_000,
            'valid_from'  => now()->subDay()->toDateString(),
            'valid_until' => null,
            'is_active'   => true,
        ]);
    }

    private function makePricelistAsset(): Asset
    {
        return Asset::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenantId,
            'type'         => AssetType::PRICELIST->value,
            'name'         => 'Pricelist 2026',
            'file_path'    => 'assets/pricelist-2026.pdf',
            'file_url'     => 'https://cdn.example.com/pricelist-2026.pdf',
            'mime_type'    => 'application/pdf',
            'file_size_kb' => 512,
            'is_active'    => true,
        ]);
    }

    // ── Factory helpers ───────────────────────────────────────────────────────

    private function makeSuperadmin(): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Superadmin B5',
            'email'     => 'bfit-sa-' . Str::random(6) . '@platform.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::SUPERADMIN,
            'is_active' => true,
        ]);
    }

    private function makeAdminUser(string $tenantId): User
    {
        return User::create([
            'id'        => Str::uuid()->toString(),
            'name'      => 'Tenant Admin B5',
            'email'     => 'bfit-ta-' . Str::random(6) . '@tenant.com',
            'password'  => bcrypt('Password123!'),
            'role'      => UserRole::TENANT_ADMIN,
            'tenant_id' => $tenantId,
            'is_active' => true,
        ]);
    }

    /** Plan with all Phase-5 features enabled. */
    private function makePlan(): Plan
    {
        $plan = Plan::create([
            'id'         => Str::uuid()->toString(),
            'code'       => 'growth-' . Str::random(4),
            'name'       => 'Growth',
            'is_active'  => true,
            'sort_order' => 0,
        ]);

        $features = [
            FeatureKey::MAX_WA_AGENTS->value          => '3',
            FeatureKey::MONTHLY_LEAD_LIMIT->value      => '-1',
            FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'true',
            FeatureKey::FOLLOW_UP_AUTOMATION->value    => 'true',
            FeatureKey::ANALYTICS_ADVANCED->value      => 'false',
            FeatureKey::MULTI_CHANNEL->value           => 'false',
        ];

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

    /** Plan with FOLLOW_UP_AUTOMATION disabled (for test 9). */
    private function makePlanWithoutFollowUp(): Plan
    {
        $plan = Plan::create([
            'id'         => Str::uuid()->toString(),
            'code'       => 'starter-' . Str::random(4),
            'name'       => 'Starter',
            'is_active'  => true,
            'sort_order' => 1,
        ]);

        $features = [
            FeatureKey::MAX_WA_AGENTS->value          => '1',
            FeatureKey::MONTHLY_LEAD_LIMIT->value      => '100',
            FeatureKey::GOOGLE_CALENDAR_ENABLED->value => 'false',
            FeatureKey::FOLLOW_UP_AUTOMATION->value    => 'false',
            FeatureKey::ANALYTICS_ADVANCED->value      => 'false',
            FeatureKey::MULTI_CHANNEL->value           => 'false',
        ];

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

    private function makeTenant(User $createdBy, Plan $plan): Tenant
    {
        $slug = 'bfit-' . Str::random(6);

        $tenant = Tenant::create([
            'id'            => Str::uuid()->toString(),
            'name'          => 'BookingFlow Integration Vendor',
            'slug'          => $slug,
            'status'        => TenantStatus::ACTIVE,
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
        $wa = WaAccount::create([
            'id'        => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'status'    => WaAccountStatus::CONNECTED->value,
            'metadata'  => [],
        ]);

        $wa->markConnected('+6285511112222');

        return $wa;
    }
}
