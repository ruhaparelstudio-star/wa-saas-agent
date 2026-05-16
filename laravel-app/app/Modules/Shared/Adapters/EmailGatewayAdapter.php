<?php

namespace App\Modules\Shared\Adapters;

use App\Modules\Shared\Contracts\ChannelGatewayInterface;
use App\Modules\Shared\Enums\WaAccountStatus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class EmailGatewayAdapter implements ChannelGatewayInterface
{
    private string $apiKey;
    private string $fromAddress;
    private string $fromName;

    public function __construct()
    {
        $this->apiKey      = config('services.resend.api_key') ?? env('RESEND_API_KEY', '');
        $this->fromAddress = config('services.resend.from_address') ?? env('RESEND_FROM_ADDRESS', 'noreply@example.com');
        $this->fromName    = config('services.resend.from_name') ?? env('RESEND_FROM_NAME', 'Wedding Vendor');
    }

    public function sendText(string $accountId, string $toAddress, string $body): bool
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post('https://api.resend.com/emails', [
                'from'    => $this->fromName . ' <' . $this->fromAddress . '>',
                'to'      => [$toAddress],
                'subject' => 'Informasi dari ' . $this->fromName,
                'html'    => nl2br(e($body)),
            ]);

            if (!$response->successful()) {
                Log::warning('EmailGatewayAdapter: sendText failed.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('EmailGatewayAdapter: sendText exception.', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function sendFile(string $accountId, string $toAddress, string $fileUrl, string $caption = ''): bool
    {
        $html = '';
        if ($caption) {
            $html .= nl2br(e($caption)) . '<br><br>';
        }
        $html .= '<a href="' . e($fileUrl) . '">Download Invoice</a>';

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post('https://api.resend.com/emails', [
                'from'    => $this->fromName . ' <' . $this->fromAddress . '>',
                'to'      => [$toAddress],
                'subject' => 'Invoice dari ' . $this->fromName,
                'html'    => $html,
            ]);

            if (!$response->successful()) {
                Log::warning('EmailGatewayAdapter: sendFile failed.', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('EmailGatewayAdapter: sendFile exception.', ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getStatus(string $accountId): WaAccountStatus
    {
        return WaAccountStatus::CONNECTED;
    }
}
