<?php

namespace App\Modules\WhatsApp\Adapters;

use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\Enums\WaAccountStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppGatewayAdapter implements ChannelGatewayInterface
{
    private string $baseUrl;
    private string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.wa_gateway.url', env('WA_GATEWAY_URL', 'http://wa-gateway:3001')), '/');
        $this->secret  = config('services.wa_gateway.secret', env('WA_INTERNAL_SECRET', ''));
    }

    public function sendText(string $accountId, string $toPhone, string $body): bool
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Internal-Secret' => $this->secret])
                ->post("{$this->baseUrl}/dispatch", [
                    'wa_account_id' => $accountId,
                    'to_phone'      => $toPhone,
                    'message_type'  => 'text',
                    'body'          => $body,
                ]);

            return $response->successful() && ($response->json('success') === true);
        } catch (\Throwable $e) {
            Log::warning('WhatsAppGatewayAdapter: sendText failed.', [
                'error'      => $e->getMessage(),
                'account_id' => $accountId,
            ]);
            return false;
        }
    }

    public function sendFile(string $accountId, string $toPhone, string $fileUrl, string $caption = ''): bool
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Internal-Secret' => $this->secret])
                ->post("{$this->baseUrl}/dispatch", [
                    'wa_account_id' => $accountId,
                    'to_phone'      => $toPhone,
                    'message_type'  => 'document',
                    'media_url'     => $fileUrl,
                    'body'          => $caption,
                    'caption'       => $caption,
                ]);

            return $response->successful() && ($response->json('success') === true);
        } catch (\Throwable $e) {
            Log::warning('WhatsAppGatewayAdapter: sendFile failed.', [
                'error'      => $e->getMessage(),
                'account_id' => $accountId,
            ]);
            return false;
        }
    }

    public function startSession(string $accountId, string $callbackUrl): bool
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Internal-Secret' => $this->secret])
                ->post("{$this->baseUrl}/sessions/start", [
                    'wa_account_id'   => $accountId,
                    'callback_url'    => $callbackUrl,
                    'internal_secret' => $this->secret,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('WhatsAppGatewayAdapter: startSession failed.', [
                'error'      => $e->getMessage(),
                'account_id' => $accountId,
            ]);
            return false;
        }
    }

    public function stopSession(string $accountId): bool
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders(['X-Internal-Secret' => $this->secret])
                ->post("{$this->baseUrl}/sessions/stop", [
                    'wa_account_id' => $accountId,
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('WhatsAppGatewayAdapter: stopSession failed.', [
                'error'      => $e->getMessage(),
                'account_id' => $accountId,
            ]);
            return false;
        }
    }

    public function getStatus(string $accountId): WaAccountStatus
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders(['X-Internal-Secret' => $this->secret])
                ->get("{$this->baseUrl}/status/{$accountId}");

            if ($response->successful()) {
                $status = $response->json('status', 'disconnected');
                return WaAccountStatus::tryFrom($status) ?? WaAccountStatus::DISCONNECTED;
            }
        } catch (\Throwable $e) {
            Log::warning('WhatsAppGatewayAdapter: getStatus failed.', [
                'error'      => $e->getMessage(),
                'account_id' => $accountId,
            ]);
        }

        return WaAccountStatus::DISCONNECTED;
    }
}
