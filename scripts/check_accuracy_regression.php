<?php

/**
 * Accuracy Regression Checker — Phase 0 + Phase 3
 *
 * Phase 0 (real LLM, needs OPENAI_API_KEY):
 *   OPENAI_API_KEY=sk-xxx php scripts/check_accuracy_regression.php
 *
 * Phase 3 (MockLlmAdapter via artisan test — no API key needed):
 *   php scripts/check_accuracy_regression.php
 *
 * Exit code 0 = no regression | Exit code 1 = regression detected
 */

define('BASELINE_INTENT_ACCURACY', 0.98);
define('BASELINE_ENTITY_ACCURACY', 0.95);
define('REGRESSION_THRESHOLD', 0.05);

$apiKey = getenv('OPENAI_API_KEY') ?: '';

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

// ── Phase 0 Spot-check (only if OPENAI_API_KEY set) ──────────────────────────

if (! $apiKey) {
    echo "[INFO] OPENAI_API_KEY not set — skipping Phase 0 POC spot-check.\n";
    echo "       Set OPENAI_API_KEY to run real-LLM regression check.\n\n";
} else {
    require_once __DIR__ . '/../poc/poc_conversation.php';

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

    $llm = new PocLlmClient($apiKey);

    echo "========================================\n";
    echo "PHASE 0 ACCURACY REGRESSION CHECK\n";
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
        echo "[ALERT] Regression terdeteksi! Rollback ke prompt versi sebelumnya.\n";
        exit(1);
    }

    echo "[OK] Phase 0 accuracy dalam batas normal.\n";
}

// ── Phase 3 Pipeline Accuracy Check ──────────────────────────────────────────
// Run via: php artisan test --filter=PipelineAccuracyTest (inside Docker/CI)
// This section validates the Phase 3 full-pipeline accuracy (MockLlmAdapter).

echo "\n========================================\n";
echo "PHASE 3 PIPELINE ACCURACY CHECK\n";
echo "========================================\n";
echo "Run: docker compose exec app php artisan test --filter=PipelineAccuracyTest\n\n";

$projectDir = __DIR__ . '/..';

if (! is_dir($projectDir . '/laravel-app')) {
    echo "[SKIP] laravel-app directory not found. Run from project root.\n";
    exit(0);
}

// Run the Phase 3 pipeline accuracy test suite via docker compose exec
$output     = [];
$returnCode = 0;

exec(
    "cd " . escapeshellarg($projectDir) . " && docker compose exec -T app php artisan test --filter=PipelineAccuracyTest 2>&1",
    $output,
    $returnCode
);

$outputStr = implode("\n", $output);

// Parse test results from artisan test output
preg_match('/(\d+) passed/', $outputStr, $passedMatch);
preg_match('/(\d+) failed/', $outputStr, $failedMatch);

$passed = (int) ($passedMatch[1] ?? 0);
$failed = (int) ($failedMatch[1] ?? 0);
$total  = $passed + $failed;

define('PIPELINE_BASELINE_TOTAL', 20);
define('PIPELINE_MIN_PASS', 19); // Allow max 1 flaky test (95%)

echo "Phase 3 Pipeline Scenarios: $passed / $total passed";
if ($total > 0) {
    echo " (" . number_format(($passed / $total) * 100, 1) . "%)";
}
echo "\n";

if ($returnCode !== 0 || $failed > 0) {
    echo "[WARN] Phase 3 pipeline accuracy: $failed scenario(s) failed.\n";
    foreach ($output as $line) {
        if (str_contains($line, '⨯') || str_contains($line, 'FAILED') || str_contains($line, 'Error')) {
            echo "  " . $line . "\n";
        }
    }
    if ($passed < PIPELINE_MIN_PASS) {
        echo "[ALERT] Pipeline accuracy below threshold ($passed < " . PIPELINE_MIN_PASS . "). Regression detected!\n";
        exit(1);
    }
    echo "[INFO] Within acceptable threshold (>= " . PIPELINE_MIN_PASS . " pass required).\n";
} else {
    echo "[OK] All $passed Phase 3 pipeline scenarios passed. ✅\n";
}

exit(0);
