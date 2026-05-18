<?php

namespace App\Filament\Tenant\Pages;

use App\Modules\QualityGuard\Enums\QualitySeverity;
use App\Modules\QualityGuard\Models\ConversationQualityIssue;
use App\Modules\Shared\Scopes\TenantScope;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class QualityIssuesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'quality-issues';

    protected static ?string $navigationLabel = 'Quality Issues';

    protected static ?int $navigationSort = 4;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    public function getView(): string
    {
        return 'filament.tenant.pages.quality-issues';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                ConversationQualityIssue::query()
                    ->withoutGlobalScope(TenantScope::class)
                    ->where('tenant_id', auth()->user()->tenant_id)
                    ->latest('created_at')
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
                BadgeColumn::make('severity')
                    ->label('Severity')
                    ->colors([
                        'danger'  => fn ($state) => $state === QualitySeverity::CRITICAL,
                        'warning' => fn ($state) => $state === QualitySeverity::HIGH,
                        'gray'    => fn ($state) => $state === QualitySeverity::LOW,
                    ])
                    ->formatStateUsing(fn ($state) => strtoupper($state->value)),
                TextColumn::make('code')
                    ->label('Code')
                    ->formatStateUsing(fn ($state) => $state->label())
                    ->searchable(),
                TextColumn::make('message')
                    ->limit(80)
                    ->tooltip(fn (ConversationQualityIssue $r) => $r->message),
                TextColumn::make('source')
                    ->badge(),
                TextColumn::make('conversation_id')
                    ->label('Conversation')
                    ->formatStateUsing(fn ($state) => substr($state, 0, 8) . '…')
                    ->url(fn ($state) => url('/app/inbox?conv=' . $state))
                    ->openUrlInNewTab(),
                TextColumn::make('resolved_at')
                    ->label('Resolved')
                    ->dateTime('d M H:i')
                    ->placeholder('Open')
                    ->color(fn ($state) => $state ? 'success' : 'danger'),
                TextColumn::make('resolution_type')
                    ->label('Resolution')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state) => $state?->value),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->options([
                        'critical' => 'CRITICAL',
                        'high'     => 'HIGH',
                        'low'      => 'LOW',
                    ]),
                SelectFilter::make('code')
                    ->options([
                        'availability_hallucination'      => 'Availability hallucination',
                        'price_hallucination'             => 'Price hallucination',
                        'stage_closed_without_payment'    => 'Stage closed w/o payment',
                        'handoff_promise_without_record'  => 'Handoff promise w/o record',
                        'malformed_customer_phone'        => 'Malformed phone',
                        'customer_name_not_synced'        => 'Customer name not synced',
                        'redundant_pricelist'             => 'Redundant pricelist',
                        'llm_grader_low_score'            => 'LLM grader low score',
                    ]),
                TernaryFilter::make('resolved_at')
                    ->label('Status')
                    ->placeholder('All')
                    ->trueLabel('Resolved')
                    ->falseLabel('Open')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('resolved_at'),
                        false: fn (Builder $q) => $q->whereNull('resolved_at'),
                    ),
            ])
            ->actions([
                Action::make('resolve')
                    ->label('Mark resolved')
                    ->icon('heroicon-o-check')
                    ->visible(fn (ConversationQualityIssue $r) => $r->resolved_at === null)
                    ->action(function (ConversationQualityIssue $r) {
                        $r->update([
                            'resolved_at'     => now(),
                            'resolved_by'     => auth()->id(),
                            'resolution_type' => 'manual',
                        ]);
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
