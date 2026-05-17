<?php

namespace Tests\Feature\Benchmark;

/**
 * BENCHMARK 011-015: Language and input edge cases.
 * Tests that the pipeline is robust against non-standard inputs.
 */
class BenchmarkEdgeCasesTest extends BenchmarkTestCase
{
    // ── SKENARIO-011: Typo Ekstrem ─────────────────────────────────────────

    public function test_scenario_011_extreme_typo(): void
    {
        // Input with severe typos — MockLlmAdapter still returns intent
        $this->queueTurn('ask_package_list', [], 'Berikut paket kami Kak 📋');
        $result = $this->processTurn('kak mau tnya sol pket prwd dng');

        $this->assertNotEmpty($result->conversation_id, 'S011: pipeline handles typo input');
    }

    // ── SKENARIO-012: Singkatan Indonesia ─────────────────────────────────

    public function test_scenario_012_indonesian_abbreviations(): void
    {
        $this->queueTurn('ask_price', [], 'Harga mulai 5 juta Kak 💰');
        $result = $this->processTurn('brp hrg pkt wdng kak?');

        $this->assertNotEmpty($result->conversation_id, 'S012: pipeline handles abbreviations');
    }

    // ── SKENARIO-013: Emoji Saja ──────────────────────────────────────────

    public function test_scenario_013_emoji_only_message(): void
    {
        // Emoji-only → unclear_message
        $this->queueTurn('unclear_message', [], 'Ada yang bisa kami bantu Kak? 😊');
        $result = $this->processTurn('🙏🥰');

        $this->assertNotEmpty($result->conversation_id, 'S013: pipeline handles emoji-only input');
    }

    // ── SKENARIO-014: Bahasa Jawa ─────────────────────────────────────────

    public function test_scenario_014_javanese_language(): void
    {
        $this->queueTurn('ask_price', ['detected_language' => 'jv'], 'Harga paket kami mulai Rp 5 juta Kak 😊');
        $result = $this->processTurn('pinten regine pakete?');

        $this->assertNotEmpty($result->conversation_id, 'S014: pipeline handles Javanese input');
    }

    // ── SKENARIO-015: Full English ─────────────────────────────────────────

    public function test_scenario_015_full_english_inquiry(): void
    {
        $this->queueTurn('ask_price', ['detected_language' => 'en'], 'Our wedding packages start from IDR 5 million 😊');
        $result = $this->processTurn('how much is the wedding package?');

        $this->assertNotEmpty($result->conversation_id, 'S015: pipeline handles English input');
    }
}
