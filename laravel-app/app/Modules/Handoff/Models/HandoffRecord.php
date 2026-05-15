<?php

namespace App\Modules\Handoff\Models;

use App\Modules\Conversation\Models\Conversation;
use App\Modules\Shared\Enums\HandoffPriority;
use App\Modules\Shared\Enums\HandoffStatus;
use App\Modules\Shared\Models\TenantBaseModel;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HandoffRecord extends TenantBaseModel
{
    protected $table = 'handoff_records';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'wa_account_id',
        'assigned_to',
        'status',
        'priority',
        'reason',
        'trigger_intent',
        'resolved_at',
        'resolution_notes',
    ];

    protected function casts(): array
    {
        return [
            'status'      => HandoffStatus::class,
            'priority'    => HandoffPriority::class,
            'resolved_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'assigned_to');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [HandoffStatus::PENDING, HandoffStatus::IN_PROGRESS], true);
    }

    public function resolve(string $notes = ''): void
    {
        $this->update([
            'status'           => HandoffStatus::RESOLVED->value,
            'resolved_at'      => now(),
            'resolution_notes' => $notes,
        ]);
    }

    public function assign(string $userId): void
    {
        $this->update([
            'assigned_to' => $userId,
            'status'      => HandoffStatus::IN_PROGRESS->value,
        ]);
    }
}
