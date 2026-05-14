<?php

namespace App\Filament\Tenant\Resources\PackageResource\Pages;

use App\Filament\Tenant\Resources\PackageResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePackage extends CreateRecord
{
    protected static string $resource = PackageResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()->tenant_id;
        return $data;
    }
}
