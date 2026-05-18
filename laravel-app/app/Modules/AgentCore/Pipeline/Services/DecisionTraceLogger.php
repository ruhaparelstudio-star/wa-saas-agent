<?php

namespace App\Modules\AgentCore\Pipeline\Services;

use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use App\Modules\QualityGuard\Models\ConversationQualityIssue;
use App\Modules\QualityGuard\Models\DecisionTraceViolation;
use App\Modules\Shared\DTOs\ComposedReplyDTO;
use App\Modules\Shared\DTOs\DecisionDTO;
use App\Modules\Shared\DTOs\TurnContextDTO;
use App\Modules\Shared\DTOs\TurnResultDTO;
use App\Modules\Shared\DTOs\ValidatorResultDTO;
use App\Modules\Shared\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

class DecisionTraceLogger
{
    /**
     * Log a full pipeline turn to decision_traces.
     *
     * $llmData keys:
     *   intent_prompt, entity_prompt, composer_prompt,
     *   intent_raw, entity_raw, composer_raw,
     *   token_totals => ['prompt' => int, 'completion' => int],
     *   decision => DecisionDTO,
     *   validator_result => ValidatorResultDTO,
     *   composed_reply => ComposedReplyDTO,
     *   stage_before => string,
     *   conversation_message_id => string,
     *   error_message => string,
     */
    public function log(
        TurnContextDTO $context,
        TurnResultDTO $result,
        array $llmData = [],
    ): DecisionTrace {
        /** @var DecisionDTO|null $decision */
        $decision = $llmData['decision'] ?? null;

        /** @var ValidatorResultDTO|null $validatorResult */
        $validatorResult = $llmData['validator_result'] ?? null;

        /** @var ComposedReplyDTO|null $composedReply */
        $composedReply = $llmData['composed_reply'] ?? null;

        $tokenTotals   = $llmData['token_totals'] ?? [];
        $stageBefore   = $llmData['stage_before'] ?? $context->conversation->stage->value;
        $stageAfter    = $decision?->stage_transition ?? $stageBefore;
        $finalReply    = $composedReply ? $this->maskPhone($composedReply->reply_text) : null;
        $rawMessage    = $this->maskPhone($context->inbound_message->body ?? '');

        $grounding = array_map(
            fn($ref) => is_array($ref) ? $ref : $ref->toArray(),
            $context->knowledge->grounding_refs ?? [],
        );

        $blockedActions = $decision ? array_map(
            fn($b) => is_array($b) ? $b : $b->toArray(),
            $decision->blocked_actions,
        ) : [];

        $validatorWarnings = $validatorResult?->warnings ?? [];

        $trace = DecisionTrace::create([
            'tenant_id'               => $context->tenant->id,
            'conversation_id'         => $context->conversation->id,
            'conversation_message_id' => $llmData['conversation_message_id'] ?? null,

            'raw_message'             => $rawMessage,
            'message_type'            => $context->inbound_message->message_type ?? 'text',
            'is_sanitized'            => $context->is_sanitized,
            'injection_detected'      => $context->injection_detected,

            'intent'                  => $context->intent->intent,
            'intent_confidence'       => $context->intent->confidence,
            'intent_reason'           => $context->intent->reason,
            'intent_raw_response'     => $context->intent->raw_response,

            'extracted_entities'      => $context->entities->entities ?? [],
            'entity_confidence'       => $context->entities->confidence ?? null,
            'needs_clarification'     => $context->entities->needs_clarification ?? [],

            'grounding_refs'          => $grounding,
            'search_method'           => $context->knowledge->search_method ?? null,

            'decision'                => $decision?->decision,
            'desired_actions'         => $decision?->desired_actions ?? [],
            'allowed_actions'         => $decision?->allowed_actions ?? [],
            'blocked_actions'         => $blockedActions,
            'stage_before'            => $stageBefore,
            'stage_after'             => $stageAfter,
            'handoff_required'        => $decision?->handoff_required ?? false,

            'policy_result'           => $validatorResult?->policy_result,
            'grounding_result'        => $validatorResult?->grounding_result,
            'permission_result'       => $validatorResult?->permission_result,
            'mode_result'             => $validatorResult?->mode_result,
            'validator_warnings'      => $validatorWarnings,

            'intent_prompt'           => $llmData['intent_prompt'] ?? null,
            'entity_prompt'           => $llmData['entity_prompt'] ?? null,
            'composer_prompt'         => $llmData['composer_prompt'] ?? null,
            'intent_llm_response'     => $llmData['intent_raw'] ?? null,
            'entity_llm_response'     => $llmData['entity_raw'] ?? null,
            'composer_llm_response'   => $llmData['composer_raw'] ?? null,
            'prompt_tokens_total'     => $tokenTotals['prompt'] ?? 0,
            'completion_tokens_total' => $tokenTotals['completion'] ?? 0,

            'final_reply'             => $finalReply,
            'reply_type'              => $composedReply?->reply_type ?? 'text',
            'detected_hallucination'  => $composedReply?->detected_hallucination ?? false,
            'actions_dispatched'      => $result->actions_dispatched,

            'processing_time_ms'      => $result->processing_time_ms,
            'error_message'           => $llmData['error_message'] ?? null,

            'guard_verdict'           => $llmData['guard_verdict'] ?? null,
            'reply_overridden'        => (bool) ($llmData['reply_overridden'] ?? false),
        ]);

        try {
            $this->persistViolations($trace, $llmData['quality_violations'] ?? []);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('persistViolations failed', ['error' => $e->getMessage()]);
        }

        return $trace;
    }

    /**
     * Persist guard violations to decision_trace_violations + link to issues.
     */
    private function persistViolations(DecisionTrace $trace, array $violationData): void
    {
        if (empty($violationData)) {
            return;
        }

        foreach ($violationData as $v) {
            DecisionTraceViolation::create([
                'id'                => (string) Str::uuid(),
                'tenant_id'         => $trace->tenant_id,
                'decision_trace_id' => $trace->id,
                'code'              => $v['code'] ?? '',
                'severity'          => $v['severity'] ?? '',
                'source'            => 'guard',
                'message'           => $v['message'] ?? '',
                'evidence'          => $v['evidence'] ?? [],
                'created_at'        => now(),
            ]);
        }

        // Link issues (created earlier in pipeline with decision_trace_id=NULL)
        // back to this trace, plus link the violation rows to those issues.
        $issues = ConversationQualityIssue::withoutGlobalScope(TenantScope::class)
            ->where('conversation_id', $trace->conversation_id)
            ->whereNull('decision_trace_id')
            ->where('created_at', '>=', $trace->created_at->copy()->subSeconds(60))
            ->get();

        foreach ($issues as $issue) {
            $issue->update(['decision_trace_id' => $trace->id]);
            DecisionTraceViolation::withoutGlobalScope(TenantScope::class)
                ->where('decision_trace_id', $trace->id)
                ->where('code', $issue->code->value)
                ->whereNull('quality_issue_id')
                ->update(['quality_issue_id' => $issue->id]);
        }
    }

    public function getTracesByConversation(string $conversationId, int $limit = 20): Collection
    {
        return DecisionTrace::where('conversation_id', $conversationId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Mask phone numbers in a string for PII protection.
     * +628121234567 → +6281****567
     * 08121234567   → 0812****567
     */
    public function maskPhone(string $text): string
    {
        // +62 prefix: show first 7 chars, mask middle, show last 3
        $text = preg_replace_callback(
            '/(\+62\d{2,3})\d{3,}(\d{3})/',
            fn($m) => $m[1] . '****' . $m[2],
            $text,
        );

        // 08 prefix: show first 4 chars (08xx), mask middle, show last 3
        $text = preg_replace_callback(
            '/(08\d{2})\d{3,}(\d{3})/',
            fn($m) => $m[1] . '****' . $m[2],
            $text,
        );

        return $text;
    }
}
