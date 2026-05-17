<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RunBenchmarkCommand extends Command
{
    protected $signature   = 'benchmark:run {--filter= : Run a specific benchmark test filter}';
    protected $description = 'Run all 30 benchmark scenarios and print results table';

    private const BENCHMARK_SUITES = [
        ['file' => 'BenchmarkHappyPathTest',  'label' => 'Happy Path   (S001-005)'],
        ['file' => 'BenchmarkObjectionTest',  'label' => 'Objections   (S006-010)'],
        ['file' => 'BenchmarkEdgeCasesTest',  'label' => 'Edge Cases   (S011-015)'],
        ['file' => 'BenchmarkConcurrencyTest','label' => 'Concurrency  (S016-020)'],
        ['file' => 'BenchmarkSecurityTest',   'label' => 'Security     (S021-025)'],
        ['file' => 'BenchmarkHandoffTest',    'label' => 'Handoff      (S026-030)'],
    ];

    public function handle(): int
    {
        $this->line('');
        $this->info('═══════════════════════════════════════════════════');
        $this->info('  WA SaaS AI Agent — Benchmark 30 Scenarios');
        $this->info('═══════════════════════════════════════════════════');
        $this->line('');

        $filter = $this->option('filter');

        $results  = [];
        $allPassed = true;
        $totalMs   = 0;

        foreach (self::BENCHMARK_SUITES as $suite) {
            if ($filter && !str_contains(strtolower($suite['file']), strtolower($filter))) {
                continue;
            }

            $start = microtime(true);
            [$passed, $output] = $this->runSuite($suite['file']);
            $ms = (int) ((microtime(true) - $start) * 1000);
            $totalMs += $ms;

            $results[] = [
                'label'  => $suite['label'],
                'status' => $passed ? '<fg=green>PASS</>' : '<fg=red>FAIL</>',
                'ms'     => $ms,
                'output' => $output,
            ];

            if (!$passed) {
                $allPassed = false;
            }
        }

        $this->printTable($results);
        $this->line('');

        if ($allPassed) {
            $this->info("  ✅ ALL BENCHMARK SCENARIOS PASSED ({$totalMs}ms total)");
            $this->line('');
            return self::SUCCESS;
        }

        $this->error('  ❌ SOME BENCHMARK SCENARIOS FAILED');
        $this->line('');

        foreach ($results as $result) {
            if (str_contains($result['status'], 'FAIL')) {
                $this->line("<fg=red>── {$result['label']} output ──</>");
                $this->line($result['output']);
                $this->line('');
            }
        }

        return self::FAILURE;
    }

    private function runSuite(string $testClass): array
    {
        $phpunit = base_path('vendor/bin/phpunit');
        $filter  = "tests/Feature/Benchmark/{$testClass}.php";

        $process = new Process(
            ['php', $phpunit, $filter, '--no-coverage', '--colors=never'],
            base_path(),
            ['APP_ENV' => 'testing'],
        );

        $process->setTimeout(120);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();
        $passed = $process->isSuccessful();

        return [$passed, $output];
    }

    private function printTable(array $results): void
    {
        $this->line('  ┌──────────────────────────────┬────────┬──────────┐');
        $this->line('  │ Suite                         │ Status │   Time   │');
        $this->line('  ├──────────────────────────────┼────────┼──────────┤');

        foreach ($results as $result) {
            $label  = str_pad($result['label'], 29);
            $ms     = str_pad($result['ms'] . 'ms', 8, ' ', STR_PAD_LEFT);
            $this->line("  │ {$label} │ {$result['status']}   │ {$ms} │");
        }

        $this->line('  └──────────────────────────────┴────────┴──────────┘');
    }
}
