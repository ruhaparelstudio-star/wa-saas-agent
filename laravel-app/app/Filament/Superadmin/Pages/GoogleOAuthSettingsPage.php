<?php

namespace App\Filament\Superadmin\Pages;

use App\Modules\Shared\Models\SystemSetting;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

class GoogleOAuthSettingsPage extends Page
{
    protected static ?string $slug = 'google-oauth-settings';

    protected static ?string $navigationLabel = 'Google OAuth';

    protected static ?int $navigationSort = 10;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-key';

    public static function getNavigationGroup(): ?string
    {
        return 'System Settings';
    }

    public function getView(): string
    {
        return 'filament.superadmin.pages.google-oauth-settings';
    }

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'google_client_id'     => SystemSetting::get('google_client_id'),
            'google_client_secret' => SystemSetting::get('google_client_secret'),
            'google_redirect_uri'  => SystemSetting::get('google_redirect_uri', url('/app/calendar/oauth/callback')),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $isConfigured = $this->isConfigured();

        return $schema->components([
            Section::make('Status Koneksi Google OAuth')
                ->schema([
                    Placeholder::make('status_indicator')
                        ->label('')
                        ->content($isConfigured
                            ? '✅ Google OAuth sudah dikonfigurasi. Client ID dan Client Secret tersimpan.'
                            : '⚠️ Google OAuth belum dikonfigurasi. Masukkan Client ID dan Client Secret dari Google Cloud Console.'
                        ),
                ]),

            Section::make('Kredensial Google OAuth 2.0')
                ->description('Dapatkan credentials dari Google Cloud Console → APIs & Services → Credentials → OAuth 2.0 Client IDs.')
                ->schema([
                    TextInput::make('google_client_id')
                        ->label('Google Client ID')
                        ->placeholder('123456789-xxxx.apps.googleusercontent.com')
                        ->required()
                        ->maxLength(512),

                    TextInput::make('google_client_secret')
                        ->label('Google Client Secret')
                        ->placeholder('GOCSPX-xxxxxxxxxxxx')
                        ->password()
                        ->revealable()
                        ->required()
                        ->maxLength(512),

                    TextInput::make('google_redirect_uri')
                        ->label('Redirect URI')
                        ->helperText('Daftarkan URI ini di Google Cloud Console sebagai Authorized Redirect URI.')
                        ->required()
                        ->maxLength(512),
                ]),
        ])->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Simpan Konfigurasi')
                ->icon('heroicon-o-check')
                ->action('save'),
        ];
    }

    public function save(): void
    {
        $data = $this->form->getState();

        SystemSetting::set('google_client_id', $data['google_client_id'] ?? null, 'google_oauth');
        SystemSetting::set('google_client_secret', $data['google_client_secret'] ?? null, 'google_oauth');
        SystemSetting::set('google_redirect_uri', $data['google_redirect_uri'] ?? null, 'google_oauth');

        Notification::make()
            ->title('Konfigurasi Google OAuth disimpan.')
            ->success()
            ->send();
    }

    private function isConfigured(): bool
    {
        return !empty(SystemSetting::get('google_client_id'))
            && !empty(SystemSetting::get('google_client_secret'));
    }
}
