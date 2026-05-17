<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerModuleProviders();
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
        $this->configureRateLimiting();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('webhook', function (Request $request) {
            $accountId = $request->input('wa_account_id', 'unknown');
            return Limit::perMinute(30)->by('webhook:' . $accountId)
                ->response(fn () => response()->json(['error' => 'Too many requests'], 429));
        });

        RateLimiter::for('api-auth', function (Request $request) {
            return Limit::perMinute(5)->by('auth:' . $request->ip())
                ->response(fn () => response()->json(['error' => 'Too many login attempts'], 429));
        });

        RateLimiter::for('export', function (Request $request) {
            return Limit::perHour(10)->by('export:' . ($request->user()?->id ?? $request->ip()))
                ->response(fn () => response()->json(['error' => 'Export rate limit exceeded'], 429));
        });

        RateLimiter::for('analytics-api', function (Request $request) {
            return Limit::perMinute(60)->by('analytics:' . ($request->user()?->id ?? $request->ip()));
        });
    }

    private function registerModuleProviders(): void
    {
        $modulesPath = app_path('Modules');

        if (!is_dir($modulesPath)) {
            return;
        }

        $files = array_merge(
            glob("{$modulesPath}/*/Providers/*ServiceProvider.php") ?: [],
            glob("{$modulesPath}/*/*ServiceProvider.php") ?: []
        );

        foreach (array_unique($files) as $file) {
            $relativePath = str_replace([app_path() . '/', '.php'], ['', ''], $file);
            $className = 'App\\' . str_replace('/', '\\', $relativePath);

            if (class_exists($className)) {
                $this->app->register($className);
            }
        }
    }

    private function loadModuleRoutes(): void
    {
        $modulesPath = app_path('Modules');

        if (!is_dir($modulesPath)) {
            return;
        }

        foreach (glob("{$modulesPath}/*/routes.php") as $routeFile) {
            require $routeFile;
        }
    }
}
