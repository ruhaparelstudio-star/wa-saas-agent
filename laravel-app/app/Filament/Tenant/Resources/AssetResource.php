<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\AssetResource\Pages;
use App\Modules\Knowledge\Models\Asset;
use App\Modules\Shared\Enums\AssetType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            TextInput::make('name')
                ->label('Nama')
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->label('Tipe')
                ->options(collect(AssetType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()]))
                ->required(),
            TextInput::make('file_path')
                ->label('Path File')
                ->required()
                ->maxLength(500)
                ->helperText('Path di storage, contoh: tenants/abc/pricelist.pdf'),
            TextInput::make('file_url')
                ->label('URL Publik')
                ->nullable()
                ->maxLength(500)
                ->url(),
            TextInput::make('mime_type')
                ->label('MIME Type')
                ->required()
                ->maxLength(100)
                ->placeholder('Contoh: application/pdf'),
            TextInput::make('file_size_kb')
                ->label('Ukuran (KB)')
                ->numeric()
                ->default(0),
            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
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
                Tables\Columns\TextColumn::make('human_file_size')
                    ->label('Ukuran')
                    ->getStateUsing(fn ($record) => $record->human_file_size),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Aktif')
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Dibuat'),
            ])
            ->defaultSort('created_at', 'desc');
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
