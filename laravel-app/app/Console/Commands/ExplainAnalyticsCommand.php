<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExplainAnalyticsCommand extends Command
{
    protected $signature   = 'db:explain-analytics';
    protected $description = 'Run EXPLAIN ANALYZE on key analytics queries to verify index usage';

    public function handle(): int
    {
        $tenantId = '00000000-0000-0000-0000-000000000000';

        $queries = [
            'Lead funnel (conversations by stage)' =>
                "SELECT stage, COUNT(*) FROM conversations WHERE tenant_id = '{$tenantId}' AND created_at BETWEEN NOW() - INTERVAL '30 days' AND NOW() GROUP BY stage",
            'Revenue (paid invoices)' =>
                "SELECT SUM(amount) FROM invoices WHERE tenant_id = '{$tenantId}' AND status = 'paid' AND paid_at BETWEEN NOW() - INTERVAL '30 days' AND NOW()",
            'Booking availability check' =>
                "SELECT * FROM bookings WHERE tenant_id = '{$tenantId}' AND event_date = '2026-09-15' AND status IN ('confirmed','awaiting_dp','paid')",
            'Follow-up candidates' =>
                "SELECT * FROM conversations WHERE tenant_id = '{$tenantId}' AND stage IN ('new_lead','exploration') AND last_message_at < NOW() - INTERVAL '3 days'",
        ];

        foreach ($queries as $label => $sql) {
            $this->info("--- {$label} ---");
            $plan = DB::select("EXPLAIN ANALYZE {$sql}");
            foreach ($plan as $row) {
                $line = array_values((array) $row)[0];
                $color = str_contains($line, 'Seq Scan') ? 'yellow' : 'white';
                $this->line("<fg={$color}>{$line}</>");
            }
            $this->newLine();
        }

        $this->info('Done. Yellow lines indicate Seq Scan (possible missing index).');
        return self::SUCCESS;
    }
}
