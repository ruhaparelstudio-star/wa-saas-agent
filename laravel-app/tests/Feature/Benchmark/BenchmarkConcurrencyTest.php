<?php

namespace Tests\Feature\Benchmark;

use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\FollowUp\Services\FollowUpService;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\FollowUpReason;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * BENCHMARK 016-020: Concurrency and race condition scenarios.
 */
class BenchmarkConcurrencyTest extends BenchmarkTestCase
{
    // ── SKENARIO-016: Duplicate Message (Idempotency) ─────────────────────

    public function test_scenario_016_duplicate_message_idempotency(): void
    {
        $msgId = 'idempotent-msg-' . Str::random(8);

        // First message — processed normally
        $this->queueTurn('greeting', [], 'Halo Kak, ada yang bisa kami bantu? 😊');
        $result1 = $this->pipeline->process(
            $this->makeInboundWithId('halo kak', $msgId),
            $this->tenantId,
        );

        $this->assertNotEmpty($result1->conversation_id, 'S016: first message creates conversation');

        // Second identical message — duplicate, skipped by idempotency
        $result2 = $this->pipeline->process(
            $this->makeInboundWithId('halo kak', $msgId),
            $this->tenantId,
        );

        // Duplicate → reply_sent=false, conversation_id empty (short-circuit return)
        $this->assertFalse($result2->reply_sent, 'S016: duplicate message should not be processed');
    }

    // ── SKENARIO-017: Race Condition Booking (Pessimistic Lock) ───────────

    public function test_scenario_017_concurrent_booking_same_date(): void
    {
        // Pre-create a CONFIRMED booking for the target date
        Booking::create([
            'id'           => Str::uuid()->toString(),
            'tenant_id'    => $this->tenantId,
            'booking_code' => 'BKG-202609-0001',
            'event_date'   => '2026-09-20',
            'event_type'   => 'resepsi',
            'status'       => BookingStatus::CONFIRMED->value,
        ]);

        // Second customer tries to book same date via pipeline
        $this->queueTurn(
            'request_booking',
            ['event_date' => '2026-09-20', 'event_type' => 'resepsi'],
            'Maaf Kak, tanggal tersebut sudah terpesan 🙏',
        );
        $this->processTurn('mau booking 20 september 2026');

        // Only the original CONFIRMED booking should exist — no second draft
        $count = Booking::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->count();

        $this->assertSame(1, $count, 'S017: pessimistic lock prevents double booking');
    }

    // ── SKENARIO-018: Multi Pesan Berurutan ───────────────────────────────

    public function test_scenario_018_sequential_messages_same_customer(): void
    {
        $phone = '+628120000018';

        // Turn 1
        $this->queueTurn('greeting', [], 'Halo Kak 😊');
        $r1 = $this->pipeline->process($this->makeInbound('halo kak', $phone), $this->tenantId);

        // Turn 2 — same conversation (same phone)
        $this->queueTurn('ask_package_list', [], 'Berikut paket kami 📋');
        $r2 = $this->pipeline->process($this->makeInbound('mau tanya paket', $phone), $this->tenantId);

        // Turn 3 — same conversation
        $this->queueTurn('provide_budget', ['budget_max' => 8000000], 'Budget 8 juta, baik Kak 👍');
        $r3 = $this->pipeline->process($this->makeInbound('budget 8 juta kak', $phone), $this->tenantId);

        // All turns should belong to same conversation
        $this->assertSame($r1->conversation_id, $r2->conversation_id, 'S018 T1=T2 same conversation');
        $this->assertSame($r2->conversation_id, $r3->conversation_id, 'S018 T2=T3 same conversation');

        // Entity cache should accumulate
        $conv = Conversation::withoutGlobalScopes()->find($r3->conversation_id);
        $this->assertArrayHasKey('budget_max', $conv->entity_cache ?? [], 'S018: entity cache accumulated');
    }

    // ── SKENARIO-019: Idempotency After Cache Clear ────────────────────────

    public function test_scenario_019_idempotency_resets_after_cache_clear(): void
    {
        $msgId = 'cacheable-msg-' . Str::random(8);

        // Process first time
        $this->queueTurn('greeting', [], 'Halo Kak 😊');
        $r1 = $this->pipeline->process(
            $this->makeInboundWithId('halo', $msgId),
            $this->tenantId,
        );

        $this->assertNotEmpty($r1->conversation_id, 'S019: first message processed');

        // Clear cache to simulate TTL expiry
        Cache::flush();

        // Process same message again — should be treated as new (cache cleared)
        $this->queueTurn('greeting', [], 'Halo lagi Kak 😊');
        $r2 = $this->pipeline->process(
            $this->makeInboundWithId('halo', $msgId),
            $this->tenantId,
        );

        $this->assertNotEmpty($r2->conversation_id, 'S019: message processed again after cache clear');
    }

    // ── SKENARIO-020: Out of Scope 3x → Handoff ───────────────────────────

    public function test_scenario_020_out_of_scope_three_times_triggers_handoff(): void
    {
        $phone = '+628120000020';

        // Pre-create conversation with out_of_scope_count already at 2
        $conv = Conversation::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantId,
            'wa_account_id'  => $this->waAccount->id,
            'customer_phone' => $phone,
            'stage'          => ConversationStage::NEW_LEAD->value,
            'agent_mode'     => 'active',
            'entity_cache'   => ['out_of_scope_count' => 2],
        ]);

        // Third out_of_scope message → handoff triggered
        $this->queueTurn('out_of_scope', [], 'Tim kami akan segera menghubungi Anda Kak 🙏');
        $result = $this->pipeline->process(
            $this->makeInbound('gimana cara bikin SIM?', $phone),
            $this->tenantId,
        );

        $conv->refresh();
        $this->assertSame(
            ConversationStage::HANDOFF,
            $conv->stage,
            'S020: out_of_scope 3x should trigger handoff to HANDOFF stage',
        );
    }
}
