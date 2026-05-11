<?php

use App\Modules\Auth\Http\Middleware\SuperadminOnly;
use App\Modules\Plans\Http\Controllers\PlanController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/superadmin/tenants')
    ->middleware(['auth:sanctum', SuperadminOnly::class])
    ->group(function () {
        Route::post('/{tenantId}/assign-plan', [PlanController::class, 'assignPlan']);
    });
