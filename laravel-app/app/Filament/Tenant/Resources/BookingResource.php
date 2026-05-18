<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\BookingResource\Pages;
use App\Modules\Booking\Models\Booking;
use App\Modules\Booking\Services\BookingService;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Knowledge\Models\Package;
use App\Modules\Shared\Enums\BookingStatus;
use App\Modules\Shared\Enums\InvoiceType;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BookingResource extends Resource
{
    protected static ?string $model = Booking::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar';

    protected static ?string $navigationLabel = 'Booking';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Bookings';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->tenant_id);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Informasi Event')
                ->columns(2)
                ->schema([
                    DatePicker::make('event_date')
                        ->label('Tanggal Event')
                        ->required()
                        ->columnSpan(2),
                    TimePicker::make('event_time_start')
                        ->label('Waktu Mulai')
                        ->seconds(false),
                    TimePicker::make('event_time_end')
                        ->label('Waktu Selesai')
                        ->seconds(false),
                    Select::make('event_type')
                        ->label('Tipe Event')
                        ->options([
                            'akad'     => 'Akad',
                            'resepsi'  => 'Resepsi',
                            'keduanya' => 'Akad + Resepsi',
                        ])
                        ->native(false),
                    TextInput::make('location')
                        ->label('Lokasi / Venue')
                        ->maxLength(255),
                    TextInput::make('guest_count')
                        ->label('Jumlah Tamu (perkiraan)')
                        ->numeric()
                        ->suffix('orang')
                        ->minValue(1),
                ]),

            Section::make('Informasi Customer')
                ->columns(2)
                ->schema([
                    TextInput::make('customer_name')
                        ->label('Nama Customer')
                        ->maxLength(255),
                    TextInput::make('customer_phone')
                        ->label('Nomor WhatsApp')
                        ->tel()
                        ->prefix('+62')
                        ->maxLength(20),
                    Select::make('package_id')
                        ->label('Paket yang Dipilih')
                        ->options(fn () => Package::withoutGlobalScopes()
                            ->where('tenant_id', auth()->user()->tenant_id)
                            ->where('is_active', true)
                            ->pluck('name', 'id')
                        )
                        ->searchable()
                        ->native(false)
                        ->placeholder('Pilih paket...')
                        ->columnSpanFull(),
                ]),

            Section::make('Informasi Keuangan')
                ->columns(2)
                ->schema([
                    TextInput::make('total_amount')
                        ->label('Total Harga')
                        ->numeric()
                        ->prefix('Rp')
                        ->helperText('Total nilai kontrak booking ini.'),
                    TextInput::make('dp_amount')
                        ->label('Uang Muka / DP')
                        ->numeric()
                        ->prefix('Rp')
                        ->helperText('Jumlah down payment yang disepakati.'),
                ]),

            Section::make('Catatan')
                ->schema([
                    Textarea::make('notes')
                        ->label('Catatan Internal')
                        ->rows(3)
                        ->placeholder('Catatan khusus untuk tim internal...')
                        ->helperText('Tidak ditampilkan ke customer.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_code')
                    ->label('Kode Booking')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('customer_name')
                    ->label('Customer')
                    ->formatStateUsing(fn (?string $state, Booking $record): string => $state ?: '-')
                    ->searchable(),
                Tables\Columns\TextColumn::make('event_date')
                    ->label('Tanggal Event')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('event_time_start')
                    ->label('Jam')
                    ->formatStateUsing(function ($state, Booking $record): string {
                        if (empty($state) || $state === '00:00:00') {
                            return '-';
                        }
                        $start = substr((string) $state, 0, 5);
                        $end   = $record->event_time_end ? substr((string) $record->event_time_end, 0, 5) : null;
                        return $end ? "{$start} – {$end}" : $start;
                    })
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('package.name')
                    ->label('Paket')
                    ->placeholder('-')
                    ->toggleable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('event_type')
                    ->label('Tipe')
                    ->badge()
                    ->color('gray')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (BookingStatus $state): string => match ($state) {
                        BookingStatus::DRAFT       => 'gray',
                        BookingStatus::CONFIRMED   => 'success',
                        BookingStatus::AWAITING_DP => 'warning',
                        BookingStatus::PAID        => 'success',
                        BookingStatus::COMPLETED   => 'info',
                        BookingStatus::CANCELLED   => 'danger',
                        BookingStatus::EXPIRED     => 'danger',
                    })
                    ->formatStateUsing(fn (BookingStatus $state) => $state->label()),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->formatStateUsing(fn (?int $state): string => $state
                        ? 'Rp ' . number_format($state, 0, ',', '.')
                        : '-'
                    ),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(BookingStatus::cases())
                        ->mapWithKeys(fn ($s) => [$s->value => $s->label()])
                    )
                    ->multiple(),
                Filter::make('event_date')
                    ->form([
                        DatePicker::make('from')->label('Dari Tanggal'),
                        DatePicker::make('until')->label('Sampai Tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $v) => $q->where('event_date', '>=', $v))
                            ->when($data['until'], fn ($q, $v) => $q->where('event_date', '<=', $v));
                    }),
            ])
            ->actions([
                Action::make('confirm')
                    ->label('Konfirmasi')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Booking $record): bool => $record->status === BookingStatus::DRAFT)
                    ->requiresConfirmation()
                    ->action(function (Booking $record): void {
                        try {
                            app(BookingService::class)->confirm($record);
                            Notification::make()->title('Booking dikonfirmasi.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('Gagal: ' . $e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('cancel')
                    ->label('Batalkan')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Booking $record): bool => $record->isActive()
                        && !in_array($record->status, [
                            BookingStatus::PAID,
                            BookingStatus::AWAITING_DP,
                        ], true)
                    )
                    ->requiresConfirmation()
                    ->form([
                        Textarea::make('reason')
                            ->label('Alasan Pembatalan')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (Booking $record, array $data): void {
                        app(BookingService::class)->cancel($record, $data['reason'] ?? '');
                        Notification::make()->title('Booking dibatalkan.')->warning()->send();
                    }),
                Action::make('reschedule')
                    ->label('Reschedule')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (Booking $record): bool => $record->isActive())
                    ->form([
                        DatePicker::make('new_date')
                            ->label('Tanggal Baru')
                            ->required()
                            ->minDate(now()),
                        TimePicker::make('new_time_start')
                            ->label('Waktu Mulai Baru')
                            ->seconds(false),
                    ])
                    ->action(function (Booking $record, array $data): void {
                        $newDate = Carbon::parse($data['new_date']);
                        $success = app(BookingService::class)->rescheduleBooking(
                            $record,
                            $newDate,
                            $data['new_time_start'] ?? null
                        );
                        if ($success) {
                            Notification::make()->title('Booking dijadwal ulang.')->success()->send();
                        } else {
                            Notification::make()->title('Tanggal sudah terisi, pilih tanggal lain.')->danger()->send();
                        }
                    }),
                Action::make('send_invoice')
                    ->label('Kirim Invoice')
                    ->icon('heroicon-o-document-text')
                    ->color('primary')
                    ->visible(fn (Booking $record): bool => in_array($record->status, [
                        BookingStatus::CONFIRMED,
                        BookingStatus::AWAITING_DP,
                    ]))
                    ->form([
                        Select::make('type')
                            ->label('Tipe Invoice')
                            ->options([
                                InvoiceType::DP->value        => InvoiceType::DP->label(),
                                InvoiceType::PELUNASAN->value => InvoiceType::PELUNASAN->label(),
                            ])
                            ->required()
                            ->native(false),
                        TextInput::make('amount')
                            ->label('Nominal (IDR)')
                            ->numeric()
                            ->prefix('Rp')
                            ->required(),
                        DatePicker::make('due_date')
                            ->label('Jatuh Tempo')
                            ->required()
                            ->minDate(now()),
                    ])
                    ->action(function (Booking $record, array $data): void {
                        $invoiceService = app(InvoiceService::class);
                        $invoice = $invoiceService->issue(
                            $record,
                            InvoiceType::from($data['type']),
                            (int) $data['amount'],
                            Carbon::parse($data['due_date'])
                        );
                        $sent = $invoiceService->send($invoice);
                        $msg  = $sent ? 'Invoice dikirim via WA.' : 'Invoice dibuat (gagal kirim WA, cek WA account).';
                        Notification::make()->title($msg)->success()->send();
                    }),
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBookings::route('/'),
            'create' => Pages\CreateBooking::route('/create'),
            'edit'   => Pages\EditBooking::route('/{record}/edit'),
        ];
    }
}
