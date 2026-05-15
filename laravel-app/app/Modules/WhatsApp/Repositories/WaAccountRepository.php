<?php

namespace App\Modules\WhatsApp\Repositories;

use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\WhatsApp\Models\WaAccount;
use Illuminate\Database\Eloquent\Collection;

class WaAccountRepository
{
    public function findById(string $id): ?WaAccount
    {
        return WaAccount::find($id);
    }

    public function findByTenant(string $tenantId): Collection
    {
        return WaAccount::where('tenant_id', $tenantId)->get();
    }

    public function findConnectedByTenant(string $tenantId): Collection
    {
        return WaAccount::where('tenant_id', $tenantId)
            ->where('status', WaAccountStatus::CONNECTED->value)
            ->get();
    }

    public function create(string $tenantId, string $displayName): WaAccount
    {
        return WaAccount::create([
            'tenant_id'    => $tenantId,
            'display_name' => $displayName,
            'status'       => WaAccountStatus::DISCONNECTED,
            'metadata'     => [],
        ]);
    }

    public function updateStatus(string $id, WaAccountStatus $status, array $extra = []): WaAccount
    {
        $account = WaAccount::findOrFail($id);
        $account->update(array_merge(['status' => $status], $extra));
        return $account->fresh();
    }

    public function getActiveForTenant(string $tenantId): ?WaAccount
    {
        return WaAccount::where('tenant_id', $tenantId)
            ->where('status', WaAccountStatus::CONNECTED->value)
            ->first();
    }
}
