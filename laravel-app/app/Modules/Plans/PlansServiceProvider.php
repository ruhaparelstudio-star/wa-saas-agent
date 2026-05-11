<?php

namespace App\Modules\Plans;

use App\Modules\Plans\Services\FeatureGateService;
use Illuminate\Support\ServiceProvider;

class PlansServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FeatureGateService::class);
    }

    public function boot(): void
    {
        //
    }
}
