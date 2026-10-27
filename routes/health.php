<?php

use Illuminate\Support\Facades\Route;
use Zzktz\LaravelHealth\Http\Controllers\HealthController;

Route::middleware(config('health.middleware', []))->group(function () {
    Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
    Route::get('/health/ready', [HealthController::class, 'ready'])->name('health.ready');
    Route::get('/health', [HealthController::class, 'show'])->name('health.show');
});
