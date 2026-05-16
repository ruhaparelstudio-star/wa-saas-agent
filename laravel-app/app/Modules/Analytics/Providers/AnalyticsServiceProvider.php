<?php

namespace App\Modules\Analytics\Providers;

use App\Modules\Analytics\Services\AnalyticsService;
use Illuminate\Support\ServiceProvider;

class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AnalyticsService::class);
    }
}
