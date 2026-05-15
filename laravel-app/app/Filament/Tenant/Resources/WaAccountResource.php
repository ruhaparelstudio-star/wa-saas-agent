<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\WaAccountResource\Pages;
use App\Modules\Shared\Enums\WaAccountStatus;
use App\Modules\WhatsApp\Models\WaAccount;
use App\Modules\WhatsApp\Services\WaAccountService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WaAccountResource extends Resource
{
    protected static ?string $model = WaAccount::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?string $navigationLabel = 'Akun WhatsApp';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'WhatsApp';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('display_name')
                ->label('Nama Akun')
                ->required()
                ->maxLength(255)
                ->placeholder('Contoh: CS Utama, CS Backup'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('display_name')
                    ->label('Nama Akun')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('phone_number')
                    ->label('Nomor WA')
                    ->formatStateUsing(fn (?string $state): string => $state
                        ? preg_replace('/(\+62\d{2})\d+(\d{3})/', '$1****$2', $state)
                        : '-'
                    )
                    ->placeholder('-'),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (WaAccountStatus $state): string => $state->label())
                    ->color(fn (WaAccountStatus $state): string => match ($state) {
                        WaAccountStatus::CONNECTED              => 'success',
                        WaAccountStatus::QR_PENDING,
                        WaAccountStatus::CONNECTING,
                        WaAccountStatus::RECONNECTING           => 'warning',
                        WaAccountStatus::DISCONNECTED           => 'gray',
                        WaAccountStatus::FAILED,
                        WaAccountStatus::BANNED_OR_RESTRICTED   => 'danger',
                    }),
                Tables\Columns\TextColumn::make('connected_at')
                    ->label('Terhubung Sejak')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->placeholder('-'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('connect')
                    ->label('Connect')
                    ->icon('heroicon-o-wifi')
                    ->color('success')
                    ->visible(fn (WaAccount $record): bool => in_array(
                        $record->status,
                        [WaAccountStatus::DISCONNECTED, WaAccountStatus::FAILED, WaAccountStatus::BANNED_OR_RESTRICTED]
                    ))
                    ->action(function (WaAccount $record): void {
                        $success = app(WaAccountService::class)->initiateConnect($record);

                        if ($success) {
                            Notification::make()
                                ->title('Koneksi dimulai. Silakan scan QR Code.')
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Gagal memulai koneksi ke WA Gateway.')
                                ->danger()
                                ->send();
                        }
                    }),

                Action::make('view_qr')
                    ->label('Lihat QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('warning')
                    ->visible(fn (WaAccount $record): bool => in_array(
                        $record->status,
                        [WaAccountStatus::QR_PENDING, WaAccountStatus::CONNECTING]
                    ))
                    ->modalHeading('Scan QR Code WhatsApp')
                    ->modalContent(fn (WaAccount $record) => view(
                        'filament.tenant.wa-qr-modal',
                        ['account' => $record]
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup'),

                Action::make('disconnect')
                    ->label('Disconnect')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (WaAccount $record): bool => in_array(
                        $record->status,
                        [WaAccountStatus::CONNECTED, WaAccountStatus::RECONNECTING]
                    ))
                    ->requiresConfirmation()
                    ->modalHeading('Putuskan Koneksi WA?')
                    ->modalDescription('AI agent tidak dapat membalas pesan WA setelah disconnect.')
                    ->action(function (WaAccount $record): void {
                        app(WaAccountService::class)->disconnect($record);

                        Notification::make()
                            ->title('Akun WA berhasil diputus.')
                            ->success()
                            ->send();
                    }),

                DeleteAction::make()
                    ->visible(fn (WaAccount $record): bool => $record->status === WaAccountStatus::DISCONNECTED),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', auth()->user()->tenant_id);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListWaAccounts::route('/'),
            'create' => Pages\CreateWaAccount::route('/create'),
        ];
    }
}
