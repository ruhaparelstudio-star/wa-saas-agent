<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Http\Requests\ActivateRequest;
use App\Modules\Tenancy\Services\ActivationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use RuntimeException;

class ActivationController extends Controller
{
    public function __construct(
        private readonly ActivationService $activationService,
    ) {}

    public function show(string $token): JsonResponse
    {
        $activationToken = $this->activationService->validateToken($token);

        if (!$activationToken) {
            return response()->json(['message' => 'Token tidak valid atau sudah kedaluwarsa.'], 422);
        }

        return response()->json([
            'message' => 'Token valid. Silakan set password Anda.',
            'tenant' => $activationToken->tenant->name,
            'expires_at' => $activationToken->expires_at->toIso8601String(),
        ]);
    }

    public function activate(ActivateRequest $request, string $token): JsonResponse
    {
        try {
            $user = $this->activationService->activate($token, $request->password);

            return response()->json([
                'message' => 'Akun berhasil diaktifkan. Silakan login.',
                'email' => $user->email,
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
