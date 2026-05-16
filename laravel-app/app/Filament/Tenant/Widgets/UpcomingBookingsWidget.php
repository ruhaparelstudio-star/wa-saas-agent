<?php

namespace App\Filament\Tenant\Widgets;

use App\Modules\Booking\Models\Booking;
use App\Modules\Shared\Enums\BookingStatus;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;

class UpcomingBookingsWidget extends BaseWidget
{
    protected static ?string $heading = 'Booking Mendatang (14 Hari)';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        $tenantId = auth()->user()->tenant_id;

        return $table
            ->query(
                Booking::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('event_date', '>=', now()->toDateString())
                    ->where('event_date', '<=', now()->addDays(14)->toDateString())
                    ->whereNotIn('status', [
                        BookingStatus::CANCELLED->value,
                        BookingStatus::EXPIRED->value,
                    ])
                    ->orderBy('event_date')
            )
            ->columns([
                TextColumn::make('booking_code')
                    ->label('Kode')
                    ->searchable(),
                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->placeholder('-'),
                TextColumn::make('event_date')
                    ->label('Tanggal Event')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label('Tipe')
                    ->placeholder('-'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (BookingStatus $state): string => match ($state) {
                        BookingStatus::DRAFT       => 'gray',
                        BookingStatus::CONFIRMED   => 'success',
                        BookingStatus::AWAITING_DP => 'warning',
                        BookingStatus::PAID        => 'success',
                        BookingStatus::COMPLETED   => 'info',
                        default                    => 'danger',
                    })
                    ->formatStateUsing(fn (BookingStatus $state) => $state->label()),
            ])
            ->paginated(false);
    }
}
