<?php

namespace App\Modules\WhatsApp\Http\Controllers;

use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use App\Modules\WhatsApp\Services\WaAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WaSessionCallbackController extends Controller
{
    public function __construct(
        private readonly WaAccountService $service,
        private readonly WaAccountRepository $repository,
    ) {}

    public function handle(Request $request, string $account_id): JsonResponse
    {
        $validated = $request->validate([
            'event'     => 'required|string|in:qr,connected,disconnected,failed',
            'qr_base64' => 'nullable|string',
            'phone'     => 'nullable|string',
        ]);

        $account = $this->repository->findById($account_id);

        if ($account === null) {
            return response()->json(['error' => 'Account not found'], 404);
        }

        $this->service->handleSessionCallback($account_id, $validated);

        return response()->json(['status' => 'ok'], 200);
    }
}
