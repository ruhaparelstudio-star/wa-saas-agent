<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\PackageResource\Pages;
use App\Filament\Tenant\Resources\PackageResource\RelationManagers;
use App\Modules\Knowledge\Models\Package;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationLabel = 'Paket';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengetahuan';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Nama Paket')
                ->required()
                ->maxLength(255),
            TextInput::make('slug')
                ->label('Slug')
                ->required()
                ->maxLength(255)
                ->rules(fn ($record) => [
                    Rule::unique('packages', 'slug')
                        ->where('tenant_id', auth()->user()->tenant_id)
                        ->ignore($record?->id),
                ]),
            Textarea::make('description')
                ->label('Deskripsi')
                ->nullable()
                ->rows(3),
            Select::make('category')
                ->label('Kategori')
                ->options([
                    'wedding' => 'Wedding',
                    'corporate' => 'Corporate',
                    'birthday' => 'Birthday',
                ])
                ->default('wedding')
                ->required(),
            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
            TextInput::make('sort_order')
                ->label('Urutan')
                ->numeric()
                ->default(0),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nama Paket')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('prices_count')
                    ->counts('prices')
                    ->badge()
                    ->label('Harga'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Aktif')
                    ->sortable(),
                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->label('Diupdate'),
            ])
            ->defaultSort('sort_order');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', auth()->user()->tenant_id);
    }

    public static function getRelationManagers(): array
    {
        return [
            RelationManagers\PricesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }
}
