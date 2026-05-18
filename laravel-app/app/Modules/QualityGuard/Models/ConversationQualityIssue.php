<?php

namespace App\Modules\QualityGuard\Models;

use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\QualityGuard\Enums\ResolutionType;
use App\Modules\Shared\Models\TenantBaseModel;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationQualityIssue extends TenantBaseModel
{
    protected $table = 'conversation_quality_issues';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'decision_trace_id',
        'code',
        'severity',
        'source',
        'message',
        'evidence',
        'blocked',
        'resolved_at',
        'resolved_by',
        'resolution_type',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'code'            => QualityIssueCode::class,
            'severity'        => QualitySeverity::class,
            'resolution_type' => ResolutionType::class,
            'evidence'        => 'array',
            'blocked'         => 'boolean',
            'resolved_at'     => 'datetime',
        ]);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function decisionTrace(): BelongsTo
    {
        return $this->belongsTo(DecisionTrace::class);
    }
}
