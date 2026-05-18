<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('followups:schedule')->hourly();
Schedule::command('wa:reconnect-sessions')->everyMinute();
Schedule::command('quality:patrol --since=15m')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();
