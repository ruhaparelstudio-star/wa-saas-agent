<?php

namespace App\Modules\Booking\Models;

use App\Modules\Conversation\Models\Conversation;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Lead\Models\Lead;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Models\TenantBaseModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Booking extends TenantBaseModel
{
    protected $table = 'bookings';

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'lead_id',
        'package_id',
        'booking_code',
        'status',
        'event_date',
        'event_time_start',
        'event_time_end',
        'event_type',
        'location',
        'guest_count',
        'customer_name',
        'customer_phone',
        'total_amount',
        'dp_amount',
        'notes',
        'metadata',
        'calendar_event_id',
        'confirmed_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status'       => BookingStatus::class,
            'event_date'   => 'date',
            'metadata'     => 'array',
            'total_amount' => 'integer',
            'dp_amount'    => 'integer',
            'guest_count'  => 'integer',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ]);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'package_id');
    }

    public function invoices(): HasMany
    {
        // Will be resolved once Invoice model is created in 5.4
        return $this->hasMany(\App\Modules\Invoice\Models\Invoice::class, 'booking_id');
    }

    public function isActive(): bool
    {
        return !in_array($this->status, [
            BookingStatus::CANCELLED,
            BookingStatus::EXPIRED,
            BookingStatus::COMPLETED,
        ]);
    }

    public function markConfirmed(): void
    {
        $this->update([
            'status'       => BookingStatus::CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function markCancelled(string $reason = ''): void
    {
        $this->update([
            'status'       => BookingStatus::CANCELLED,
            'cancelled_at' => now(),
            'notes'        => $reason ?: $this->notes,
        ]);
    }

    public static function generateBookingCode(string $tenantId): string
    {
        $prefix = 'BKG-' . Carbon::now()->format('Ym') . '-';

        // Global sequence (not per-tenant) because booking_code is globally UNIQUE
        $last = static::withoutGlobalScopes()
            ->where('booking_code', 'like', $prefix . '%')
            ->orderByDesc('booking_code')
            ->lockForUpdate()
            ->first();

        $sequence = 1;
        if ($last) {
            $parts = explode('-', $last->booking_code);
            $sequence = (int) end($parts) + 1;
        }

        return $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}
