<?php

namespace App\Filament\Superadmin\Resources;

use App\Filament\Superadmin\Resources\TenantResource\Pages;
use App\Modules\Plans\Models\Plan;
use App\Modules\Plans\Models\TenantSubscription;
use App\Modules\Plans\Services\FeatureGateService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Shared\Enums\TenantStatus;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select as FormSelect;
use Filament\Forms\Components\TextInput as FormTextInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class TenantResource extends Resource
{
    protected static ?string $model = Tenant::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Tenants';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('contact_email')
                ->email()
                ->required()
                ->maxLength(255),
            TextInput::make('contact_phone')
                ->tel()
                ->maxLength(20),
            TextInput::make('industry')
                ->default('wedding')
                ->maxLength(100),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('subscription.plan.name')
                    ->label('Plan')
                    ->placeholder('No plan')
                    ->badge()
                    ->color('primary'),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state): string => match (true) {
                        $state === TenantStatus::TRIAL || $state === TenantStatus::TRIAL->value => 'warning',
                        $state === TenantStatus::ACTIVE || $state === TenantStatus::ACTIVE->value => 'success',
                        $state === TenantStatus::EXPIRED || $state === TenantStatus::EXPIRED->value => 'danger',
                        $state === TenantStatus::SUSPENDED || $state === TenantStatus::SUSPENDED->value => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('contact_email')
                    ->searchable(),
                Tables\Columns\TextColumn::make('industry'),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordUrl(fn (Tenant $record): string => static::getUrl('edit', ['record' => $record]))
            ->actions([
                Action::make('activate')
                    ->label('Activate')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Tenant $record) => $record->status !== TenantStatus::ACTIVE)
                    ->action(function (Tenant $record) {
                        app(TenantService::class)->updateStatus($record, TenantStatus::ACTIVE);
                        Notification::make()->title('Tenant activated.')->success()->send();
                    }),
                Action::make('suspend')
                    ->label('Suspend')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->visible(fn (Tenant $record) => $record->status !== TenantStatus::SUSPENDED)
                    ->requiresConfirmation()
                    ->action(function (Tenant $record) {
                        app(TenantService::class)->updateStatus($record, TenantStatus::SUSPENDED);
                        Notification::make()->title('Tenant suspended.')->warning()->send();
                    }),
                Action::make('resend_activation')
                    ->label('Resend Activation')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (Tenant $record) => $record->status !== TenantStatus::ACTIVE)
                    ->action(function (Tenant $record) {
                        app(TenantService::class)->resendActivation($record);
                        Notification::make()->title('Activation email resent.')->success()->send();
                    }),
                Action::make('assign_plan')
                    ->label('Assign Plan')
                    ->icon('heroicon-o-credit-card')
                    ->color('primary')
                    ->schema([
                        FormSelect::make('plan_id')
                            ->label('Plan')
                            ->options(
                                Plan::where('is_active', true)
                                    ->orderBy('sort_order')
                                    ->pluck('name', 'id')
                            )
                            ->required(),
                        FormTextInput::make('trial_days')
                            ->label('Trial Days (0 = langsung aktif)')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])
                    ->action(function (Tenant $record, array $data) {
                        $plan = Plan::findOrFail($data['plan_id']);
                        $trialDays = (int) ($data['trial_days'] ?? 0);

                        TenantSubscription::updateOrCreate(
                            ['tenant_id' => $record->id],
                            [
                                'plan_id' => $plan->id,
                                'status' => $trialDays > 0 ? 'trial' : 'active',
                                'starts_at' => now(),
                                'ends_at' => null,
                                'trial_ends_at' => $trialDays > 0 ? now()->addDays($trialDays) : null,
                            ]
                        );

                        app(FeatureGateService::class)->clearCache($record->id);

                        Notification::make()
                            ->title("Plan '{$plan->name}' assigned to '{$record->name}'.")
                            ->success()
                            ->send();
                    }),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTenants::route('/'),
            'create' => Pages\CreateTenant::route('/create'),
            'edit' => Pages\EditTenant::route('/{record}/edit'),
        ];
    }
}
