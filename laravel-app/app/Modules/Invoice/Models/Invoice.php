<?php

namespace App\Modules\Invoice\Models;

use App\Modules\Booking\Models\Booking;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\Shared\Models\TenantBaseModel;
use App\Modules\Tenancy\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends TenantBaseModel
{
    protected $table = 'invoices';

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'invoice_number',
        'type',
        'status',
        'amount',
        'due_date',
        'notes',
        'sent_count',
        'sent_at',
        'paid_at',
        'payment_proof_url',
        'metadata',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status'    => InvoiceStatus::class,
            'type'      => InvoiceType::class,
            'due_date'  => 'date',
            'amount'    => 'integer',
            'sent_count'=> 'integer',
            'metadata'  => 'array',
            'sent_at'   => 'datetime',
            'paid_at'   => 'datetime',
        ]);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class, 'booking_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::PAID;
    }

    public function isOverdue(): bool
    {
        return !$this->isPaid()
            && $this->due_date !== null
            && Carbon::now()->startOfDay()->gt($this->due_date);
    }

    public function markSent(): void
    {
        $this->update([
            'sent_count' => $this->sent_count + 1,
            'sent_at'    => now(),
            'status'     => InvoiceStatus::SENT,
        ]);
    }

    public function markPaid(string $proofUrl = ''): void
    {
        $data = [
            'status'  => InvoiceStatus::PAID,
            'paid_at' => now(),
        ];
        if ($proofUrl !== '') {
            $data['payment_proof_url'] = $proofUrl;
        }
        $this->update($data);
    }

    public function canResend(int $maxResend): bool
    {
        return $this->sent_count < $maxResend;
    }

    public static function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . Carbon::now()->format('Ym') . '-';

        $last = static::withoutGlobalScopes()
            ->where('invoice_number', 'like', $prefix . '%')
            ->orderByDesc('invoice_number')
            ->lockForUpdate()
            ->first();

        $sequence = 1;
        if ($last) {
            $parts = explode('-', $last->invoice_number);
            $sequence = (int) end($parts) + 1;
        }

        return $prefix . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }
}
