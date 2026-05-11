<?php

/**
 * Accuracy Regression Checker — Integration Checkpoint Phase 0
 *
 * Jalankan: OPENAI_API_KEY=sk-xxx php scripts/check_accuracy_regression.php
 *
 * Update BASELINE_* constants setelah Integration Checkpoint selesai
 * dengan nilai accuracy aktual yang dicapai.
 *
 * Exit code 0 = no regression | Exit code 1 = regression detected
 */

require_once __DIR__ . '/../poc/poc_conversation.php';

// ── Baseline — UPDATE setelah Phase 0 Integration Checkpoint ─────────────────
// Isi dengan nilai accuracy aktual yang dicapai saat gate dibuka.

define('BASELINE_INTENT_ACCURACY', 0.98);  // Run 1 Phase 0: 49/50 = 98%
define('BASELINE_ENTITY_ACCURACY', 0.95);  // Run 1 Phase 0: 28.5/30 = 95%
define('REGRESSION_THRESHOLD', 0.05);     // 5% degradation allowed

// ── Spot-check cases (10 intent + 5 entity) ──────────────────────────────────
// High-confidence cases yang jarang ambigu — representatif tapi cepat.

$intentSpotCheck = [
    ['message' => 'halo kak',                          'expected' => 'greeting'],
    ['message' => 'boleh minta pricelist kak?',         'expected' => 'ask_pricelist'],
    ['message' => 'paket silver harganya berapa?',      'expected' => 'ask_price'],
    ['message' => 'ada paket apa aja kak?',             'expected' => 'ask_package_list'],
    ['message' => 'paket gold isinya apa aja?',         'expected' => 'ask_package_detail'],
    ['message' => 'nama saya Rina',                     'expected' => 'provide_name'],
    ['message' => 'tanggalnya 20 april 2025',           'expected' => 'provide_date'],
    ['message' => 'oke saya mau booking',               'expected' => 'booking_intent'],
    ['message' => 'mau ngobrol sama adminnya kak',      'expected' => 'request_handoff'],
    ['message' => 'makasih ya kak',                     'expected' => 'thanks'],
];

$entitySpotCheck = [
    ['message' => 'nama saya Rina',              'existing' => [], 'expected_key' => 'customer_name', 'expected_val' => 'Rina'],
    ['message' => 'tanggalnya 20 april 2025',    'existing' => [], 'expected_key' => 'event_date',    'expected_val' => '2025-04-20'],
    ['message' => 'budgetnya sekitar 30 juta',   'existing' => [], 'expected_key' => 'budget_min',    'expected_val' => 27000000],
    ['message' => 'tertarik paket gold kak',     'existing' => [], 'expected_key' => 'package_interest', 'expected_val' => 'Paket Gold'],
    ['message' => 'tamu sekitar 150 orang',      'existing' => [], 'expected_key' => 'guest_count',   'expected_val' => 150],
];

// ── Helpers ──────────────────────────────────────────────────────────────────

function entityValueClose(mixed $expected, mixed $actual): bool
{
    if ($expected === null && $actual === null) {
        return true;
    }
    if (is_int($expected) && is_int($actual)) {
        return abs($expected - $actual) <= abs($expected) * 0.10;
    }
    if (is_string($expected) && is_string($actual)) {
        return strtolower(trim($expected)) === strtolower(trim($actual));
    }
    return $expected == $actual;
}

// ── Main ─────────────────────────────────────────────────────────────────────

if (!OPENAI_API_KEY) {
    fwrite(STDERR, "ERROR: OPENAI_API_KEY tidak di-set.\n");
    exit(1);
}

if (BASELINE_INTENT_ACCURACY == 0.0 && BASELINE_ENTITY_ACCURACY == 0.0) {
    echo "[INFO] Baseline belum di-set (masih 0.0).\n";
    echo "       Update BASELINE_* constants setelah Integration Checkpoint selesai.\n";
    echo "       Script ini akan skip regression check dan exit 0.\n";
    exit(0);
}

$llm = new PocLlmClient(OPENAI_API_KEY);

echo "========================================\n";
echo "ACCURACY REGRESSION CHECK\n";
echo "Date: " . date('Y-m-d H:i:s') . "\n";
echo "Baseline Intent : " . number_format(BASELINE_INTENT_ACCURACY * 100, 1) . "%\n";
echo "Baseline Entity : " . number_format(BASELINE_ENTITY_ACCURACY * 100, 1) . "%\n";
echo "Threshold       : " . number_format(REGRESSION_THRESHOLD * 100, 1) . "%\n";
echo "========================================\n\n";

// Intent spot-check
$intentPassed = 0;
echo "--- Intent Spot-check (" . count($intentSpotCheck) . " cases) ---\n";
foreach ($intentSpotCheck as $c) {
    try {
        $result = $llm->classifyIntent($c['message'], '');
        $ok     = ($result['intent'] === $c['expected']);
        if ($ok) {
            $intentPassed++;
        }
        echo ($ok ? '[✅]' : '[❌]') . " \"{$c['message']}\" → {$result['intent']} (expected: {$c['expected']})\n";
    } catch (RuntimeException $e) {
        echo "[ERROR] \"{$c['message']}\" → " . $e->getMessage() . "\n";
    }
    usleep(200000);
}

$intentRate = $intentPassed / count($intentSpotCheck);
echo "Intent spot-check: $intentPassed / " . count($intentSpotCheck) . " = " . number_format($intentRate * 100, 1) . "%\n\n";

// Entity spot-check
$entityPassed = 0;
echo "--- Entity Spot-check (" . count($entitySpotCheck) . " cases) ---\n";
foreach ($entitySpotCheck as $c) {
    try {
        $result   = $llm->extractEntities($c['message'], $c['existing']);
        $entities = $result['entities'];
        $actual   = $entities[$c['expected_key']] ?? null;
        $ok       = entityValueClose($c['expected_val'], $actual);
        if ($ok) {
            $entityPassed++;
        }
        echo ($ok ? '[✅]' : '[❌]') . " \"{$c['message']}\" → {$c['expected_key']}: " . json_encode($actual) . " (expected: " . json_encode($c['expected_val']) . ")\n";
    } catch (RuntimeException $e) {
        echo "[ERROR] \"{$c['message']}\" → " . $e->getMessage() . "\n";
    }
    usleep(200000);
}

$entityRate = $entityPassed / count($entitySpotCheck);
echo "Entity spot-check: $entityPassed / " . count($entitySpotCheck) . " = " . number_format($entityRate * 100, 1) . "%\n\n";

// Regression check
$intentRegression = ($intentRate < BASELINE_INTENT_ACCURACY - REGRESSION_THRESHOLD);
$entityRegression = ($entityRate  < BASELINE_ENTITY_ACCURACY  - REGRESSION_THRESHOLD);

echo "========================================\n";
echo "REGRESSION CHECK\n";
echo "========================================\n";
echo "Intent : " . number_format($intentRate * 100, 1) . "% vs baseline " . number_format(BASELINE_INTENT_ACCURACY * 100, 1) . "% → " . ($intentRegression ? '❌ REGRESSION DETECTED' : '✅ OK') . "\n";
echo "Entity : " . number_format($entityRate  * 100, 1) . "% vs baseline " . number_format(BASELINE_ENTITY_ACCURACY  * 100, 1) . "% → " . ($entityRegression ? '❌ REGRESSION DETECTED' : '✅ OK') . "\n";
echo "========================================\n";

if ($intentRegression || $entityRegression) {
    echo "[ALERT] Regression terdeteksi! Cek apakah ada perubahan prompt yang menyebabkan penurunan.\n";
    echo "        Rollback ke prompt versi sebelumnya jika penurunan > 5%.\n";
    exit(1);
}

echo "[OK] Accuracy dalam batas normal. Tidak ada regression.\n";
exit(0);
