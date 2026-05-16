<?php

namespace App\Filament\Tenant\Pages;

use App\Modules\Calendar\Services\GoogleOAuthService;
use App\Modules\TenantConfig\Models\TenantSetting;
use Carbon\Carbon;
use Filament\Actions\Action;
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
        ]);
    }

    public function getConnectionStatus(): array
    {
        $setting   = $this->tenantSetting;
        $tokenData = $this->loadTokenData($setting);

        if (! $setting || ! $setting->google_calendar_enabled) {
            return ['color' => 'gray', 'label' => 'Tidak Aktif', 'connected' => false];
        }

        if (! empty($tokenData['access_token'])) {
            $expiresAt = ! empty($tokenData['expires_at'])
                ? Carbon::parse($tokenData['expires_at'])->format('d M Y H:i')
                : null;
            return [
                'color'      => 'success',
                'label'      => 'Terhubung ke Google Calendar',
                'connected'  => true,
                'expires_at' => $expiresAt,
            ];
        }

        return ['color' => 'warning', 'label' => 'Belum Terhubung — Klik "Connect" untuk mulai OAuth', 'connected' => false];
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Toggle::make('google_calendar_enabled')
                ->label('Aktifkan Google Calendar')
                ->helperText('Sinkronkan booking dengan Google Calendar milik tenant.')
                ->live(),
        ])->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        $tenantId  = auth()->user()->tenant_id;
        $status    = $this->getConnectionStatus();

        $actions = [];

        if ($status['connected']) {
            $actions[] = Action::make('disconnect')
                ->label('Disconnect Google Calendar')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function () use ($tenantId) {
                    app(GoogleOAuthService::class)->revokeToken($tenantId);
                    $this->tenantSetting = TenantSetting::where('tenant_id', $tenantId)->first();
                    Notification::make()->title('Google Calendar diputus.')->warning()->send();
                });
        } else {
            $actions[] = Action::make('connect')
                ->label('Connect Google Calendar')
                ->color('success')
                ->url(route('calendar.oauth.redirect'))
                ->openUrlInNewTab(false);
        }

        $actions[] = Action::make('save')
            ->label('Simpan Pengaturan')
            ->action('save');

        return $actions;
    }

    public function save(): void
    {
        $tenantId = auth()->user()->tenant_id;
        $data     = $this->form->getState();

        TenantSetting::updateOrCreate(
            ['tenant_id' => $tenantId],
            ['google_calendar_enabled' => (bool) ($data['google_calendar_enabled'] ?? false)]
        );

        $this->tenantSetting = TenantSetting::where('tenant_id', $tenantId)->first();

        Notification::make()
            ->title('Pengaturan kalender disimpan.')
            ->success()
            ->send();
    }

    private function loadTokenData(?TenantSetting $setting): array
    {
        if (! $setting || ! $setting->google_oauth_token) {
            return [];
        }
        $decoded = json_decode($setting->google_oauth_token, true);
        return is_array($decoded) ? $decoded : [];
    }
}
