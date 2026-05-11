<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Auth\Models\User;
use App\Modules\Shared\Enums\TenantStatus;
use App\Modules\Shared\Enums\UserRole;
use App\Modules\Tenancy\Mail\ActivationEmail;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class TenantService
{
    public function __construct(
        private readonly ActivationService $activationService
    ) {}

    public function create(array $data, User $createdBy): Tenant
    {
        return DB::transaction(function () use ($data, $createdBy) {
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['slug'] ?? Str::slug($data['name']),
                'status' => TenantStatus::TRIAL,
                'industry' => $data['industry'] ?? 'wedding',
                'contact_email' => $data['contact_email'],
                'contact_phone' => $data['contact_phone'] ?? null,
                'created_by_id' => $createdBy->id,
            ]);

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['contact_email'],
                'password' => Str::random(32),
                'role' => UserRole::TENANT_ADMIN,
                'is_active' => true,
                'tenant_id' => $tenant->id,
            ]);

            TenantUser::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role' => UserRole::TENANT_ADMIN->value,
                'is_primary' => true,
            ]);

            $activationToken = $this->activationService->generateToken($tenant);

            Mail::to($tenant->contact_email)->send(
                new ActivationEmail($tenant, $activationToken->raw_token)
            );

            return $tenant;
        });
    }

    public function updateStatus(Tenant $tenant, TenantStatus $status): Tenant
    {
        $tenant->status = $status;
        $tenant->save();

        return $tenant;
    }

    public function resendActivation(Tenant $tenant): \App\Modules\Tenancy\Models\ActivationToken
    {
        return $this->activationService->resendActivation($tenant);
    }
}
