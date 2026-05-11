<?php

/**
 * Sub-task 0.3 — Response Composer Quality Test
 *
 * Jalankan: OPENAI_API_KEY=sk-xxx php poc/test_composer_quality.php
 *
 * 10 skenario manual + 5 anti-hallucination test cases.
 * Auto-checks dijalankan di code; kolom [manual] untuk review human.
 */

require_once __DIR__ . '/poc_conversation.php';

// ── Helpers ──────────────────────────────────────────────────────────────────

function checkAutoRules(string $reply, array $context): array
{
    $knowledge       = $context['knowledge'] ?? [];
    $knownPackages   = array_column($knowledge['packages'] ?? [], 'name');
    $knownPrices     = $knowledge['prices']  ?? [];
    $pricelist       = $knowledge['pricelist_text'] ?? '';

    $checks = [];

    // Contains "Kak"
    $checks['contains_kak']   = str_contains($reply, 'Kak');

    // Length proxy for "max 3-4 kalimat"
    $checks['not_too_long']   = mb_strlen($reply) <= 500;

    // No fake package names OFFERED positively (corrective mentions are OK)
    $mentionsFakePackagePositively = false;
    $fakePatterns = ['Emerald', 'Diamond', 'Bronze', 'Titanium', 'Pearl'];
    foreach ($fakePatterns as $fake) {
        if (stripos($reply, $fake) !== false) {
            // If the reply also has corrective language, this is NOT hallucination
            $correctivePhrases = ['tidak ada', 'tidak punya', 'tidak memiliki', 'belum ada', 'belum punya', 'maaf', 'mohon maaf', "don't have", 'not available'];
            $isCorrectiveContext = false;
            foreach ($correctivePhrases as $cp) {
                if (stripos($reply, $cp) !== false) {
                    $isCorrectiveContext = true;
                    break;
                }
            }
            if (!$isCorrectiveContext) {
                $mentionsFakePackagePositively = true;
            }
        }
    }
    $checks['no_fake_package']   = !$mentionsFakePackagePositively;

    // No invented price strings that don't match known prices
    // Simple heuristic: if reply mentions "Rp" followed by digits, check it's a known value
    $inventsPrice = false;
    if (preg_match_all('/Rp\s*([\d.,]+)\s*(juta|jt)?/i', $reply, $matches)) {
        $knownPriceValues = array_values($knownPrices); // integers
        foreach ($matches[1] as $i => $num) {
            $num = (float) str_replace([',', '.'], '', $num);
            $unit = strtolower($matches[2][$i] ?? '');
            if ($unit === 'juta' || $unit === 'jt') {
                $num *= 1000000;
            }
            // Check if this price is within ±5% of any known price, or if pricelist_text contains this info
            $knownMatch = false;
            foreach ($knownPriceValues as $kp) {
                if (abs($num - $kp) <= $kp * 0.05) {
                    $knownMatch = true;
                    break;
                }
            }
            if (!$knownMatch && $num > 0) {
                $inventsPrice = true;
                break;
            }
        }
    }
    $checks['no_invented_price'] = !$inventsPrice;

    return $checks;
}

function printScenario(int $num, string $name, array $context, string $reply, array $autoChecks, bool $isHallucinationTest = false): void
{
    echo "\n============================================\n";
    echo "SCENARIO $num: $name\n";
    echo "============================================\n";
    echo "Context:\n";
    echo "  intent         : " . ($context['intent']         ?? '-') . "\n";
    echo "  decision       : " . ($context['decision']       ?? '-') . "\n";
    echo "  reply_strategy : " . ($context['reply_strategy'] ?? '-') . "\n";
    echo "  entities       : " . json_encode($context['entities'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
    echo "--------------------------------------------\n";
    echo "REPLY:\n$reply\n";
    echo "--------------------------------------------\n";
    echo "AUTO-CHECKS:\n";
    foreach ($autoChecks as $checkName => $passed) {
        $icon = $passed ? '✅' : '❌';
        echo "  [$icon] $checkName\n";
    }
    if ($isHallucinationTest) {
        $allPass = array_reduce($autoChecks, fn($c, $v) => $c && $v, true);
        echo "\n  " . ($allPass ? '[NO HALLUCINATION]' : '[HALLUCINATION DETECTED]') . "\n";
    }
    echo "MANUAL REVIEW:\n";
    echo "  [ ] natural untuk bahasa Indonesia\n";
    echo "  [ ] empati tepat untuk konteks\n";
    echo "  [ ] tidak terlalu formal / kaku\n";
    echo "============================================\n";
}

// ── Main ─────────────────────────────────────────────────────────────────────

if (!OPENAI_API_KEY) {
    fwrite(STDERR, "ERROR: OPENAI_API_KEY tidak di-set.\n");
    exit(1);
}

$llm = new PocLlmClient(OPENAI_API_KEY);

$baseKnowledge = [
    'packages' => [
        ['name' => 'Paket Silver',   'slug' => 'silver',   'description' => 'Paket dasar foto & video'],
        ['name' => 'Paket Gold',     'slug' => 'gold',     'description' => 'Paket lengkap foto & video + album'],
        ['name' => 'Paket Platinum', 'slug' => 'platinum', 'description' => 'Paket premium all-in'],
    ],
    'prices' => [
        'silver'   => 25000000,
        'gold'     => 35000000,
        'platinum' => 45000000,
    ],
    'pricelist_text' => 'Kami punya 3 paket: Paket Silver (Rp 25 juta), Paket Gold (Rp 35 juta), Paket Platinum (Rp 45 juta).',
    'booking_link'   => 'https://booking.contoh.com/form',
];

$scenarios = [
    // 1 — Greeting new lead
    [
        'name' => 'Greeting new lead',
        'context' => [
            'intent'         => 'greeting',
            'decision'       => 'reply_greeting',
            'reply_strategy' => 'greeting_new_lead',
            'entities'       => [],
            'stage'          => 'new_lead',
            'knowledge'      => $baseKnowledge,
        ],
    ],
    // 2 — Ask pricelist, nama belum ada
    [
        'name' => 'Ask pricelist — nama belum ada',
        'context' => [
            'intent'         => 'ask_pricelist',
            'decision'       => 'ask_name',
            'reply_strategy' => 'ask_missing_info',
            'entities'       => [],
            'stage'          => 'exploration',
            'knowledge'      => $baseKnowledge,
        ],
    ],
    // 3 — Ask pricelist, nama sudah ada
    [
        'name' => 'Ask pricelist — nama sudah ada (Kak Rina)',
        'context' => [
            'intent'         => 'ask_pricelist',
            'decision'       => 'send_pricelist',
            'reply_strategy' => 'send_pricelist_preface',
            'entities'       => ['customer_name' => 'Rina'],
            'stage'          => 'exploration',
            'knowledge'      => $baseKnowledge,
        ],
    ],
    // 4 — Tanya harga spesifik (ada di knowledge)
    [
        'name' => 'Tanya harga Paket Silver (ada di knowledge)',
        'context' => [
            'intent'         => 'ask_price',
            'decision'       => 'answer_price',
            'reply_strategy' => 'short_contextual_answer',
            'entities'       => ['customer_name' => 'Budi', 'package_interest' => 'Paket Silver'],
            'stage'          => 'qualification',
            'knowledge'      => $baseKnowledge,
        ],
    ],
    // 5 — Paket yang TIDAK ADA (hallucination test)
    [
        'name' => 'Tanya paket TIDAK ADA (Paket Emerald) — hallucination test',
        'context' => [
            'intent'         => 'ask_package_detail',
            'decision'       => 'ask_which_package',
            'reply_strategy' => 'ask_missing_info',
            'entities'       => ['package_interest' => 'Paket Emerald'],
            'stage'          => 'qualification',
            'knowledge'      => [
                'packages'       => $baseKnowledge['packages'],
                'prices'         => $baseKnowledge['prices'],
                'pricelist_text' => $baseKnowledge['pricelist_text'],
                'booking_link'   => null,
            ],
        ],
        'hallucination_test' => true,
    ],
    // 6 — Price objection
    [
        'name' => 'Objection harga — customer merasa mahal',
        'context' => [
            'intent'         => 'complaint',
            'decision'       => 'handoff_urgent',
            'reply_strategy' => 'handoff_message',
            'entities'       => ['customer_name' => 'Dewi', 'package_interest' => 'Paket Silver', 'objection' => 'price'],
            'stage'          => 'consideration',
            'knowledge'      => $baseKnowledge,
        ],
    ],
    // 7 — Booking intent semua lengkap
    [
        'name' => 'Booking intent — semua field lengkap',
        'context' => [
            'intent'         => 'booking_intent',
            'decision'       => 'send_booking_link',
            'reply_strategy' => 'booking_link_preface',
            'entities'       => [
                'customer_name'    => 'Rina',
                'event_date'       => '2025-04-20',
                'package_interest' => 'Paket Silver',
            ],
            'stage'          => 'booking',
            'knowledge'      => $baseKnowledge,
        ],
    ],
    // 8 — Complaint
    [
        'name' => 'Complaint — customer kecewa dengan pelayanan',
        'context' => [
            'intent'         => 'complaint',
            'decision'       => 'handoff_urgent',
            'reply_strategy' => 'handoff_message',
            'entities'       => ['customer_name' => 'Ahmad', 'objection' => 'trust'],
            'stage'          => 'handoff',
            'knowledge'      => $baseKnowledge,
        ],
    ],
    // 9 — After hours (knowledge tidak ada business_hours)
    [
        'name' => 'Tanya ketersediaan — data kalender tidak ada',
        'context' => [
            'intent'         => 'ask_availability',
            'decision'       => 'check_availability',
            'reply_strategy' => 'short_contextual_answer',
            'entities'       => ['customer_name' => 'Sari', 'event_date' => '2025-12-25'],
            'stage'          => 'qualification',
            'knowledge'      => [
                'packages'       => $baseKnowledge['packages'],
                'prices'         => $baseKnowledge['prices'],
                'pricelist_text' => $baseKnowledge['pricelist_text'],
                'booking_link'   => null,
                'calendar'       => null,
            ],
        ],
    ],
    // 10 — Unclear message
    [
        'name' => 'Unclear message — customer mengirim pesan tidak jelas',
        'context' => [
            'intent'         => 'unclear_message',
            'decision'       => 'ask_clarification',
            'reply_strategy' => 'ask_missing_info',
            'entities'       => [],
            'stage'          => 'new_lead',
            'knowledge'      => $baseKnowledge,
        ],
    ],
];

echo "========================================\n";
echo "COMPOSER QUALITY TEST\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Total scenarios: " . count($scenarios) . "\n";
echo "========================================\n";

$autoPassTotal = 0;
$autoCheckTotal = 0;
$hallucinationDetected = false;

foreach ($scenarios as $i => $scenario) {
    $num     = $i + 1;
    $name    = $scenario['name'];
    $context = $scenario['context'];
    $isHallucinationTest = $scenario['hallucination_test'] ?? false;

    try {
        $reply      = $llm->composeReply($context);
        $autoChecks = checkAutoRules($reply, $context);

        if ($isHallucinationTest) {
            $allAuto = array_reduce($autoChecks, fn($c, $v) => $c && $v, true);
            if (!$allAuto) {
                $hallucinationDetected = true;
            }
        }

        $passCount = count(array_filter($autoChecks));
        $autoPassTotal += $passCount;
        $autoCheckTotal += count($autoChecks);

        printScenario($num, $name, $context, $reply, $autoChecks, $isHallucinationTest);

    } catch (RuntimeException $e) {
        echo "\n[ERROR] Scenario $num: $name → " . $e->getMessage() . "\n";
    }

    usleep(300000);
}

echo "\n========================================\n";
echo "OVERALL SUMMARY\n";
echo "========================================\n";
echo "Auto-checks passed : $autoPassTotal / $autoCheckTotal\n";
echo "Hallucination found: " . ($hallucinationDetected ? 'YES ❌ — FIX PROMPT SEBELUM LANJUT' : 'NO ✅') . "\n";
echo "\nManual review required:\n";
echo "  - Review setiap reply apakah natural dalam konteks Indonesia\n";
echo "  - Scenario 5 (Paket Emerald): apakah reply menyebut paket itu? Jika ya = HALLUCINATION\n";
echo "  - Scenario 9 (availability): apakah claim available/not available? Jika ya = HALLUCINATION\n";
echo "========================================\n";

exit($hallucinationDetected ? 1 : 0);
