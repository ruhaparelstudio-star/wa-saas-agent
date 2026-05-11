<?php

use App\Modules\Auth\Http\Middleware\SuperadminOnly;
use App\Modules\Tenancy\Http\Controllers\ActivationController;
use App\Modules\Tenancy\Http\Controllers\SuperadminTenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/superadmin/tenants')
    ->middleware(['auth:sanctum', SuperadminOnly::class])
    ->group(function () {
        Route::get('/', [SuperadminTenantController::class, 'index']);
        Route::post('/', [SuperadminTenantController::class, 'store']);
        Route::get('/{id}', [SuperadminTenantController::class, 'show']);
        Route::put('/{id}/status', [SuperadminTenantController::class, 'updateStatus']);
        Route::post('/{id}/resend-activation', [SuperadminTenantController::class, 'resendActivation']);
    });

Route::prefix('activate')->group(function () {
    Route::get('/{token}', [ActivationController::class, 'show']);
    Route::post('/{token}', [ActivationController::class, 'activate']);
});
