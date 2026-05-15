<?php

namespace App\Modules\Booking\Providers;

use App\Modules\Booking\Repositories\BookingRepository;
use Illuminate\Support\ServiceProvider;

class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(BookingRepository::class);
    }
}
