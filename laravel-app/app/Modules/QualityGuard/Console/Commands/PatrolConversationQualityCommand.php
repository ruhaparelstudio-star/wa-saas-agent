<?php

namespace App\Modules\QualityGuard\Console\Commands;

use App\Modules\QualityGuard\Services\ConversationQualityPatrol;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PatrolConversationQualityCommand extends Command
{
    protected $signature = 'quality:patrol
        {--since=15m : Window to scan (e.g. 15m, 1h, 24h)}
        {--dry-run : Print findings without writing to DB}';

    protected $description = 'Scan recent decision_traces for quality issues + auto-resolve cleared issues.';

    public function handle(ConversationQualityPatrol $patrol): int
    {
        $window = $this->option('since');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $since = $this->parseWindow($window);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::INVALID;
        }

        $this->info("Patrolling decision_traces since {$since->toIso8601String()}" . ($dryRun ? ' (dry-run)' : ''));

        $result = $patrol->patrol($since, $dryRun);

        $this->table(
            ['Scanned', 'Issues created', 'Issues resolved'],
            [[$result['scanned'], $result['issues_created'], $result['resolved']]],
        );

        return self::SUCCESS;
    }

    private function parseWindow(string $w): Carbon
    {
        if (!preg_match('/^(\d+)(m|h|d)$/', $w, $m)) {
            throw new \InvalidArgumentException("Invalid window: {$w}. Use like 15m, 1h, 24h.");
        }
        $n = (int) $m[1];
        return match ($m[2]) {
            'm' => now()->subMinutes($n),
            'h' => now()->subHours($n),
            'd' => now()->subDays($n),
        };
    }
}
