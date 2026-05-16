<?php

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
