<?php

namespace Tests\Feature\Benchmark;

use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\FollowUp\Services\FollowUpService;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * BENCHMARK 001-005: Happy Path scenarios.
 * These represent the most common successful customer journeys.
 */
class BenchmarkHappyPathTest extends BenchmarkTestCase
{
    // ── SKENARIO-001: Tanya Paket + Booking ────────────────────────────────

    public function test_scenario_001_ask_package_list_to_booking(): void
    {
        // Turn 1: tanya paket → exploration stage
        $this->queueTurn('ask_package_list', [], 'Berikut paket-paket kami Kak 📋');
        $result1 = $this->processTurn('kak mau tanya paket foto prewed dong');

        $this->assertNotEmpty($result1->conversation_id, 'S001: conversation must be created');

        $conv = Conversation::withoutGlobalScopes()->find($result1->conversation_id);
        $this->assertSame(
            ConversationStage::EXPLORATION,
            $conv->stage,
            'S001 T1: stage should transition to EXPLORATION after ask_package_list',
        );

        // Turn 2: provide budget
        $this->queueTurn('provide_budget', ['budget_max' => 5000000], 'Budget 5 juta, oke Kak 👍');
        $result2 = $this->processTurn('budget sekitar 5 jutaan kak');

        $this->assertSame($result1->conversation_id, $result2->conversation_id, 'S001: same conversation');

        // Turn 3: booking intent + event_date → booking created
        $this->queueTurn('request_booking', ['event_date' => '2026-09-20', 'event_type' => 'resepsi'], 'Booking diterima Kak 🎉');
        $result3 = $this->processTurn('oke kak mau booking deh tanggal 20 september');

        $conv->refresh();
        $this->assertSame(
            ConversationStage::WAITING_BOOKING,
            $conv->stage,
            'S001 T3: stage should be WAITING_BOOKING after booking intent with event_date',
        );

        $booking = Booking::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->first();
        $this->assertNotNull($booking, 'S001: booking should be created');
        $this->assertSame(BookingStatus::DRAFT, $booking->status);
    }

    // ── SKENARIO-002: Tanya Harga + Price Objection ────────────────────────

    public function test_scenario_002_ask_price_then_objection(): void
    {
        // Turn 1: tanya harga
        $this->queueTurn('ask_price', [], 'Harga mulai dari 5 juta Kak 😊');
        $result1 = $this->processTurn('berapa harga paket wedding kak?');

        $this->assertNotEmpty($result1->conversation_id, 'S002: conversation created');

        // Turn 2: price objection → handle_objection in decision
        $this->queueTurn('objection_price', ['objection' => 'price'], 'Kita punya opsi cicilan lho Kak 💰');
        $result2 = $this->processTurn('wah mahal juga ya');

        // Pipeline should complete without error
        $this->assertSame($result1->conversation_id, $result2->conversation_id, 'S002: same conversation');
    }

    // ── SKENARIO-003: Langsung Booking ────────────────────────────────────

    public function test_scenario_003_direct_booking_intent(): void
    {
        // Turn 1: customer already knows → direct booking with date
        $this->queueTurn(
            'request_booking',
            ['event_date' => '2026-06-15', 'event_type' => 'resepsi', 'customer_name' => 'Siti'],
            'Booking untuk tanggal 15 Juni diterima Kak 🎊',
        );
        $result = $this->processTurn('kak saya mau booking paket gold tanggal 15 juni 2026 atas nama Siti');

        $this->assertNotEmpty($result->conversation_id, 'S003: conversation created');

        $conv = Conversation::withoutGlobalScopes()->find($result->conversation_id);
        $this->assertSame(
            ConversationStage::WAITING_BOOKING,
            $conv->stage,
            'S003: direct booking → WAITING_BOOKING',
        );

        $booking = Booking::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->first();
        $this->assertNotNull($booking, 'S003: booking created');
        $this->assertSame('2026-06-15', $booking->event_date->toDateString());
    }

    // ── SKENARIO-004: Invoice Inquiry ─────────────────────────────────────

    public function test_scenario_004_invoice_inquiry(): void
    {
        // Pre-create a confirmed booking
        Booking::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenantId,
            'booking_code' => 'BKG-202606-0099',
            'event_date'   => '2026-06-15',
            'event_type'   => 'resepsi',
            'status'       => BookingStatus::CONFIRMED->value,
        ]);

        // Customer asks about invoice/DP
        $this->queueTurn('invoice_inquiry', ['payment_topic' => 'dp', 'invoice_reference' => 'latest'], 'Invoice DP sudah dikirim Kak 📄');
        $result = $this->processTurn('kapan harus transfer dp nya kak?');

        $this->assertNotEmpty($result->conversation_id, 'S004: conversation created');
        // Pipeline should handle invoice_inquiry intent without error
    }

    // ── SKENARIO-005: Follow-Up Stale Lead Detection ──────────────────────

    public function test_scenario_005_followup_stale_lead_detection(): void
    {
        // Create a stale conversation (last_message_at > 72 hours ago)
        $staleConv = Conversation::create([
            'id'               => Str::uuid()->toString(),
            'tenant_id'        => $this->tenantId,
            'wa_account_id'    => $this->waAccount->id,
            'customer_phone'   => '+628129990001',
            'stage'            => ConversationStage::EXPLORATION->value,
            'agent_mode'       => 'active',
            'lead_temperature' => 'warm',
            'last_message_at'  => Carbon::now()->subHours(80),
        ]);

        $followUpService = app(FollowUpService::class);
        $candidates      = $followUpService->findCandidates($this->tenantId);

        $this->assertNotEmpty($candidates, 'S005: stale lead should be found as follow-up candidate');

        $convIds = array_map(fn ($c) => $c->conversation_id, $candidates);
        $this->assertContains(
            $staleConv->id,
            $convIds,
            'S005: stale conversation should be in candidates list',
        );
    }
}
