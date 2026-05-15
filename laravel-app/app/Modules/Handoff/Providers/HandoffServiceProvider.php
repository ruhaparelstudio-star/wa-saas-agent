<?php

namespace App\Modules\Handoff\Providers;

use App\Modules\Handoff\Repositories\HandoffRepository;
use App\Modules\Handoff\Services\HandoffService;
use Illuminate\Support\ServiceProvider;

class HandoffServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HandoffRepository::class);
        $this->app->singleton(HandoffService::class);
    }

    public function boot(): void
    {
        //
    }
}
