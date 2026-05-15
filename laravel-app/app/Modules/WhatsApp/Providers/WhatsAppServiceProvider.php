<?php

namespace App\Modules\WhatsApp\Providers;

use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use App\Modules\WhatsApp\Services\WaAccountService;
use Illuminate\Support\ServiceProvider;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WaAccountRepository::class);
        $this->app->singleton(WaAccountService::class);
    }

    public function boot(): void
    {
        //
    }
}
