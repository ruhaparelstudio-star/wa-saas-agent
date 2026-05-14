<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\PackageResource\Pages;
use App\Modules\Knowledge\Models\Package;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
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
            Repeater::make('prices')
                ->label('Daftar Harga')
                ->relationship('prices')
                ->schema([
                    TextInput::make('label')
                        ->label('Label')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('Contoh: Weekday, Weekend, Peak Season')
                        ->columnSpan(2),
                    TextInput::make('price_idr')
                        ->label('Harga (IDR)')
                        ->required()
                        ->numeric()
                        ->prefix('Rp')
                        ->columnSpan(2),
                    DatePicker::make('valid_from')
                        ->label('Berlaku Dari')
                        ->required()
                        ->columnSpan(1),
                    DatePicker::make('valid_until')
                        ->label('Berlaku Sampai')
                        ->nullable()
                        ->helperText('Kosongkan jika berlaku selamanya')
                        ->columnSpan(1),
                    Textarea::make('notes')
                        ->label('Catatan')
                        ->nullable()
                        ->rows(2)
                        ->columnSpan(2),
                    Toggle::make('is_active')
                        ->label('Aktif')
                        ->default(true)
                        ->columnSpan(2),
                ])
                ->columns(2)
                ->addActionLabel('+ Tambah Variasi Harga')
                ->collapsible()
                ->defaultItems(1)
                ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                    $data['tenant_id'] = auth()->user()->tenant_id;
                    return $data;
                })
                ->columnSpanFull(),
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
                Tables\Columns\TextColumn::make('price_range')
                    ->label('Harga')
                    ->getStateUsing(function (Package $record): string {
                        $prices = $record->activePrices()->orderBy('price_idr')->pluck('price_idr');
                        if ($prices->isEmpty()) {
                            return '-';
                        }
                        $min = 'Rp ' . number_format($prices->first(), 0, ',', '.');
                        if ($prices->count() === 1) {
                            return $min;
                        }
                        $max = 'Rp ' . number_format($prices->last(), 0, ',', '.');
                        return $min . ' – ' . $max;
                    }),
                Tables\Columns\TextColumn::make('prices_count')
                    ->counts('prices')
                    ->badge()
                    ->color('gray')
                    ->label('Variasi'),
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
            ->defaultSort('sort_order')
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', auth()->user()->tenant_id);
    }

    public static function getRelationManagers(): array
    {
        return [];
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
