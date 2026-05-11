<?php

/**
 * Sub-task 0.2 — Intent Classifier Accuracy Test
 *
 * Jalankan: OPENAI_API_KEY=sk-xxx php poc/test_intent_accuracy.php
 * Output disimpan ke results/run{N}.txt
 */

require_once __DIR__ . '/poc_conversation.php';

define('TEST_DATA_PATH', __DIR__ . '/../tests/conversation-data/intent-test-cases.json');
define('RESULTS_DIR', __DIR__ . '/../results');
define('INTENT_ACCURACY_GATE', 0.85);
define('LOW_CONFIDENCE_THRESHOLD', 0.70);

// ── Helpers ──────────────────────────────────────────────────────────────────

function getNextRunNumber(): int
{
    $files = glob(RESULTS_DIR . '/run*.txt');
    if (empty($files)) {
        return 1;
    }
    $numbers = array_map(function ($f) {
        preg_match('/run(\d+)\.txt$/', $f, $m);
        return (int) ($m[1] ?? 0);
    }, $files);
    return max($numbers) + 1;
}

function teeOutput(string $line, $fh): void
{
    echo $line;
    fwrite($fh, $line);
}

// ── Main ─────────────────────────────────────────────────────────────────────

if (!OPENAI_API_KEY) {
    fwrite(STDERR, "ERROR: OPENAI_API_KEY tidak di-set.\n");
    exit(1);
}

$raw = file_get_contents(TEST_DATA_PATH);
if ($raw === false) {
    fwrite(STDERR, "ERROR: Tidak bisa baca " . TEST_DATA_PATH . "\n");
    exit(1);
}

$testCases = json_decode($raw, true);
if (!is_array($testCases)) {
    fwrite(STDERR, "ERROR: JSON tidak valid di " . TEST_DATA_PATH . "\n");
    exit(1);
}

$runN   = getNextRunNumber();
$outPath = RESULTS_DIR . "/run{$runN}.txt";
$fh     = fopen($outPath, 'w');
if (!$fh) {
    fwrite(STDERR, "ERROR: Tidak bisa tulis ke $outPath\n");
    exit(1);
}

$llm = new PocLlmClient(OPENAI_API_KEY);

$passed          = 0;
$failed          = 0;
$lowConfidence   = [];
$failedCases     = [];

$header = "========================================\n";
$header .= "INTENT ACCURACY TEST — Run #$runN\n";
$header .= "Date: " . date('Y-m-d H:i:s') . "\n";
$header .= "Total cases: " . count($testCases) . "\n";
$header .= "========================================\n\n";
teeOutput($header, $fh);

foreach ($testCases as $case) {
    $id       = $case['id']              ?? '?';
    $message  = $case['message']         ?? '';
    $expected = $case['expected_intent'] ?? '';
    $context  = $case['context']         ?? '';

    try {
        $result     = $llm->classifyIntent($message, $context);
        $actual     = $result['intent'];
        $confidence = $result['confidence'];
        $confStr    = number_format($confidence, 2);

        $isPass = ($actual === $expected);

        if ($isPass) {
            $passed++;
            $line = "[PASS] $id: \"$message\" → $actual (conf: $confStr)\n";
        } else {
            $failed++;
            $failedCases[] = ['id' => $id, 'message' => $message, 'expected' => $expected, 'actual' => $actual, 'conf' => $confidence];
            $line = "[FAIL] $id: \"$message\" → got: $actual | expected: $expected (conf: $confStr)\n";
        }

        if ($confidence < LOW_CONFIDENCE_THRESHOLD) {
            $lowConfidence[] = ['id' => $id, 'message' => $message, 'actual' => $actual, 'conf' => $confidence];
        }

        teeOutput($line, $fh);

    } catch (RuntimeException $e) {
        $failed++;
        $failedCases[] = ['id' => $id, 'message' => $message, 'expected' => $expected, 'actual' => 'ERROR', 'conf' => 0.0];
        $line = "[ERROR] $id: \"$message\" → " . $e->getMessage() . "\n";
        teeOutput($line, $fh);
    }

    usleep(200000); // 200ms delay to avoid rate limit
}

$total       = count($testCases);
$accuracy    = $total > 0 ? $passed / $total : 0.0;
$accuracyPct = number_format($accuracy * 100, 1);
$gate        = $accuracy >= INTENT_ACCURACY_GATE ? 'PASS ✅' : 'FAIL ❌ (target >= 85%)';

$summary  = "\n========================================\n";
$summary .= "SUMMARY — Run #$runN\n";
$summary .= "========================================\n";
$summary .= "Accuracy : $passed / $total = {$accuracyPct}%  →  Gate: $gate\n";
$summary .= "Passed   : $passed\n";
$summary .= "Failed   : $failed\n";
$summary .= "\n";

if (!empty($failedCases)) {
    $summary .= "--- FAILED CASES ---\n";
    foreach ($failedCases as $fc) {
        $summary .= "  [{$fc['id']}] \"{$fc['message']}\" → got: {$fc['actual']} | expected: {$fc['expected']} (conf: " . number_format($fc['conf'], 2) . ")\n";
    }
    $summary .= "\n";
}

if (!empty($lowConfidence)) {
    $summary .= "--- LOW CONFIDENCE (< " . (LOW_CONFIDENCE_THRESHOLD * 100) . "%) ---\n";
    foreach ($lowConfidence as $lc) {
        $summary .= "  [{$lc['id']}] \"{$lc['message']}\" → {$lc['actual']} (conf: " . number_format($lc['conf'], 2) . ")\n";
    }
    $summary .= "\n";
}

$summary .= "Output saved to: $outPath\n";
$summary .= "========================================\n";

teeOutput($summary, $fh);
fclose($fh);

exit($accuracy >= INTENT_ACCURACY_GATE ? 0 : 1);
