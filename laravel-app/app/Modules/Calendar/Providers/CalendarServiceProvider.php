<?php

namespace App\Modules\Calendar\Providers;

use App\Modules\Calendar\Adapters\GoogleCalendarAdapter;
use App\Modules\Calendar\Adapters\NullCalendarAdapter;
use App\Modules\Shared\Contracts\CalendarProviderInterface;
use Illuminate\Support\ServiceProvider;

class CalendarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CalendarProviderInterface::class, function ($app) {
            $provider = config('services.calendar.provider', 'null');

            return match ($provider) {
                'google' => $app->make(GoogleCalendarAdapter::class),
                default  => $app->make(NullCalendarAdapter::class),
            };
        });
    }
}
