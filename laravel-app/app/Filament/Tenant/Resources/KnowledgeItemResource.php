<?php

namespace App\Filament\Tenant\Resources;

use App\Filament\Tenant\Resources\KnowledgeItemResource\Pages;
use App\Modules\Knowledge\Models\KnowledgeItem;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class KnowledgeItemResource extends Resource
{
    protected static ?string $model = KnowledgeItem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Pengetahuan';

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): ?string
    {
        return 'Pengetahuan';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Judul')
                ->required()
                ->maxLength(255),
            Textarea::make('content')
                ->label('Konten')
                ->required()
                ->rows(5),
            TextInput::make('category')
                ->label('Kategori')
                ->required()
                ->maxLength(100)
                ->placeholder('Contoh: terms, process, tips, policy'),
            TagsInput::make('tags')
                ->label('Tags')
                ->nullable(),
            Toggle::make('is_active')
                ->label('Aktif')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('Judul')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('category')
                    ->label('Kategori')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('tags')
                    ->label('Tags')
                    ->badge()
                    ->separator(','),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Aktif')
                    ->sortable(),
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
            'index' => Pages\ListKnowledgeItems::route('/'),
            'create' => Pages\CreateKnowledgeItem::route('/create'),
            'edit' => Pages\EditKnowledgeItem::route('/{record}/edit'),
        ];
    }
}
