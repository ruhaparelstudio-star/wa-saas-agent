<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use App\Filament\Superadmin\Widgets\PlatformStatsWidget;
use App\Filament\Superadmin\Widgets\TenantStatsWidget;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class SuperadminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('superadmin')
            ->path('superadmin')
            ->login()
            ->brandName('WA SaaS — Admin')
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->profile(isSimple: false)
            ->discoverResources(
                in: app_path('Filament/Superadmin/Resources'),
                for: 'App\\Filament\\Superadmin\\Resources'
            )
            ->discoverPages(
                in: app_path('Filament/Superadmin/Pages'),
                for: 'App\\Filament\\Superadmin\\Pages'
            )
            ->discoverWidgets(
                in: app_path('Filament/Superadmin/Widgets'),
                for: 'App\\Filament\\Superadmin\\Widgets'
            )
            ->widgets([
                TenantStatsWidget::class,
                PlatformStatsWidget::class,
            ])
            ->navigationItems([
                NavigationItem::make('Queue Monitor')
                    ->url('/horizon')
                    ->openUrlInNewTab()
                    ->icon('heroicon-o-queue-list')
                    ->group('System Settings')
                    ->sort(90),
            ])
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
}
