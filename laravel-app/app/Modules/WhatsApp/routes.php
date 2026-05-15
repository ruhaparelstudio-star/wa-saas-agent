<?php

use App\Modules\WhatsApp\Http\Controllers\WaSessionCallbackController;
use Illuminate\Support\Facades\Route;

// Internal callback from wa-gateway (Baileys → Laravel) — PRINSIP 6 & 13
Route::middleware('internal.secret')
    ->post('/internal/wa-session-callback/{account_id}', [WaSessionCallbackController::class, 'handle'])
    ->name('wa.session.callback');
