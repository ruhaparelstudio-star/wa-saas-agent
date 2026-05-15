<?php

namespace App\Filament\Tenant\Resources\WaAccountResource\Pages;

use App\Filament\Tenant\Resources\WaAccountResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWaAccounts extends ListRecords
{
    protected static string $resource = WaAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Tambah Akun WA'),
        ];
    }
}
