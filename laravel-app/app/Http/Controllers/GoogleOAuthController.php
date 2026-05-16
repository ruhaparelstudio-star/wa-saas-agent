<?php

namespace App\Http\Controllers;

use App\Modules\Calendar\Services\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoogleOAuthController extends Controller
{
    public function __construct(
        private readonly GoogleOAuthService $oauthService,
    ) {}

    public function redirect(): RedirectResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $url      = $this->oauthService->getAuthorizationUrl($tenantId);

        return redirect()->away($url);
    }

    public function callback(Request $request): RedirectResponse
    {
        $code  = $request->input('code');
        $state = $request->input('state');

        if (! $code || ! $state) {
            return redirect('/app/calendar-settings')
                ->with('error', 'Google OAuth gagal: parameter tidak lengkap.');
        }

        $decodedTenantId = base64_decode($state);
        $authUser        = auth()->user();

        if (! $authUser || $authUser->tenant_id !== $decodedTenantId) {
            abort(400, 'Invalid state parameter.');
        }

        $result = $this->oauthService->exchangeCode($code, $state);

        if (! $result) {
            return redirect('/app/calendar-settings')
                ->with('error', 'Google OAuth gagal: tidak bisa menukar kode.');
        }

        return redirect('/app/calendar-settings')
            ->with('success', 'Google Calendar berhasil terhubung.');
    }
}
