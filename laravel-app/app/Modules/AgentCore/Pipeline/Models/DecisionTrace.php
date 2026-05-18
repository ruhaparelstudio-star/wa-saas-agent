<?php

namespace App\Modules\AgentCore\Pipeline\Models;

use App\Modules\Shared\Models\TenantBaseModel;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionTrace extends TenantBaseModel
{
    protected $table = 'decision_traces';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'conversation_message_id',
        'raw_message',
        'message_type',
        'is_sanitized',
        'injection_detected',
        'intent',
        'intent_confidence',
        'intent_reason',
        'intent_raw_response',
        'extracted_entities',
        'entity_confidence',
        'needs_clarification',
        'grounding_refs',
        'search_method',
        'decision',
        'desired_actions',
        'allowed_actions',
        'blocked_actions',
        'stage_before',
        'stage_after',
        'handoff_required',
        'policy_result',
        'grounding_result',
        'permission_result',
        'mode_result',
        'validator_warnings',
        'intent_prompt',
        'entity_prompt',
        'composer_prompt',
        'intent_llm_response',
        'entity_llm_response',
        'composer_llm_response',
        'prompt_tokens_total',
        'completion_tokens_total',
        'final_reply',
        'reply_type',
        'detected_hallucination',
        'actions_dispatched',
        'processing_time_ms',
        'error_message',
        'quality_score',
        'llm_grade',
        'llm_graded_at',
        'guard_verdict',
        'reply_overridden',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'is_sanitized'          => 'boolean',
            'injection_detected'    => 'boolean',
            'handoff_required'      => 'boolean',
            'detected_hallucination' => 'boolean',
            'intent_confidence'     => 'float',
            'entity_confidence'     => 'float',
            'extracted_entities'    => 'array',
            'needs_clarification'   => 'array',
            'grounding_refs'        => 'array',
            'desired_actions'       => 'array',
            'allowed_actions'       => 'array',
            'blocked_actions'       => 'array',
            'validator_warnings'    => 'array',
            'actions_dispatched'    => 'array',
            'prompt_tokens_total'   => 'integer',
            'completion_tokens_total' => 'integer',
            'processing_time_ms'    => 'integer',
            'quality_score'         => 'float',
            'llm_grade'             => 'array',
            'llm_graded_at'         => 'datetime',
            'reply_overridden'      => 'boolean',
        ]);
    }
}
