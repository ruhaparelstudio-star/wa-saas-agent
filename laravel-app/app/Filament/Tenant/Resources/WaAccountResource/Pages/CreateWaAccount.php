<?php

namespace App\Filament\Tenant\Resources\WaAccountResource\Pages;

use App\Filament\Tenant\Resources\WaAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWaAccount extends CreateRecord
{
    protected static string $resource = WaAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()->tenant_id;
        $data['status']    = \App\Modules\Shared\Enums\WaAccountStatus::DISCONNECTED;
        $data['metadata']  = [];
        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
