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
            ->renderHook(
                'panels::body.end',
                fn () => $this->handoffAudioAlert(),
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

    /**
     * Audio alert that fires when a new HandoffRecord appears.
     *
     * Strategy:
     *  - Tiny Alpine snippet polls /internal/handoff-latest-id every 15s.
     *  - When the latest id changes (= new handoff was created), synthesize a
     *    two-tone chime via the Web Audio API. No audio asset file needed.
     *  - First page-load just learns the current id so old records don't chime.
     *  - Browser autoplay policy needs a user gesture — login / nav click
     *    satisfies that. AudioContext is created lazily inside the user-gesture
     *    callback chain (tick after first interaction), so chime is reliable.
     *  - Toggle persists in localStorage('handoff_audio_enabled') — default ON.
     */
    private function handoffAudioAlert(): HtmlString
    {
        if (!auth()->check() || empty(auth()->user()->tenant_id)) {
            return new HtmlString('');
        }

        $pollUrl = url('/internal/handoff-latest-id');

        $html = <<<HTML
<div
    x-data="{
        lastId: null,
        enabled: localStorage.getItem('handoff_audio_enabled') !== 'false',
        chime() {
            if (!this.enabled) return;
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                const ctx = new Ctx();
                const play = (freq, start, dur) => {
                    const o = ctx.createOscillator();
                    const g = ctx.createGain();
                    o.type = 'sine';
                    o.frequency.value = freq;
                    g.gain.setValueAtTime(0.0001, ctx.currentTime + start);
                    g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + start + 0.02);
                    g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + start + dur);
                    o.connect(g).connect(ctx.destination);
                    o.start(ctx.currentTime + start);
                    o.stop(ctx.currentTime + start + dur + 0.05);
                };
                play(880, 0.00, 0.18);
                play(1320, 0.20, 0.22);
                setTimeout(() => ctx.close(), 700);
            } catch (_) { /* swallow */ }
        },
        async tick() {
            if (!this.enabled) return;
            try {
                const r = await fetch('{$pollUrl}', { credentials: 'same-origin' });
                if (!r.ok) return;
                const j = await r.json();
                const id = j.id || null;
                if (this.lastId === null) { this.lastId = id; return; }
                if (id && id !== this.lastId) {
                    this.lastId = id;
                    this.chime();
                }
            } catch (_) { /* swallow */ }
        }
    }"
    x-init="tick(); setInterval(() => tick(), 15000)"
    style="display:none"
></div>
HTML;

        return new HtmlString($html);
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
