<?php

namespace App\Filament\Superadmin\Resources\PlanResource\RelationManagers;

use App\Modules\Shared\Enums\FeatureKey;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FeaturesRelationManager extends RelationManager
{
    protected static string $relationship = 'features';

    protected static ?string $title = 'Plan Features';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('feature_key')
                ->label('Feature')
                ->options(
                    collect(FeatureKey::cases())
                        ->mapWithKeys(fn (FeatureKey $k) => [$k->value => $k->label()])
                )
                ->required(),
            TextInput::make('feature_value')
                ->label('Value')
                ->required()
                ->maxLength(100)
                ->helperText('Boolean: "true"/"false". Numeric: integer atau "-1" untuk unlimited.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('feature_key')
                    ->label('Feature')
                    ->formatStateUsing(fn ($state): string =>
                        $state instanceof FeatureKey ? $state->label() : (string) $state),
                TextColumn::make('feature_value')
                    ->label('Value')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state === 'true'  => 'success',
                        $state === 'false' => 'danger',
                        $state === '-1'    => 'warning',
                        default            => 'gray',
                    }),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
