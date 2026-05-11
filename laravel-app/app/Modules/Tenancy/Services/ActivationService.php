<?php

namespace App\Modules\Tenancy\Services;

use App\Modules\Tenancy\Mail\ActivationEmail;
use App\Modules\Tenancy\Models\ActivationToken;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Auth\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class ActivationService
{
    public function generateToken(Tenant $tenant): ActivationToken
    {
        $rawToken = Str::random(64);
        $hashed = hash('sha256', $rawToken);

        $token = ActivationToken::create([
            'tenant_id' => $tenant->id,
            'token' => $hashed,
            'expires_at' => now()->addHours(48),
            'used_at' => null,
        ]);

        // Set raw token as transient property (not persisted) for use in email
        $token->raw_token = $rawToken;

        return $token;
    }

    public function validateToken(string $rawToken): ?ActivationToken
    {
        $hashed = hash('sha256', $rawToken);
        $token = ActivationToken::where('token', $hashed)->first();

        if (!$token || !$token->isValid()) {
            return null;
        }

        return $token;
    }

    public function activate(string $rawToken, string $password): User
    {
        $token = $this->validateToken($rawToken);

        if (!$token) {
            throw new RuntimeException('Token tidak valid atau sudah kedaluwarsa.');
        }

        $tenant = $token->tenant;
        $tenantUser = $tenant->tenantUsers()->where('is_primary', true)->first();

        if (!$tenantUser) {
            throw new RuntimeException('Tenant admin tidak ditemukan.');
        }

        $user = $tenantUser->user;
        $user->password = $password;
        $user->save();

        $token->used_at = now();
        $token->save();

        $tenant->status = \App\Modules\Shared\Enums\TenantStatus::ACTIVE;
        $tenant->save();

        return $user;
    }

    public function resendActivation(Tenant $tenant): ActivationToken
    {
        // Invalidate all existing unused tokens for this tenant
        ActivationToken::where('tenant_id', $tenant->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $token = $this->generateToken($tenant);

        Mail::to($tenant->contact_email)->send(new ActivationEmail($tenant, $token->raw_token));

        return $token;
    }
}
