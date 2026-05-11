<?php

namespace App\Modules\Shared\Contracts;

use App\Modules\Shared\Enums\WaAccountStatus;

interface ChannelGatewayInterface
{
    /** Send a text message to a phone number. */
    public function sendText(string $accountId, string $toPhone, string $body): bool;

    /** Send a file with optional caption. */
    public function sendFile(string $accountId, string $toPhone, string $fileUrl, string $caption = ''): bool;

    /** Get the current connection status of a WA account. */
    public function getStatus(string $accountId): WaAccountStatus;
}
