<?php

namespace Tests\Feature\Benchmark;

use App\Modules\Conversation\Models\Conversation;
use App\Modules\Shared\Enums\ConversationStage;
use Illuminate\Support\Str;

/**
 * BENCHMARK 006-010: Objection handling scenarios.
 * Tests that the pipeline handles various customer objections gracefully.
 */
class BenchmarkObjectionTest extends BenchmarkTestCase
{
    // ── SKENARIO-006: Objection — Harga ───────────────────────────────────

    public function test_scenario_006_price_objection_handling(): void
    {
        // Turn 1: exploration
        $this->queueTurn('ask_price', [], 'Harga mulai 5 juta Kak 😊');
        $result1 = $this->processTurn('berapa harga paketnya?');

        // Turn 2: price objection — handle_objection desired action
        $this->queueTurn('objection_price', ['objection' => 'price'], 'Ada promo khusus bulan ini lho Kak! 🎁');
        $result2 = $this->processTurn('mahal banget kak, ada diskon ga?');

        $this->assertSame($result1->conversation_id, $result2->conversation_id, 'S006: same conversation');
        // Pipeline completed = no exceptions thrown
    }

    // ── SKENARIO-007: Objection — Trust/Kepercayaan ───────────────────────

    public function test_scenario_007_trust_objection(): void
    {
        $this->queueTurn('ask_package_list', [], 'Berikut paket kami Kak 📋');
        $result1 = $this->processTurn('mau tanya paket kak');

        $this->queueTurn('objection_trust', ['objection' => 'trust'], 'Kami sudah 5 tahun di industri wedding Kak 🏆');
        $result2 = $this->processTurn('kak kami belum kenal vendor ini, ada portfolio ga?');

        $this->assertSame($result1->conversation_id, $result2->conversation_id, 'S007: same conversation');
    }

    // ── SKENARIO-008: Objection — Timing ─────────────────────────────────

    public function test_scenario_008_timing_objection(): void
    {
        // Pre-create conversation in QUALIFICATION stage
        $conv = Conversation::create([
            'id'             => Str::uuid()->toString(),
            'tenant_id'      => $this->tenantId,
            'wa_account_id'  => $this->waAccount->id,
            'customer_phone' => '+628120000022',
            'stage'          => ConversationStage::QUALIFICATION->value,
            'agent_mode'     => 'active',
        ]);

        $this->queueTurn('objection_timing', ['objection' => 'timing'], 'Tidak apa Kak, kami bisa hold slot sementara 📅');
        $result = $this->pipeline->process(
            $this->makeInbound('masih lama kak acaranya, belum yakin mau booking sekarang', '+628120000022'),
            $this->tenantId,
        );

        $this->assertNotEmpty($result->conversation_id, 'S008: pipeline produced result');
    }

    // ── SKENARIO-009: Objection — Competitor ─────────────────────────────

    public function test_scenario_009_competitor_objection(): void
    {
        $this->queueTurn('ask_package_list', [], 'Paket kami Kak 📋');
        $result1 = $this->processTurn('mau tanya paket foto');

        $this->queueTurn(
            'objection_trust',
            ['objection' => 'trust'],
            'Kami siap bersaing Kak, kualitas kami terjamin ✨',
        );
        $result2 = $this->processTurn('vendor lain kasih harga lebih murah kak');

        $this->assertSame($result1->conversation_id, $result2->conversation_id, 'S009: same conversation');
    }

    // ── SKENARIO-010: Objection — Perlu Diskusi Dulu ─────────────────────

    public function test_scenario_010_need_time_to_decide(): void
    {
        $this->queueTurn('ask_package_list', [], 'Paket kami Kak 📋');
        $result1 = $this->processTurn('mau tanya paket');

        // Customer says "need to think" — unclear_message or consider
        $this->queueTurn('unclear_message', [], 'Siap Kak, kami tunggu ya 😊 Hubungi kami kapan saja!');
        $result2 = $this->processTurn('nanti ya kak mau diskusi dulu sama calon pasangan');

        $this->assertSame($result1->conversation_id, $result2->conversation_id, 'S010: same conversation');
        // Pipeline handles unclear_message gracefully
    }
}
