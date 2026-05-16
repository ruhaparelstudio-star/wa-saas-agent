<?php

namespace App\Modules\Calendar\Services;

use App\Modules\Notification\Services\NotificationService;
use App\Modules\TenantConfig\Models\TenantSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleOAuthService
{
    private const AUTH_URL    = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
    private const REVOKE_URL  = 'https://oauth2.googleapis.com/revoke';
    private const EXPIRE_BUFFER_SECONDS = 300; // refresh if < 5 minutes remain

    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function getAuthorizationUrl(string $tenantId): string
    {
        $config = config('services.google_oauth');
        $state  = base64_encode($tenantId);
        $scopes = implode(' ', $config['scopes']);

        return self::AUTH_URL . '?' . http_build_query([
            'client_id'     => $config['client_id'],
            'redirect_uri'  => $config['redirect_uri'],
            'response_type' => 'code',
            'scope'         => $scopes,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    public function exchangeCode(string $code, string $state): ?array
    {
        $tenantId = base64_decode($state);
        $config   = config('services.google_oauth');

        $response = Http::post(self::TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri'  => $config['redirect_uri'],
            'grant_type'    => 'authorization_code',
        ]);

        if (! $response->successful()) {
            Log::error('GoogleOAuth exchangeCode failed', ['body' => $response->body()]);
            return null;
        }

        $data      = $response->json();
        $expiresAt = Carbon::now()->addSeconds($data['expires_in'] ?? 3600)->toIso8601String();

        $tokenData = [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at'    => $expiresAt,
        ];

        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenantId],
            ['google_oauth_token' => json_encode($tokenData)]
        );

        return $tokenData;
    }

    public function refreshAccessToken(string $tenantId): ?string
    {
        $config       = config('services.google_oauth');
        $tokenData    = $this->loadTokenData($tenantId);
        $refreshToken = $tokenData['refresh_token'] ?? null;

        if (! $refreshToken) {
            $this->emitCalendarError($tenantId, 'refreshAccessToken', 'No refresh_token stored.');
            return null;
        }

        $response = Http::post(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'grant_type'    => 'refresh_token',
        ]);

        if (! $response->successful()) {
            $this->emitCalendarError($tenantId, 'refreshAccessToken', $response->body());
            return null;
        }

        $data      = $response->json();
        $expiresAt = Carbon::now()->addSeconds($data['expires_in'] ?? 3600)->toIso8601String();

        $tokenData['access_token'] = $data['access_token'];
        $tokenData['expires_at']   = $expiresAt;

        TenantSetting::where('tenant_id', $tenantId)->update([
            'google_oauth_token' => json_encode($tokenData),
        ]);

        return $data['access_token'];
    }

    public function getValidToken(string $tenantId): ?string
    {
        $tokenData = $this->loadTokenData($tenantId);

        if (empty($tokenData['access_token'])) {
            return null;
        }

        if (! empty($tokenData['expires_at'])) {
            $expiresAt = Carbon::parse($tokenData['expires_at']);
            if ($expiresAt->subSeconds(self::EXPIRE_BUFFER_SECONDS)->isPast()) {
                return $this->refreshAccessToken($tenantId);
            }
        }

        return $tokenData['access_token'];
    }

    public function revokeToken(string $tenantId): void
    {
        $tokenData   = $this->loadTokenData($tenantId);
        $accessToken = $tokenData['access_token'] ?? null;

        if ($accessToken) {
            Http::post(self::REVOKE_URL, ['token' => $accessToken]);
        }

        TenantSetting::where('tenant_id', $tenantId)->update([
            'google_oauth_token' => null,
        ]);
    }

    private function loadTokenData(string $tenantId): array
    {
        $setting = TenantSetting::where('tenant_id', $tenantId)->first();
        $raw     = $setting?->google_oauth_token;

        if (! $raw) {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function emitCalendarError(string $tenantId, string $method, string $error): void
    {
        Log::error("GoogleOAuthService::{$method} failed.", [
            'tenant_id' => $tenantId,
            'error'     => $error,
        ]);
        $this->notificationService->notifyCalendarError($tenantId, $method, $error);
    }
}
