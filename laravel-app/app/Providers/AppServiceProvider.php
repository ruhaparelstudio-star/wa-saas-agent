<?php

namespace App\Providers;

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
    }

    private function registerModuleProviders(): void
    {
        $modulesPath = app_path('Modules');

        if (!is_dir($modulesPath)) {
            return;
        }

        foreach (glob("{$modulesPath}/*/Providers/*ServiceProvider.php") as $file) {
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
            Route::middleware('api')->group($routeFile);
        }
    }
}
