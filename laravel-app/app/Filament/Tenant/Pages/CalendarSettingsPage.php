<?php

namespace App\Filament\Tenant\Pages;

use App\Modules\TenantConfig\Models\TenantSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class CalendarSettingsPage extends Page
{
    protected static ?string $slug = 'calendar-settings';

    protected static ?string $navigationLabel = 'Google Calendar';

    protected static ?int $navigationSort = 3;

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return 'heroicon-o-calendar-days';
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Pengaturan';
    }

    public function getView(): string
    {
        return 'filament.tenant.pages.calendar-settings';
    }

    public ?array $data = [];

    public function mount(): void
    {
        $tenantId = auth()->user()->tenant_id;
        $setting  = TenantSetting::where('tenant_id', $tenantId)->first();

        $this->form->fill([
            'google_calendar_enabled' => (bool) ($setting?->google_calendar_enabled ?? false),
            'google_oauth_token'      => $setting?->google_oauth_token ?? '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Toggle::make('google_calendar_enabled')
                ->label('Aktifkan Google Calendar')
                ->helperText('Sinkronkan booking dengan Google Calendar milik tenant.')
                ->live(),
            Textarea::make('google_oauth_token')
                ->label('OAuth Token Google (manual Phase 5)')
                ->helperText('Paste token dari Google OAuth Playground. Phase 6 akan pakai flow otomatis.')
                ->rows(4)
                ->placeholder('ya29.xxx...')
                ->visible(fn ($get) => (bool) $get('google_calendar_enabled')),
        ])->statePath('data');
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan Pengaturan')
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $tenantId = auth()->user()->tenant_id;
        $data     = $this->form->getState();

        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'google_calendar_enabled' => (bool) ($data['google_calendar_enabled'] ?? false),
                'google_oauth_token'      => $data['google_oauth_token'] ?? null,
            ]
        );

        Notification::make()
            ->title('Pengaturan kalender disimpan.')
            ->success()
            ->send();
    }
}
