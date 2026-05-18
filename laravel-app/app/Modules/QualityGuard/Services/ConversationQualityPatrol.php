<?php

namespace App\Modules\QualityGuard\Services;

use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use App\Modules\Handoff\Models\HandoffRecord;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\QualityGuard\Enums\ResolutionType;
use App\Modules\QualityGuard\Models\ConversationQualityIssue;
use App\Modules\QualityGuard\Models\DecisionTraceViolation;
use App\Modules\Shared\Scopes\TenantScope;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ConversationQualityPatrol
{
    public function __construct(
        private readonly ConversationQualityGuard $guard,
    ) {}

    /**
     * Scan recent decision_traces and create/update quality issues.
     *
     * @return array{scanned: int, issues_created: int, resolved: int}
     */
    public function patrol(Carbon $since, bool $dryRun = false): array
    {
        $traces = DecisionTrace::withoutGlobalScope(TenantScope::class)
            ->where('created_at', '>=', $since)
            ->orderBy('created_at')
            ->get();

        $issuesCreated = 0;

        foreach ($traces as $trace) {
            foreach ($this->guard->rulesByCode() as $code => $rule) {
                try {
                    $violation = $rule->evaluateAgainstTrace($trace);
                } catch (\Throwable $e) {
                    Log::warning('QualityRule patrol error', [
                        'code'  => $code,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }

                if ($violation === null) {
                    continue;
                }

                // Idempotent by (decision_trace_id, code)
                $existing = ConversationQualityIssue::withoutGlobalScope(TenantScope::class)
                    ->where('decision_trace_id', $trace->id)
                    ->where('code', $violation->code->value)
                    ->first();

                if ($existing !== null) {
                    continue;
                }

                if ($dryRun) {
                    $issuesCreated++;
                    continue;
                }

                $issue = ConversationQualityIssue::create([
                    'tenant_id'         => $trace->tenant_id,
                    'conversation_id'   => $trace->conversation_id,
                    'decision_trace_id' => $trace->id,
                    'code'              => $violation->code->value,
                    'severity'          => $violation->severity->value,
                    'source'            => 'patrol',
                    'message'           => $violation->message,
                    'evidence'          => $violation->evidence,
                    'blocked'           => false,
                ]);

                DecisionTraceViolation::create([
                    'id'                => (string) Str::uuid(),
                    'tenant_id'         => $trace->tenant_id,
                    'decision_trace_id' => $trace->id,
                    'quality_issue_id'  => $issue->id,
                    'code'              => $violation->code->value,
                    'severity'          => $violation->severity->value,
                    'source'            => 'patrol',
                    'message'           => $violation->message,
                    'evidence'          => $violation->evidence,
                    'created_at'        => now(),
                ]);

                $issuesCreated++;
            }
        }

        $resolved = $dryRun ? 0 : $this->autoResolveIssues();

        return [
            'scanned'        => $traces->count(),
            'issues_created' => $issuesCreated,
            'resolved'       => $resolved,
        ];
    }

    /**
     * Auto-resolve issues whose triggering condition has cleared.
     *
     * @return int  count of issues resolved
     */
    public function autoResolveIssues(): int
    {
        $now      = now();
        $count    = 0;
        $rulesMap = $this->guard->rulesByCode();

        // 1. AUTO_RULE_PASS — newer trace passes the same rule.
        ConversationQualityIssue::withoutGlobalScope(TenantScope::class)
            ->whereNull('resolved_at')
            ->orderBy('created_at')
            ->chunk(100, function ($issues) use ($rulesMap, $now, &$count) {
                foreach ($issues as $issue) {
                    $codeValue = $issue->code->value;
                    $rule      = $rulesMap[$codeValue] ?? null;
                    if ($rule === null) {
                        continue;
                    }

                    $latestTrace = DecisionTrace::withoutGlobalScope(TenantScope::class)
                        ->where('conversation_id', $issue->conversation_id)
                        ->where('created_at', '>', $issue->created_at)
                        ->latest('created_at')
                        ->first();

                    if ($latestTrace === null) {
                        continue;
                    }

                    try {
                        $stillFailing = $rule->evaluateAgainstTrace($latestTrace);
                    } catch (\Throwable) {
                        continue;
                    }

                    if ($stillFailing === null) {
                        $issue->update([
                            'resolved_at'      => $now,
                            'resolution_type'  => ResolutionType::AUTO_RULE_PASS->value,
                            'resolution_notes' => "Rule passed in trace {$latestTrace->id}",
                        ]);
                        $count++;
                    }
                }
            });

        // 2. AUTO_HANDOFF_RESOLVED — handoff_records.status='resolved' resolves
        //    all CRITICAL issues for that conversation.
        $recentResolved = HandoffRecord::withoutGlobalScope(TenantScope::class)
            ->where('status', 'resolved')
            ->where('resolved_at', '>=', $now->copy()->subHours(2))
            ->pluck('conversation_id')
            ->all();

        if (!empty($recentResolved)) {
            $count += ConversationQualityIssue::withoutGlobalScope(TenantScope::class)
                ->whereNull('resolved_at')
                ->where('severity', QualitySeverity::CRITICAL->value)
                ->whereIn('conversation_id', $recentResolved)
                ->update([
                    'resolved_at'     => $now,
                    'resolution_type' => ResolutionType::AUTO_HANDOFF_RESOLVED->value,
                ]);
        }

        // 3. AUTO_STALE — LOW severity > 7 days untouched.
        $count += ConversationQualityIssue::withoutGlobalScope(TenantScope::class)
            ->whereNull('resolved_at')
            ->where('severity', QualitySeverity::LOW->value)
            ->where('created_at', '<', $now->copy()->subDays(7))
            ->update([
                'resolved_at'     => $now,
                'resolution_type' => ResolutionType::AUTO_STALE->value,
            ]);

        return $count;
    }
}
