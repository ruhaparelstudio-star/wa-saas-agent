<?php

namespace App\Filament\Superadmin\Resources\TenantResource\Pages;

use App\Filament\Superadmin\Resources\TenantResource;
use App\Modules\Tenancy\Services\TenantService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(TenantService::class)->create($data, auth()->user());
    }
}
