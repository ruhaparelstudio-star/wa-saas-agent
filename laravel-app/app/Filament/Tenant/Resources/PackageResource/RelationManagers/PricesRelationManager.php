<?php

namespace App\Filament\Tenant\Resources\PackageResource\RelationManagers;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PricesRelationManager extends RelationManager
{
    protected static string $relationship = 'prices';

    protected static ?string $title = 'Daftar Harga';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')
                ->label('Label')
                ->required()
                ->maxLength(100)
                ->placeholder('Contoh: Weekday, Weekend, Peak Season'),
            TextInput::make('price_idr')
                ->label('Harga (IDR)')
                ->required()
                ->numeric()
                ->prefix('Rp'),
            DatePicker::make('valid_from')
                ->label('Berlaku Dari')
                ->required(),
            DatePicker::make('valid_until')
                ->label('Berlaku Sampai')
                ->nullable()
                ->helperText('Kosongkan jika berlaku selamanya'),
            Textarea::make('notes')
                ->label('Catatan')
                ->nullable()
                ->rows(2),
            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')
                    ->label('Label')
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_idr')
                    ->label('Harga')
                    ->formatStateUsing(fn ($state) => 'Rp ' . number_format($state, 0, ',', '.'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('valid_from')
                    ->label('Berlaku Dari')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('valid_until')
                    ->label('Berlaku Sampai')
                    ->date()
                    ->placeholder('Selamanya'),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Aktif'),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['tenant_id'] = auth()->user()->tenant_id;
                        return $data;
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }
}
