<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class TenantPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('tenant')
            ->path('app')
            ->login()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->discoverResources(
                in: app_path('Filament/Tenant/Resources'),
                for: 'App\\Filament\\Tenant\\Resources'
            )
            ->discoverPages(
                in: app_path('Filament/Tenant/Pages'),
                for: 'App\\Filament\\Tenant\\Pages'
            )
            ->discoverWidgets(
                in: app_path('Filament/Tenant/Widgets'),
                for: 'App\\Filament\\Tenant\\Widgets'
            )
            ->renderHook(
                'panels::head.end',
                fn () => $this->appCssLink(),
            )
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    private function appCssLink(): HtmlString
    {
        $manifestPath = public_path('build/manifest.json');

        if (!file_exists($manifestPath)) {
            return new HtmlString('');
        }

        $manifest = json_decode(file_get_contents($manifestPath), true);
        $file = $manifest['resources/css/app.css']['file'] ?? null;

        if (!$file) {
            return new HtmlString('');
        }

        $url = asset('build/' . $file);

        return new HtmlString('<link rel="stylesheet" href="' . e($url) . '" data-navigate-track>');
    }
}
