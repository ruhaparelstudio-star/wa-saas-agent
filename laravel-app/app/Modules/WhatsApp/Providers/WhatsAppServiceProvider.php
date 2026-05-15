<?php

namespace App\Modules\WhatsApp\Providers;

use App\Modules\WhatsApp\Repositories\WaAccountRepository;
use Illuminate\Support\ServiceProvider;

class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WaAccountRepository::class);
    }

    public function boot(): void
    {
        //
    }
}
