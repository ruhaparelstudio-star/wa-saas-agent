<?php

namespace App\Modules\WhatsApp\Models;

use App\Modules\Conversation\Models\Conversation;
use App\Modules\Shared\DTOs\WaAccountStatusDTO;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\Shared\Models\TenantBaseModel;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WaAccount extends TenantBaseModel
{
    protected $table = 'wa_accounts';

    protected $fillable = [
        'tenant_id',
        'phone_number',
        'display_name',
        'status',
        'session_data',
        'qr_code',
        'qr_expires_at',
        'connected_at',
        'last_seen_at',
        'reconnect_attempts',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status'        => WaAccountStatus::class,
            'session_data'  => 'encrypted',
            'metadata'      => 'array',
            'qr_expires_at' => 'datetime',
            'connected_at'  => 'datetime',
            'last_seen_at'  => 'datetime',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'wa_account_id');
    }

    public function isConnected(): bool
    {
        return $this->status === WaAccountStatus::CONNECTED;
    }

    public function isQrPending(): bool
    {
        return $this->status === WaAccountStatus::QR_PENDING;
    }

    public function isQrExpired(): bool
    {
        return $this->qr_expires_at !== null && now()->greaterThan($this->qr_expires_at);
    }

    public function markConnected(string $phone): void
    {
        $this->update([
            'status'              => WaAccountStatus::CONNECTED,
            'phone_number'        => $phone,
            'connected_at'        => now(),
            'reconnect_attempts'  => 0,
        ]);
    }

    public function markDisconnected(): void
    {
        $this->update([
            'status'   => WaAccountStatus::DISCONNECTED,
            'qr_code'  => null,
        ]);
    }

    public function markQrPending(string $qrBase64): void
    {
        $this->update([
            'status'        => WaAccountStatus::QR_PENDING,
            'qr_code'       => $qrBase64,
            'qr_expires_at' => now()->addMinutes(5),
        ]);
    }

    public function markFailed(): void
    {
        $this->update([
            'status'              => WaAccountStatus::FAILED,
            'reconnect_attempts'  => $this->reconnect_attempts + 1,
        ]);
    }

    public function toStatusDTO(): WaAccountStatusDTO
    {
        return new WaAccountStatusDTO(
            id: $this->id,
            tenant_id: $this->tenant_id,
            phone_number: $this->phone_number,
            display_name: $this->display_name,
            status: $this->status->value,
            connected_at: $this->connected_at?->toIso8601String(),
            is_qr_expired: $this->isQrExpired(),
        );
    }
}
