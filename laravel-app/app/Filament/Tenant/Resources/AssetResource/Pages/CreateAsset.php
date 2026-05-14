<?php

namespace App\Filament\Tenant\Resources\AssetResource\Pages;

use App\Filament\Tenant\Resources\AssetResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()->tenant_id;
        return $data;
    }
}
