<?php

/**
 * Sub-task 0.3 — Entity Extractor Accuracy Test
 *
 * Jalankan: OPENAI_API_KEY=sk-xxx php poc/test_entity_accuracy.php
 * Output disimpan ke results/entity-run{N}.txt
 *
 * Scoring:
 *   FULL PASS : semua expected_entities key match
 *   PARTIAL   : >= 50% expected keys match
 *   FAIL      : < 50% match ATAU entity kritis (customer_name / event_date) salah
 */

require_once __DIR__ . '/poc_conversation.php';

define('ENTITY_TEST_DATA_PATH', __DIR__ . '/../tests/conversation-data/entity-test-cases.json');
define('ENTITY_RESULTS_DIR', __DIR__ . '/../results');
define('ENTITY_ACCURACY_GATE', 0.85);
define('CRITICAL_ENTITIES', ['customer_name', 'event_date']);

// ── Helpers ──────────────────────────────────────────────────────────────────

function getNextEntityRunNumber(): int
{
    $files = glob(ENTITY_RESULTS_DIR . '/entity-run*.txt');
    if (empty($files)) {
        return 1;
    }
    $numbers = array_map(function ($f) {
        preg_match('/entity-run(\d+)\.txt$/', $f, $m);
        return (int) ($m[1] ?? 0);
    }, $files);
    return max($numbers) + 1;
}

function teeEntityOutput(string $line, $fh): void
{
    echo $line;
    fwrite($fh, $line);
}

/**
 * Compare expected vs actual entity value.
 * Integers within ±5% tolerance; strings case-insensitive; null == null.
 */
function entityValuesMatch(mixed $expected, mixed $actual): bool
{
    if ($expected === null && $actual === null) {
        return true;
    }
    if ($expected === null || $actual === null) {
        return false;
    }
    if (is_int($expected) && is_int($actual)) {
        $tolerance = abs($expected) * 0.05;
        return abs($expected - $actual) <= max($tolerance, 1);
    }
    if (is_string($expected) && is_string($actual)) {
        return strtolower(trim($expected)) === strtolower(trim($actual));
    }
    if (is_bool($expected) && is_bool($actual)) {
        return $expected === $actual;
    }
    return $expected == $actual;
}

/**
 * Score a test case.
 * Returns: ['grade' => 'FULL PASS'|'PARTIAL'|'FAIL', 'score' => float, 'details' => array]
 */
function scoreEntityCase(array $case, array $extractionResult): array
{
    $expected       = $case['expected_entities']          ?? [];
    $expectedCorr   = $case['expected_corrections']       ?? [];
    $expectedClarif = $case['expected_needs_clarification'] ?? [];
    $actualEntities = $extractionResult['entities']       ?? [];
    $actualCorr     = $extractionResult['corrections']    ?? [];
    $actualClarif   = $extractionResult['needs_clarification'] ?? [];

    $details       = [];
    $matched       = 0;
    $criticalFail  = false;

    $checkKeys = array_unique(array_merge(
        array_keys($expected),
        array_keys($actualEntities)
    ));

    foreach ($expected as $key => $expVal) {
        $actVal = $actualEntities[$key] ?? null;
        $match  = entityValuesMatch($expVal, $actVal);

        if ($match) {
            $matched++;
            $details[] = "  ✅ $key: " . json_encode($expVal) . " == " . json_encode($actVal);
        } else {
            $details[] = "  ❌ $key: expected=" . json_encode($expVal) . " | actual=" . json_encode($actVal);
            if (in_array($key, CRITICAL_ENTITIES, true)) {
                $criticalFail = true;
            }
        }
    }

    // Check corrections
    if (!empty($expectedCorr)) {
        $corrMissing = array_diff($expectedCorr, $actualCorr);
        if (empty($corrMissing)) {
            $details[] = "  ✅ corrections: " . implode(', ', $expectedCorr) . " detected";
            $matched++;
        } else {
            $details[] = "  ❌ corrections: expected [" . implode(', ', $expectedCorr) . "] | got [" . implode(', ', $actualCorr) . "]";
        }
    }

    // Check needs_clarification
    if (!empty($expectedClarif)) {
        $clarifMissing = array_diff($expectedClarif, $actualClarif);
        if (empty($clarifMissing)) {
            $details[] = "  ✅ needs_clarification: " . implode(', ', $expectedClarif) . " flagged";
            $matched++;
        } else {
            $details[] = "  ❌ needs_clarification: expected [" . implode(', ', $expectedClarif) . "] | got [" . implode(', ', $actualClarif) . "]";
        }
    }

    $total = count($expected) + (empty($expectedCorr) ? 0 : 1) + (empty($expectedClarif) ? 0 : 1);
    $total = max($total, 1);
    $ratio = $matched / $total;

    if ($criticalFail || $ratio < 0.5) {
        $grade = 'FAIL';
        $score = 0.0;
    } elseif ($ratio >= 1.0) {
        $grade = 'FULL PASS';
        $score = 1.0;
    } else {
        $grade = 'PARTIAL';
        $score = 0.5;
    }

    return ['grade' => $grade, 'score' => $score, 'details' => $details, 'ratio' => $ratio];
}

// ── Main ─────────────────────────────────────────────────────────────────────

if (!OPENAI_API_KEY) {
    fwrite(STDERR, "ERROR: OPENAI_API_KEY tidak di-set.\n");
    exit(1);
}

$raw = file_get_contents(ENTITY_TEST_DATA_PATH);
if ($raw === false) {
    fwrite(STDERR, "ERROR: Tidak bisa baca " . ENTITY_TEST_DATA_PATH . "\n");
    exit(1);
}

$testCases = json_decode($raw, true);
if (!is_array($testCases)) {
    fwrite(STDERR, "ERROR: JSON tidak valid di " . ENTITY_TEST_DATA_PATH . "\n");
    exit(1);
}

$runN    = getNextEntityRunNumber();
$outPath = ENTITY_RESULTS_DIR . "/entity-run{$runN}.txt";
$fh      = fopen($outPath, 'w');
if (!$fh) {
    fwrite(STDERR, "ERROR: Tidak bisa tulis ke $outPath\n");
    exit(1);
}

$llm = new PocLlmClient(OPENAI_API_KEY);

$totalScore = 0.0;
$fullPass   = 0;
$partial    = 0;
$fail       = 0;
$errorCases = [];

$header  = "========================================\n";
$header .= "ENTITY ACCURACY TEST — Run #$runN\n";
$header .= "Date: " . date('Y-m-d H:i:s') . "\n";
$header .= "Total cases: " . count($testCases) . "\n";
$header .= "========================================\n\n";
teeEntityOutput($header, $fh);

foreach ($testCases as $case) {
    $id               = $case['id']               ?? '?';
    $message          = $case['message']           ?? '';
    $existingEntities = $case['existing_entities'] ?? [];

    try {
        $result = $llm->extractEntities($message, $existingEntities);
        $scored = scoreEntityCase($case, $result);

        $grade  = $scored['grade'];
        $score  = $scored['score'];
        $pct    = number_format($scored['ratio'] * 100, 0);

        match ($grade) {
            'FULL PASS' => $fullPass++,
            'PARTIAL'   => $partial++,
            'FAIL'      => $fail++,
        };

        $totalScore += $score;

        $prefix = match ($grade) {
            'FULL PASS' => '[FULL PASS]',
            'PARTIAL'   => '[PARTIAL  ]',
            'FAIL'      => '[FAIL     ]',
        };

        $line = "$prefix $id: \"$message\" ($pct% match)\n";
        teeEntityOutput($line, $fh);

        foreach ($scored['details'] as $detail) {
            teeEntityOutput($detail . "\n", $fh);
        }
        teeEntityOutput("\n", $fh);

    } catch (RuntimeException $e) {
        $fail++;
        $errorCases[] = $id;
        $line = "[ERROR    ] $id: \"$message\" → " . $e->getMessage() . "\n\n";
        teeEntityOutput($line, $fh);
    }

    usleep(200000);
}

$total        = count($testCases);
$overallScore = $total > 0 ? $totalScore / $total : 0.0;
$overallPct   = number_format($overallScore * 100, 1);
$gate         = $overallScore >= ENTITY_ACCURACY_GATE ? 'PASS ✅' : 'FAIL ❌ (target >= 85%)';

$summary  = "========================================\n";
$summary .= "SUMMARY — Run #$runN\n";
$summary .= "========================================\n";
$summary .= "Overall Accuracy : {$overallPct}%  →  Gate: $gate\n";
$summary .= "Full Pass        : $fullPass / $total\n";
$summary .= "Partial          : $partial / $total\n";
$summary .= "Fail             : $fail / $total\n";

if (!empty($errorCases)) {
    $summary .= "Errors           : " . implode(', ', $errorCases) . "\n";
}

$summary .= "\nScoring: Full Pass=1.0 | Partial=0.5 | Fail=0.0\n";
$summary .= "Output saved to: $outPath\n";
$summary .= "========================================\n";

teeEntityOutput($summary, $fh);
fclose($fh);

exit($overallScore >= ENTITY_ACCURACY_GATE ? 0 : 1);
