<?php

namespace App\Modules\Conversation\Models;

use App\Modules\Lead\Models\Lead;
use App\Modules\Shared\Enums\AgentMode;
use App\Modules\Shared\Enums\ConversationStage;
use App\Modules\Shared\Enums\LeadTemperature;
use App\Modules\Shared\Enums\MemoryMode;
use App\Modules\Shared\Models\TenantBaseModel;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\WhatsApp\Models\WaAccount;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends TenantBaseModel
{
    protected $table = 'conversations';

    protected $fillable = [
        'tenant_id',
        'wa_account_id',
        'customer_phone',
        'customer_name',
        'stage',
        'agent_mode',
        'memory_mode',
        'lead_temperature',
        'entity_cache',
        'context_summary',
        'message_count',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'stage'            => ConversationStage::class,
            'agent_mode'       => AgentMode::class,
            'memory_mode'      => MemoryMode::class,
            'lead_temperature' => LeadTemperature::class,
            'entity_cache'     => 'array',
            'last_message_at'  => 'datetime',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class, 'conversation_id');
    }

    public function lead(): HasOne
    {
        return $this->hasOne(Lead::class, 'conversation_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function waAccount(): BelongsTo
    {
        return $this->belongsTo(WaAccount::class, 'wa_account_id');
    }

    public function isActive(): bool
    {
        return $this->agent_mode === AgentMode::ACTIVE;
    }

    public function isHandoff(): bool
    {
        return $this->agent_mode === AgentMode::HANDOFF;
    }

    public function isPaused(): bool
    {
        return $this->agent_mode === AgentMode::PAUSED;
    }

    public function isDormant(): bool
    {
        return $this->memory_mode === MemoryMode::DORMANT;
    }

    public function addMessage(array $data): ConversationMessage
    {
        $data['conversation_id'] = $this->id;
        $data['tenant_id'] = $this->tenant_id;

        $message = $this->messages()->create($data);

        $this->increment('message_count');
        $this->update(['last_message_at' => now()]);

        return $message;
    }

    public function updateEntityCache(array $entities): void
    {
        $current = $this->entity_cache ?? [];
        $merged = array_merge($current, array_filter($entities, fn($v) => $v !== null));
        $this->update(['entity_cache' => $merged]);
    }

    public function getRecentMessages(int $limit = 10): Collection
    {
        return $this->messages()
            ->orderBy('created_at', 'asc')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->sortBy('created_at')
            ->values();
    }
}
