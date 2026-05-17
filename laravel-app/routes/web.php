<?php

use App\Http\Controllers\ExportController;
use App\Http\Controllers\GoogleOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/app/calendar/oauth/redirect', [GoogleOAuthController::class, 'redirect'])
         ->name('calendar.oauth.redirect');
    Route::get('/app/calendar/oauth/callback', [GoogleOAuthController::class, 'callback'])
         ->name('calendar.oauth.callback');
});

Route::prefix('app/export')->middleware(['auth', 'throttle:export'])->group(function () {
    Route::get('/bookings', [ExportController::class, 'bookings'])->name('export.bookings');
    Route::get('/invoices', [ExportController::class, 'invoices'])->name('export.invoices');
    Route::get('/leads',    [ExportController::class, 'leads'])->name('export.leads');
});
