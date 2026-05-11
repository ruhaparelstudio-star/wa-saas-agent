<?php

namespace App\Modules\Shared\Enums;

enum WaAccountStatus: string
{
    case DISCONNECTED = 'disconnected';
    case QR_PENDING = 'qr_pending';
    case CONNECTING = 'connecting';
    case CONNECTED = 'connected';
    case RECONNECTING = 'reconnecting';
    case FAILED = 'failed';
    case BANNED_OR_RESTRICTED = 'banned_or_restricted';

    public function label(): string
    {
        return match($this) {
            self::DISCONNECTED => 'Disconnected',
            self::QR_PENDING => 'QR Pending',
            self::CONNECTING => 'Connecting',
            self::CONNECTED => 'Connected',
            self::RECONNECTING => 'Reconnecting',
            self::FAILED => 'Failed',
            self::BANNED_OR_RESTRICTED => 'Banned or Restricted',
        };
    }
}
