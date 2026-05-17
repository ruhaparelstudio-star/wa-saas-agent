<?php

namespace App\Filament\Superadmin\Resources;

use App\Filament\Superadmin\Resources\DecisionTraceResource\Pages;
use App\Modules\AgentCore\Pipeline\Models\DecisionTrace;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DecisionTraceResource extends Resource
{
    protected static ?string $model = DecisionTrace::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-magnifying-glass-circle';

    protected static ?string $navigationLabel = 'Decision Traces';

    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): string
    {
        return 'AI Pipeline';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Input')
                ->columns(2)
                ->schema([
                    TextEntry::make('raw_message')
                        ->label('Raw Message')
                        ->columnSpanFull(),
                    TextEntry::make('message_type')
                        ->label('Message Type')
                        ->badge(),
                    TextEntry::make('is_sanitized')
                        ->label('Sanitized')
                        ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
                    IconEntry::make('injection_detected')
                        ->label('Injection Detected')
                        ->boolean()
                        ->trueColor('danger')
                        ->falseColor('success'),
                ]),

            Section::make('Intent Classification')
                ->columns(2)
                ->schema([
                    TextEntry::make('intent')
                        ->label('Intent')
                        ->badge()
                        ->color('primary'),
                    TextEntry::make('intent_confidence')
                        ->label('Confidence')
                        ->formatStateUsing(fn ($state) => number_format((float) $state * 100, 1) . '%'),
                    TextEntry::make('intent_reason')
                        ->label('Reason')
                        ->columnSpanFull()
                        ->placeholder('—'),
                    TextEntry::make('intent_raw_response')
                        ->label('Raw LLM Response')
                        ->columnSpanFull()
                        ->placeholder('—')
                        ->fontFamily('mono')
                        ->size('sm'),
                ]),

            Section::make('Entity Extraction')
                ->columns(2)
                ->schema([
                    KeyValueEntry::make('extracted_entities')
                        ->label('Extracted Entities')
                        ->columnSpanFull(),
                    TextEntry::make('entity_confidence')
                        ->label('Entity Confidence')
                        ->formatStateUsing(fn ($state) => $state ? number_format((float) $state * 100, 1) . '%' : '—'),
                ]),

            Section::make('Decision')
                ->columns(2)
                ->schema([
                    TextEntry::make('decision')
                        ->label('Decision')
                        ->badge()
                        ->color(fn ($state) => match ($state) {
                            'proceed'     => 'success',
                            'handoff'     => 'warning',
                            'blocked'     => 'danger',
                            'after_hours' => 'gray',
                            default       => 'gray',
                        }),
                    TextEntry::make('stage_before')
                        ->label('Stage Before')
                        ->placeholder('—'),
                    TextEntry::make('stage_after')
                        ->label('Stage After')
                        ->placeholder('—'),
                    IconEntry::make('handoff_required')
                        ->label('Handoff Required')
                        ->boolean(),
                    TextEntry::make('desired_actions')
                        ->label('Desired Actions')
                        ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state)
                        ->columnSpanFull(),
                    TextEntry::make('allowed_actions')
                        ->label('Allowed Actions')
                        ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state)
                        ->columnSpanFull(),
                ]),

            Section::make('Validators')
                ->columns(2)
                ->schema([
                    TextEntry::make('policy_result')
                        ->label('Policy')
                        ->badge()
                        ->color(fn ($state) => $state === 'passed' ? 'success' : 'danger')
                        ->placeholder('—'),
                    TextEntry::make('grounding_result')
                        ->label('Grounding')
                        ->badge()
                        ->color(fn ($state) => match ($state) {
                            'passed'  => 'success',
                            'partial' => 'warning',
                            'failed'  => 'danger',
                            default   => 'gray',
                        })
                        ->placeholder('—'),
                    TextEntry::make('permission_result')
                        ->label('Permission')
                        ->badge()
                        ->color(fn ($state) => str_starts_with((string) $state, 'passed') ? 'success' : 'danger')
                        ->placeholder('—'),
                    TextEntry::make('mode_result')
                        ->label('Mode')
                        ->badge()
                        ->color(fn ($state) => $state === 'passed' ? 'success' : 'danger')
                        ->placeholder('—'),
                ]),

            Section::make('LLM Prompts')
                ->collapsed()
                ->schema([
                    TextEntry::make('intent_prompt')
                        ->label('Intent Prompt')
                        ->columnSpanFull()
                        ->placeholder('—')
                        ->fontFamily('mono')
                        ->size('sm'),
                    TextEntry::make('entity_prompt')
                        ->label('Entity Prompt')
                        ->columnSpanFull()
                        ->placeholder('—')
                        ->fontFamily('mono')
                        ->size('sm'),
                    TextEntry::make('composer_prompt')
                        ->label('Composer Prompt')
                        ->columnSpanFull()
                        ->placeholder('—')
                        ->fontFamily('mono')
                        ->size('sm'),
                ]),

            Section::make('LLM Responses')
                ->collapsed()
                ->schema([
                    TextEntry::make('intent_llm_response')
                        ->label('Intent Response')
                        ->columnSpanFull()
                        ->placeholder('—')
                        ->fontFamily('mono')
                        ->size('sm'),
                    TextEntry::make('composer_llm_response')
                        ->label('Composer Response')
                        ->columnSpanFull()
                        ->placeholder('—')
                        ->fontFamily('mono')
                        ->size('sm'),
                ]),

            Section::make('Output')
                ->columns(2)
                ->schema([
                    TextEntry::make('final_reply')
                        ->label('Final Reply')
                        ->columnSpanFull()
                        ->placeholder('—'),
                    TextEntry::make('reply_type')
                        ->label('Reply Type')
                        ->badge()
                        ->placeholder('—'),
                    IconEntry::make('detected_hallucination')
                        ->label('Hallucination Detected')
                        ->boolean()
                        ->trueColor('danger')
                        ->falseColor('success'),
                    TextEntry::make('actions_dispatched')
                        ->label('Actions Dispatched')
                        ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : $state)
                        ->placeholder('—'),
                    TextEntry::make('prompt_tokens_total')
                        ->label('Prompt Tokens'),
                    TextEntry::make('completion_tokens_total')
                        ->label('Completion Tokens'),
                    TextEntry::make('processing_time_ms')
                        ->label('Processing Time')
                        ->formatStateUsing(fn ($state) => $state ? "{$state}ms" : '—'),
                    TextEntry::make('error_message')
                        ->label('Error')
                        ->columnSpanFull()
                        ->placeholder('None')
                        ->color('danger'),
                ]),

            Section::make('Meta')
                ->columns(2)
                ->schema([
                    TextEntry::make('tenant.name')
                        ->label('Tenant'),
                    TextEntry::make('conversation_id')
                        ->label('Conversation ID')
                        ->fontFamily('mono')
                        ->size('sm'),
                    TextEntry::make('created_at')
                        ->label('Created At')
                        ->dateTime(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable()
                    ->since(),
                Tables\Columns\TextColumn::make('tenant.name')
                    ->label('Tenant')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('intent')
                    ->label('Intent')
                    ->badge()
                    ->color('primary')
                    ->searchable(),
                Tables\Columns\TextColumn::make('intent_confidence')
                    ->label('Conf.')
                    ->formatStateUsing(fn ($state) => number_format((float) $state * 100, 0) . '%')
                    ->sortable(),
                Tables\Columns\TextColumn::make('decision')
                    ->label('Decision')
                    ->badge()
                    ->color(fn ($state): string => match ($state) {
                        'proceed'     => 'success',
                        'handoff'     => 'warning',
                        'blocked'     => 'danger',
                        'after_hours' => 'gray',
                        default       => 'gray',
                    }),
                Tables\Columns\TextColumn::make('processing_time_ms')
                    ->label('ms')
                    ->sortable()
                    ->placeholder('—'),
                Tables\Columns\IconColumn::make('detected_hallucination')
                    ->label('Halluc.')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success'),
                Tables\Columns\IconColumn::make('injection_detected')
                    ->label('Inject.')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('tenant_id')
                    ->label('Tenant')
                    ->relationship('tenant', 'name'),
                SelectFilter::make('intent')
                    ->label('Intent')
                    ->options(fn (): array => DecisionTrace::query()
                        ->whereNotNull('intent')
                        ->distinct()
                        ->pluck('intent', 'intent')
                        ->toArray()
                    ),
                SelectFilter::make('decision')
                    ->label('Decision')
                    ->options([
                        'proceed'     => 'Proceed',
                        'handoff'     => 'Handoff',
                        'blocked'     => 'Blocked',
                        'after_hours' => 'After Hours',
                    ]),
                Filter::make('has_hallucination')
                    ->label('Hallucination Only')
                    ->query(fn (Builder $query): Builder => $query->where('detected_hallucination', true)),
                Filter::make('has_injection')
                    ->label('Injection Detected')
                    ->query(fn (Builder $query): Builder => $query->where('injection_detected', true)),
            ])
            ->recordUrl(fn (DecisionTrace $record): string => static::getUrl('view', ['record' => $record]));
    }

    public static function getRelationManagers(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDecisionTraces::route('/'),
            'view'  => Pages\ViewDecisionTrace::route('/{record}'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
