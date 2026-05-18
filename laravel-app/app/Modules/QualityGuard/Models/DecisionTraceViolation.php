<?php

namespace App\Modules\QualityGuard\Models;

use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use App\Modules\QualityGuard\Enums\QualityIssueCode;
use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\Shared\Models\TenantBaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DecisionTraceViolation extends TenantBaseModel
{
    protected $table = 'decision_trace_violations';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'decision_trace_id',
        'quality_issue_id',
        'code',
        'severity',
        'source',
        'message',
        'evidence',
        'created_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'code'       => QualityIssueCode::class,
            'severity'   => QualitySeverity::class,
            'evidence'   => 'array',
            'created_at' => 'datetime',
        ]);
    }

    public function decisionTrace(): BelongsTo
    {
        return $this->belongsTo(DecisionTrace::class);
    }

    public function qualityIssue(): BelongsTo
    {
        return $this->belongsTo(ConversationQualityIssue::class, 'quality_issue_id');
    }
}
