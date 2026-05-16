<?php

namespace App\Filament\Tenant\Resources\BookingResource\Pages;

use App\Filament\Tenant\Resources\BookingResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('export_csv')
                ->label('Export CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->url(route('export.bookings'))
                ->openUrlInNewTab(),
        ];
    }
}
