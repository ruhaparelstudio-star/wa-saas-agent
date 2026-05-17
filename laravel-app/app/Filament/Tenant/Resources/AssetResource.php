<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\AssetResource\Pages;
use App\Modules\Knowledge\Models\Asset;
use App\Modules\Shared\Enums\AssetType;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

class AssetResource extends Resource
{
    protected static ?string $model = Asset::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-clip';

    protected static ?string $navigationLabel = 'Aset';

    protected static ?int $navigationSort = 4;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengetahuan';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label('Tipe Aset')
                ->options(collect(AssetType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                ->required()
                ->native(false)
                ->helperText('Pilih kategori aset ini — digunakan AI untuk mengenali jenis file.'),

            TextInput::make('name')
                ->label('Nama Aset')
                ->maxLength(255)
                ->placeholder('Opsional — diisi otomatis dari nama file')
                ->helperText('Kosongkan untuk menggunakan nama file secara otomatis.'),

            FileUpload::make('file_path')
                ->label('Upload File')
                ->disk('public')
                ->directory(fn () => 'tenants/' . auth()->user()->tenant_id . '/assets')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/jpg', 'image/png', 'image/webp'])
                ->maxSize(10240)
                ->required()
                ->downloadable()
                ->openable()
                ->helperText('Format yang didukung: PDF, JPG, PNG, WebP. Maksimal 10 MB.'),

            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true)
                ->helperText('Nonaktifkan agar file tidak digunakan oleh AI.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipe')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof AssetType ? $state->label() : $state),
                Tables\Columns\TextColumn::make('mime_type')
                    ->label('Format')
                    ->formatStateUsing(fn ($state) => match (true) {
                        str_contains($state, 'pdf') => 'PDF',
                        str_contains($state, 'png') => 'PNG',
                        str_contains($state, 'webp') => 'WebP',
                        str_contains($state, 'jpeg') || str_contains($state, 'jpg') => 'JPG',
                        default => strtoupper(last(explode('/', $state))),
                    })
                    ->badge()
                    ->color('gray'),
                Tables\Columns\TextColumn::make('human_file_size')
                    ->label('Ukuran')
                    ->getStateUsing(fn ($record) => $record->human_file_size),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Aktif')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y')
                    ->sortable()
                    ->label('Diunggah'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Action::make('open')
                    ->label('Buka')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn ($record) => $record->file_url ?: Storage::disk('public')->url($record->file_path))
                    ->openUrlInNewTab()
                    ->visible(fn ($record) => filled($record->file_path)),
                EditAction::make(),
                DeleteAction::make(),
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
            'index' => Pages\ListAssets::route('/'),
            'create' => Pages\CreateAsset::route('/create'),
            'edit' => Pages\EditAsset::route('/{record}/edit'),
        ];
    }
}
