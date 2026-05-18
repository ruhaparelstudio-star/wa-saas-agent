<?php

namespace App\Modules\QualityGuard\Jobs;

use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\QualityGuard\Models\ConversationQualityIssue;
use App\Modules\QualityGuard\Models\DecisionTraceViolation;
use App\Modules\QualityGuard\Services\LlmReplyGraderService;
use App\Modules\Shared\Scopes\TenantScope;
use App\Modules\TenantConfig\Models\TenantSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GradeReplyWithLlmJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $traceId,
    ) {
        $this->onQueue('quality-grading');
    }

    public function handle(LlmReplyGraderService $grader): void
    {
        $trace = DecisionTrace::withoutGlobalScope(TenantScope::class)->find($this->traceId);
        if ($trace === null) {
            return;
        }

        $settings = TenantSetting::where('tenant_id', $trace->tenant_id)->first();
        if (!$settings || empty($settings->llm_grader_enabled)) {
            return;
        }

        // Redis throttle: max 1 LLM call per tenant per 60s
        $throttleKey = "llm_grader:throttle:{$trace->tenant_id}";
        if (Cache::get($throttleKey)) {
            Log::info('LlmGrader throttled', ['tenant_id' => $trace->tenant_id]);
            return;
        }
        Cache::put($throttleKey, true, 60);

        $grade = $grader->grade($trace);
        if ($grade === null) {
            return;
        }

        $guardScore = $trace->quality_score;
        $combinedScore = $guardScore !== null
            ? round(((float) $guardScore + $grade->overall) / 2, 2)
            : $grade->overall;

        $trace->update([
            'llm_grade'     => $grade->toArray(),
            'llm_graded_at' => now(),
            'quality_score' => $combinedScore,
        ]);

        $this->maybeRecordLowScoreIssue($trace, $grade);
    }

    private function maybeRecordLowScoreIssue(DecisionTrace $trace, $grade): void
    {
        $axesBelow = [];
        foreach (['relevance', 'groundedness', 'tone', 'safety'] as $axis) {
            if ($grade->{$axis} < 0.6) {
                $axesBelow[] = $axis;
            }
        }

        if (empty($axesBelow)) {
            return;
        }

        $severity = $grade->overall < 0.6 ? QualitySeverity::HIGH : QualitySeverity::LOW;

        // Idempotent by (decision_trace_id, code)
        $existing = ConversationQualityIssue::withoutGlobalScope(TenantScope::class)
            ->where('decision_trace_id', $trace->id)
            ->where('code', QualityIssueCode::LLM_GRADER_LOW_SCORE->value)
            ->first();
        if ($existing !== null) {
            return;
        }

        $issue = ConversationQualityIssue::create([
            'tenant_id'         => $trace->tenant_id,
            'conversation_id'   => $trace->conversation_id,
            'decision_trace_id' => $trace->id,
            'code'              => QualityIssueCode::LLM_GRADER_LOW_SCORE->value,
            'severity'          => $severity->value,
            'source'            => 'llm_grader',
            'message'           => 'LLM grader: low score on axes ' . implode(', ', $axesBelow),
            'evidence'          => [
                'axes_below_threshold' => $axesBelow,
                'grade'                => $grade->toArray(),
            ],
        ]);

        DecisionTraceViolation::create([
            'id'                => (string) Str::uuid(),
            'tenant_id'         => $trace->tenant_id,
            'decision_trace_id' => $trace->id,
            'quality_issue_id'  => $issue->id,
            'code'              => QualityIssueCode::LLM_GRADER_LOW_SCORE->value,
            'severity'          => $severity->value,
            'source'            => 'llm_grader',
            'message'           => 'LLM grader: low score on axes ' . implode(', ', $axesBelow),
            'evidence'          => $grade->toArray(),
            'created_at'        => now(),
        ]);
    }
}
