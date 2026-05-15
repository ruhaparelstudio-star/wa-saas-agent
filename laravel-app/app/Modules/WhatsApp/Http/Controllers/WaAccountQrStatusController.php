<?php

namespace App\Modules\WhatsApp\Http\Controllers;

use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WaAccountQrStatusController extends Controller
{
    public function __construct(
        private readonly WaAccountRepository $repository,
    ) {}

    public function show(Request $request, string $id): JsonResponse
    {
        $account = $this->repository->findById($id);

        if ($account === null) {
            return response()->json(['error' => 'Not found'], 404);
        }

        // Tenant isolation — authenticated user must own this account
        if ($account->tenant_id !== $request->user()->tenant_id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json([
            'status'     => $account->status->value,
            'qr_code'    => $account->status->value === 'qr_pending' ? $account->qr_code : null,
            'phone'      => $account->phone_number,
            'is_expired' => $account->isQrExpired(),
        ]);
    }
}
