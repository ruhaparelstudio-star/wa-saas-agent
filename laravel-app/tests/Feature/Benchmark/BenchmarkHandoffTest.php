<?php

namespace Tests\Feature\Benchmark;

use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\FollowUp\Models\FollowUpLog;
use App\Modules\FollowUp\Services\FollowUpService;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\FollowUpReason;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * BENCHMARK 026-030: Handoff and follow-up automation scenarios.
 */
class BenchmarkHandoffTest extends BenchmarkTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*/dispatch' => Http::response(['success' => true, 'provider_message_id' => 'bm-fake'], 200),
            '*/status/*' => Http::response(['status' => 'connected'], 200),
        ]);
    }

    // ── SKENARIO-026: Customer Explicitly Requests Human Agent ────────────

    public function test_scenario_026_customer_requests_handoff(): void
    {
        $this->queueTurn('handoff_request', [], 'Baik Kak, tim kami akan segera membalas 🙏');
        $result = $this->processTurn('bisa minta tolong hubungin CS manusianya kak?');

        $conv = Conversation::withoutGlobalScopes()->find($result->conversation_id);
        $this->assertSame(
            ConversationStage::HANDOFF,
            $conv->stage,
            'S026: handoff_request intent should set stage to HANDOFF',
        );
    }

    // ── SKENARIO-027: Conversation Kembali Aktif Setelah Handoff ──────────

    public function test_scenario_027_pipeline_handles_handoff_stage_gracefully(): void
    {
        $phone = '+628120000027';

        // Pre-create conversation already in HANDOFF stage
        Conversation::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantId,
            'wa_account_id'  => $this->waAccount->id,
            'customer_phone' => $phone,
            'stage'          => ConversationStage::HANDOFF->value,
            'agent_mode'     => 'active',
        ]);

        // Customer sends another message while in HANDOFF — pipeline should not crash
        $this->queueTurn('greeting', [], 'Tim kami sedang meninjau percakapan Anda Kak 🙏');
        $result = $this->pipeline->process(
            $this->makeInbound('halo masih ada?', $phone),
            $this->tenantId,
        );

        // Should return valid result (pipeline continues gracefully)
        $this->assertNotEmpty($result->conversation_id, 'S027: pipeline handles HANDOFF stage without crash');
    }

    // ── SKENARIO-028: Stale Lead Follow-Up Sent ───────────────────────────

    public function test_scenario_028_stale_lead_followup_found_and_dispatched(): void
    {
        $phone = '+628120000028';

        // Create stale exploration conversation
        $conv = Conversation::create([
            'id'               => Str::uuid()->toString(),
            'tenant_id'        => $this->tenantId,
            'wa_account_id'    => $this->waAccount->id,
            'customer_phone'   => $phone,
            'stage'            => ConversationStage::EXPLORATION->value,
            'agent_mode'       => 'active',
            'lead_temperature' => 'warm',
            'last_message_at'  => Carbon::now()->subHours(60),
        ]);

        $followUpService = app(FollowUpService::class);
        $candidates      = $followUpService->findCandidates($this->tenantId);

        $staleReasons = array_map(fn ($c) => $c->reason, $candidates);
        $this->assertContains(
            FollowUpReason::STALE_LEAD->value,
            $staleReasons,
            'S028: stale lead should be detected as follow-up candidate',
        );
    }

    // ── SKENARIO-029: Booking Pending DP Reminder ─────────────────────────

    public function test_scenario_029_pending_dp_booking_followup(): void
    {
        $phone = '+628120000029';

        // Create a booking in AWAITING_DP status older than 48 hours
        $booking = Booking::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantId,
            'booking_code'   => 'BKG-202606-0029',
            'event_date'     => '2026-08-15',
            'event_type'     => 'resepsi',
            'status'         => BookingStatus::AWAITING_DP->value,
            'customer_phone' => $phone,
        ]);
        // Back-date updated_at so it falls outside the 48-hour cutoff
        \Illuminate\Support\Facades\DB::table('bookings')
            ->where('id', $booking->id)
            ->update(['updated_at' => Carbon::now()->subHours(50)]);

        $followUpService = app(FollowUpService::class);
        $candidates      = $followUpService->findCandidates($this->tenantId);

        $reasons = array_map(fn ($c) => $c->reason, $candidates);
        $this->assertContains(
            FollowUpReason::BOOKING_PENDING_DP->value,
            $reasons,
            'S029: booking pending DP should be found as follow-up candidate',
        );
    }

    // ── SKENARIO-030: H-7 Event Reminder ─────────────────────────────────

    public function test_scenario_030_h7_event_reminder_detected(): void
    {
        $phone   = '+628120000030';
        $h7Date  = Carbon::now()->addDays(7)->toDateString();

        // Create a CONFIRMED booking with event_date = 7 days from now
        Booking::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantId,
            'booking_code'   => 'BKG-202606-0030',
            'event_date'     => $h7Date,
            'event_type'     => 'resepsi',
            'status'         => BookingStatus::CONFIRMED->value,
            'customer_phone' => $phone,
        ]);

        $followUpService = app(FollowUpService::class);
        $candidates      = $followUpService->findCandidates($this->tenantId);

        $reasons = array_map(fn ($c) => $c->reason, $candidates);
        $this->assertContains(
            FollowUpReason::EVENT_REMINDER_H7->value,
            $reasons,
            'S030: booking 7 days away should trigger H-7 event reminder',
        );
    }
}
