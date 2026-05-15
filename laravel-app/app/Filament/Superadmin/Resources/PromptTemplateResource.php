<?php

namespace App\Filament\Superadmin\Resources;

use App\Filament\Superadmin\Resources\PromptTemplateResource\Pages;
use App\Modules\AgentCore\LLM\Models\PromptTemplate;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class PromptTemplateResource extends Resource
{
    protected static ?string $model = PromptTemplate::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Prompt Templates';

    protected static ?int $navigationSort = 11;

    public static function getNavigationGroup(): string
    {
        return 'AI Pipeline';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('name')
                ->label('Template Name')
                ->options([
                    'intent_classifier'   => 'Intent Classifier',
                    'entity_extractor'    => 'Entity Extractor',
                    'response_composer'   => 'Response Composer',
                ])
                ->required()
                ->searchable(),
            TextInput::make('version')
                ->label('Version')
                ->placeholder('v1.0')
                ->required()
                ->maxLength(20),
            Toggle::make('is_active')
                ->label('Active')
                ->default(true),
            Textarea::make('template')
                ->label('Template')
                ->required()
                ->rows(20)
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label('Notes')
                ->nullable()
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->badge()
                    ->color('primary')
                    ->sortable(),
                Tables\Columns\TextColumn::make('version')
                    ->label('Version')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
                Tables\Columns\TextColumn::make('accuracy_history')
                    ->label('Latest Accuracy')
                    ->formatStateUsing(function ($state): string {
                        if (empty($state) || ! is_array($state)) {
                            return '—';
                        }
                        $latest = end($state);
                        if (! isset($latest['score'])) {
                            return '—';
                        }
                        return number_format($latest['score'] * 100, 1) . '%';
                    })
                    ->badge()
                    ->color(function ($state): string {
                        if (empty($state) || ! is_array($state)) {
                            return 'gray';
                        }
                        $latest = end($state);
                        $score = $latest['score'] ?? 0;
                        return $score >= 0.95 ? 'success' : ($score >= 0.85 ? 'warning' : 'danger');
                    }),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Updated')
                    ->dateTime()
                    ->sortable()
                    ->since(),
            ])
            ->defaultSort('name')
            ->actions([
                Action::make('toggle_active')
                    ->label(fn (PromptTemplate $record) => $record->is_active ? 'Deactivate' : 'Activate')
                    ->icon(fn (PromptTemplate $record) => $record->is_active ? 'heroicon-o-x-circle' : 'heroicon-o-check-circle')
                    ->color(fn (PromptTemplate $record) => $record->is_active ? 'danger' : 'success')
                    ->requiresConfirmation()
                    ->action(function (PromptTemplate $record): void {
                        $record->update(['is_active' => ! $record->is_active]);
                        Notification::make()
                            ->title($record->is_active ? 'Template activated.' : 'Template deactivated.')
                            ->success()
                            ->send();
                    }),
                Action::make('view_accuracy')
                    ->label('Accuracy History')
                    ->icon('heroicon-o-chart-bar')
                    ->color('info')
                    ->modalDescription(function (PromptTemplate $record): string {
                        $history = $record->accuracy_history ?? [];
                        if (empty($history)) {
                            return 'No accuracy history recorded.';
                        }
                        return implode("\n", array_map(function ($entry) {
                            $score = number_format(($entry['score'] ?? 0) * 100, 1);
                            return sprintf(
                                '%s | %s | %s%% (%s tests)',
                                $entry['version'] ?? '—',
                                $entry['date'] ?? '—',
                                $score,
                                $entry['test_count'] ?? '?'
                            );
                        }, $history));
                    })
                    ->modalHeading('Accuracy History')
                    ->modalSubmitAction(false),
            ])
            ->recordUrl(fn (PromptTemplate $record): string => static::getUrl('edit', ['record' => $record]));
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListPromptTemplates::route('/'),
            'create' => Pages\CreatePromptTemplate::route('/create'),
            'edit'   => Pages\EditPromptTemplate::route('/{record}/edit'),
        ];
    }
}
