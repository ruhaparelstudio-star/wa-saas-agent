<?php

use App\Modules\Shared\Http\Controllers\HealthController;
use App\Modules\Shared\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// PRINSIP 13 — WA Gateway contract: inbound webhook (WA → Laravel)
Route::post('/webhook/inbound', [WebhookController::class, 'inbound']);

Route::prefix('health')->group(function () {
    Route::get('/', [HealthController::class, 'index']);
    Route::get('/db', [HealthController::class, 'database']);
    Route::get('/redis', [HealthController::class, 'redis']);
    Route::get('/queue', [HealthController::class, 'queue']);
    Route::get('/wa-gateway', [HealthController::class, 'waGateway']);
});
