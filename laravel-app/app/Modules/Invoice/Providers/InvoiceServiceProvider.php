<?php

namespace App\Modules\Invoice\Providers;

use App\Modules\Invoice\Repositories\InvoiceRepository;
use App\Modules\Invoice\Services\InvoiceService;
use Illuminate\Support\ServiceProvider;

class InvoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(InvoiceRepository::class);
        $this->app->singleton(InvoiceService::class);
    }
}
