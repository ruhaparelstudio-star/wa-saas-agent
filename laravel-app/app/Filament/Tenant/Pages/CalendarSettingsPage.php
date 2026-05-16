<?php

namespace App\Filament\Tenant\Pages;

use App\Modules\TenantConfig\Models\TenantSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
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

    public ?TenantSetting $tenantSetting = null;

    public function mount(): void
    {
        $tenantId = auth()->user()->tenant_id;
        $this->tenantSetting = TenantSetting::where('tenant_id', $tenantId)->first();

        $this->form->fill([
            'google_calendar_enabled' => (bool) ($this->tenantSetting?->google_calendar_enabled ?? false),
            'google_calendar_email'   => $this->tenantSetting?->google_calendar_email ?? '',
        ]);
    }

    public function getConnectionStatus(): array
    {
        $setting = $this->tenantSetting;

        if (!$setting || !$setting->google_calendar_enabled) {
            return ['color' => 'gray', 'label' => 'Tidak Aktif', 'icon' => '○'];
        }

        if (!empty($setting->google_calendar_email)) {
            return ['color' => 'warning', 'label' => 'Email Tersimpan — Menunggu OAuth (Phase 6)', 'icon' => '◑'];
        }

        return ['color' => 'danger', 'label' => 'Email Belum Diisi', 'icon' => '✕'];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Toggle::make('google_calendar_enabled')
                ->label('Aktifkan Google Calendar')
                ->helperText('Sinkronkan booking dengan Google Calendar milik tenant.')
                ->live(),
            TextInput::make('google_calendar_email')
                ->label('Email Google Calendar')
                ->helperText('Masukkan email akun Google yang akan disinkronkan dengan booking.')
                ->email()
                ->placeholder('nama@gmail.com')
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
                'google_calendar_email'   => $data['google_calendar_email'] ?? null,
            ]
        );

        Notification::make()
            ->title('Pengaturan kalender disimpan.')
            ->success()
            ->send();
    }
}
