<?php

namespace App\Modules\FollowUp\Models;

use App\Modules\Booking\Models\Booking;
use App\Modules\Conversation\Models\Conversation;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Shared\Enums\FollowUpReason;
use App\Modules\Shared\Models\TenantBaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpLog extends TenantBaseModel
{
    protected $table = 'follow_up_logs';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'booking_id',
        'invoice_id',
        'reason',
        'sent_at',
        'message_body',
        'delivered',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'reason'    => FollowUpReason::class,
            'sent_at'   => 'datetime',
            'delivered' => 'boolean',
            'metadata'  => 'array',
        ]);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
