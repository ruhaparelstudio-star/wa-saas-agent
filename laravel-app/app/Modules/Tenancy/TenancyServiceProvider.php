<?php

namespace App\Modules\Tenancy;

use App\Modules\Tenancy\Services\ActivationService;
use App\Modules\Tenancy\Services\TenantService;
use Illuminate\Support\ServiceProvider;

class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActivationService::class);
        $this->app->singleton(TenantService::class);
    }

    public function boot(): void
    {
        //
    }
}
