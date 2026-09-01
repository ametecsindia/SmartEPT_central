<?php

use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\PublicController;
use App\Http\Controllers\Api\UpdateController;
use Illuminate\Support\Facades\Route;

// Public pricing feed for the landing page (no auth, cached, rate-limited).
Route::get('v1/public/plans', [PublicController::class, 'plans'])->middleware('throttle:120,1');

// R3-7 public sales surfaces: lead capture (landing form) + live coupon check.
Route::post('v1/public/leads', [PublicController::class, 'lead'])->middleware('throttle:10,1');
Route::post('v1/public/coupon-check', [PublicController::class, 'couponCheck'])->middleware('throttle:30,1');

// Master-prompt pass (16-Jul): exclusive-offer catch + CMS-driven landing content.
Route::post('v1/public/exclusive-offer', [PublicController::class, 'exclusiveOffer'])->middleware('throttle:20,1');
Route::get('v1/public/content', [PublicController::class, 'content'])->middleware('throttle:120,1');

/**
 * Phone-home API for SmartEPT product servers.
 * Licence metadata ONLY — the hard wall. Rate-limited.
 */
Route::prefix('v1/license')->middleware('throttle:60,1')->group(function () {
    Route::post('validate', [LicenseController::class, 'validateKey']);
    Route::post('device/activate', [LicenseController::class, 'activateDevice']);
    Route::post('device/deactivate', [LicenseController::class, 'deactivateDevice']);
});

/**
 * Self-update feed for on-prem SmartEPT servers (Ejaz, 1-Sep-2026).
 * "Check for Update" on a client's Licence screen lands here. Licence-gated,
 * metadata only; the package is fetched with a short-lived one-time token so
 * the licence key never appears in a URL or an access log.
 */
Route::prefix('v1/updates')->middleware('throttle:60,1')->group(function () {
    Route::post('check', [UpdateController::class, 'check']);
    Route::get('download/{token}', [UpdateController::class, 'download'])->middleware('throttle:20,1');
});
