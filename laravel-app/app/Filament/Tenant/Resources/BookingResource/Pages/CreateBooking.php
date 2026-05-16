<?php

namespace App\Filament\Tenant\Resources\BookingResource\Pages;

use App\Filament\Tenant\Resources\BookingResource;
use App\Modules\Shared\Enums\BookingStatus;
use Filament\Resources\Pages\CreateRecord;

class CreateBooking extends CreateRecord
{
    protected static string $resource = BookingResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['tenant_id'] = auth()->user()->tenant_id;
        $data['status']    = BookingStatus::DRAFT->value;
        return $data;
    }
}
