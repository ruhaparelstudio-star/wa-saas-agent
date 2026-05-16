<?php

namespace App\Modules\FollowUp\Providers;

use App\Modules\FollowUp\Console\ScheduleFollowUpsCommand;
use Illuminate\Support\ServiceProvider;

class FollowUpServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ScheduleFollowUpsCommand::class]);
        }
    }
}
