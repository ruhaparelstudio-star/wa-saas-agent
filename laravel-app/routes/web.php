<?php

use App\Http\Controllers\ExportController;
use App\Http\Controllers\GoogleOAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/up', fn () => response('OK', 200))->name('health');

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

// Tiny poll endpoint used by the Filament tenant panel to detect new handoff
// records so a chime can be played in the browser. Returns only the ID — no
// PII leaks, no joins, cheap to call every 15s.
Route::get('/internal/handoff-latest-id', function () {
    $user = auth()->user();
    if (!$user || empty($user->tenant_id)) {
        return response()->json(['id' => null], 200);
    }

    $row = \Illuminate\Support\Facades\DB::table('handoff_records')
        ->where('tenant_id', $user->tenant_id)
        ->whereIn('status', ['pending', 'in_progress'])
        ->orderByDesc('created_at')
        ->limit(1)
        ->first(['id']);

    return response()->json(['id' => $row?->id], 200);
})->middleware(['auth'])->name('internal.handoff-latest-id');
