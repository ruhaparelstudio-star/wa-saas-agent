<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Tenancy\Http\Requests\CreateTenantRequest;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\ActivationService;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SuperadminTenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenantService,
        private readonly ActivationService $activationService,
    ) {}

    public function index(): JsonResponse
    {
        $tenants = Tenant::with('createdBy')->latest()->get();

        return response()->json(['data' => $tenants]);
    }

    public function store(CreateTenantRequest $request): JsonResponse
    {
        $tenant = $this->tenantService->create(
            $request->validated(),
            $request->user()
        );

        return response()->json(['data' => $tenant->load('tenantUsers.user')], 201);
    }

    public function show(string $id): JsonResponse
    {
        $tenant = Tenant::with(['tenantUsers.user', 'createdBy'])->findOrFail($id);

        return response()->json(['data' => $tenant]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', array_column(TenantStatus::cases(), 'value'))],
        ]);

        $tenant = Tenant::findOrFail($id);
        $tenant = $this->tenantService->updateStatus($tenant, TenantStatus::from($request->status));

        return response()->json(['data' => $tenant]);
    }

    public function resendActivation(string $id): JsonResponse
    {
        $tenant = Tenant::findOrFail($id);
        $this->activationService->resendActivation($tenant);

        return response()->json(['message' => 'Email aktivasi berhasil dikirim ulang.']);
    }
}
