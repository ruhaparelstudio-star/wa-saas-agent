<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\InvoiceResource\Pages;
use App\Modules\Invoice\Models\Invoice;
use App\Modules\Invoice\Services\InvoiceService;
use App\Modules\Shared\Enums\InvoiceStatus;
use App\Modules\Shared\Enums\InvoiceType;
use App\Modules\TenantConfig\Services\TenantPolicyService;
use App\Modules\Shared\Enums\PolicyKey;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?string $navigationLabel = 'Invoice';

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return 'Bookings';
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->with('booking');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('invoice_number')
                ->label('Nomor Invoice')
                ->disabled(),
            TextInput::make('amount')
                ->label('Nominal (IDR)')
                ->numeric()
                ->prefix('Rp'),
            DatePicker::make('due_date')
                ->label('Jatuh Tempo'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Nomor Invoice')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('booking.booking_code')
                    ->label('Kode Booking')
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipe')
                    ->formatStateUsing(fn (InvoiceType $state) => $state->label()),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Nominal')
                    ->formatStateUsing(fn (?int $state): string => $state
                        ? 'Rp ' . number_format($state, 0, ',', '.')
                        : '-'
                    ),
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Jatuh Tempo')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (InvoiceStatus $state): string => match ($state) {
                        InvoiceStatus::ISSUED    => 'gray',
                        InvoiceStatus::SENT      => 'info',
                        InvoiceStatus::PAID      => 'success',
                        InvoiceStatus::OVERDUE   => 'danger',
                        InvoiceStatus::CANCELLED => 'danger',
                    })
                    ->formatStateUsing(fn (InvoiceStatus $state) => $state->label()),
                Tables\Columns\TextColumn::make('sent_count')
                    ->label('Jml Kirim')
                    ->alignCenter(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(InvoiceStatus::cases())
                        ->mapWithKeys(fn ($s) => [$s->value => $s->label()])
                    )
                    ->multiple(),
                Filter::make('due_date')
                    ->form([
                        DatePicker::make('from')->label('Jatuh Tempo Dari'),
                        DatePicker::make('until')->label('Jatuh Tempo Sampai'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q, $v) => $q->where('due_date', '>=', $v))
                            ->when($data['until'], fn ($q, $v) => $q->where('due_date', '<=', $v));
                    }),
            ])
            ->actions([
                Action::make('send')
                    ->label('Kirim')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::ISSUED)
                    ->disabled(fn (Invoice $record): bool => !self::canResend($record))
                    ->requiresConfirmation()
                    ->action(function (Invoice $record): void {
                        $sent = app(InvoiceService::class)->send($record);
                        if ($sent) {
                            Notification::make()->title('Invoice dikirim via WA.')->success()->send();
                        } else {
                            Notification::make()->title('Batas pengiriman tercapai atau WA tidak aktif.')->warning()->send();
                        }
                    }),
                Action::make('resend')
                    ->label('Kirim Ulang')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (Invoice $record): bool => $record->status === InvoiceStatus::SENT)
                    ->disabled(fn (Invoice $record): bool => !self::canResend($record))
                    ->requiresConfirmation()
                    ->action(function (Invoice $record): void {
                        $sent = app(InvoiceService::class)->send($record);
                        if ($sent) {
                            Notification::make()->title('Invoice dikirim ulang.')->success()->send();
                        } else {
                            Notification::make()->title('Batas pengiriman tercapai.')->warning()->send();
                        }
                    }),
                Action::make('mark_paid')
                    ->label('Tandai Lunas')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Invoice $record): bool => !$record->isPaid()
                        && $record->status !== InvoiceStatus::CANCELLED)
                    ->form([
                        TextInput::make('proof_url')
                            ->label('URL Bukti Pembayaran (opsional)')
                            ->url()
                            ->placeholder('https://...'),
                    ])
                    ->action(function (Invoice $record, array $data): void {
                        app(InvoiceService::class)->markPaid($record, $data['proof_url'] ?? '');
                        Notification::make()->title('Invoice ditandai lunas.')->success()->send();
                    }),
            ]);
    }

    private static function canResend(Invoice $record): bool
    {
        $maxResend = (int) app(TenantPolicyService::class)->getPolicy(
            $record->tenant_id,
            PolicyKey::INVOICE_MAX_RESEND
        );
        return $record->canResend($maxResend);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
        ];
    }
}
