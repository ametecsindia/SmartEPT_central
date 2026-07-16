<?php

use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\PublicController;
use Illuminate\Support\Facades\Route;

// Public pricing feed for the landing page (no auth, cached, rate-limited).
Route::get('v1/public/plans', [PublicController::class, 'plans'])->middleware('throttle:120,1');

// R3-7 public sales surfaces: lead capture (landing form) + live coupon check.
Route::post('v1/public/leads', [PublicController::class, 'lead'])->middleware('throttle:10,1');
Route::post('v1/public/coupon-check', [PublicController::class, 'couponCheck'])->middleware('throttle:30,1');

/**
 * Phone-home API for SmartEPT product servers.
 * Licence metadata ONLY — the hard wall. Rate-limited.
 */
Route::prefix('v1/license')->middleware('throttle:60,1')->group(function () {
    Route::post('validate', [LicenseController::class, 'validateKey']);
    Route::post('device/activate', [LicenseController::class, 'activateDevice']);
    Route::post('device/deactivate', [LicenseController::class, 'deactivateDevice']);
});
