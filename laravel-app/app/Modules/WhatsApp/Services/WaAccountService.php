<?php

namespace App\Modules\WhatsApp\Services;

use App\Modules\Shared\DTOs\WaAccountStatusDTO;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\WhatsApp\Adapters\WhatsAppGatewayAdapter;
use App\Modules\WhatsApp\Models\WaAccount;
use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use Illuminate\Support\Facades\Log;

class WaAccountService
{
    public function __construct(
        private readonly WaAccountRepository $repository,
        private readonly WhatsAppGatewayAdapter $gateway,
    ) {}

    public function initiateConnect(WaAccount $account): bool
    {
        $callbackUrl = route('wa.session.callback', ['account_id' => $account->id]);

        $success = $this->gateway->startSession(
            accountId: $account->id,
            callbackUrl: $callbackUrl,
        );

        if ($success) {
            $account->update(['status' => WaAccountStatus::CONNECTING]);
        }

        return $success;
    }

    public function disconnect(WaAccount $account): bool
    {
        $success = $this->gateway->stopSession($account->id);
        $account->markDisconnected();

        return $success;
    }

    public function handleSessionCallback(string $accountId, array $payload): void
    {
        $account = $this->repository->findById($accountId);

        if ($account === null) {
            Log::warning('WaAccountService: callback received for unknown account.', [
                'account_id' => $accountId,
            ]);
            return;
        }

        $event = $payload['event'] ?? '';

        match ($event) {
            'qr'           => $account->markQrPending($payload['qr_base64'] ?? ''),
            'connected'    => $account->markConnected($payload['phone'] ?? ''),
            'disconnected' => $account->markDisconnected(),
            'failed'       => $account->markFailed(),
            default        => Log::warning('WaAccountService: unknown callback event.', [
                'event'      => $event,
                'account_id' => $accountId,
            ]),
        };

        Log::info('WaAccountService: session callback handled.', [
            'account_id' => $accountId,
            'event'      => $event,
        ]);
    }

    public function getGatewayStatus(WaAccount $account): WaAccountStatusDTO
    {
        $status = $this->gateway->getStatus($account->id);

        return new WaAccountStatusDTO(
            id: $account->id,
            tenant_id: $account->tenant_id,
            phone_number: $account->phone_number,
            display_name: $account->display_name,
            status: $status->value,
            connected_at: $account->connected_at?->toIso8601String(),
            is_qr_expired: $account->isQrExpired(),
        );
    }
}
