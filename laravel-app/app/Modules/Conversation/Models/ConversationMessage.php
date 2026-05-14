<?php

namespace App\Modules\Conversation\Models;

use App\Modules\Shared\Enums\MessageType;
use App\Modules\Shared\Models\TenantBaseModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends TenantBaseModel
{
    protected $table = 'conversation_messages';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'direction',
        'message_type',
        'body',
        'media_url',
        'provider_message_id',
        'intent',
        'is_injection_attempt',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'message_type'        => MessageType::class,
            'metadata'            => 'array',
            'is_injection_attempt' => 'boolean',
            'created_at'          => 'datetime',
            'updated_at'          => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', 'inbound');
    }

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', 'outbound');
    }
}
