<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Client;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// ---------- Public: landing ----------
Route::get('/', fn () => response()->file(public_path('landing.html')));

// ---------- Public: legal & contact (linked from landing + portal footers) ----------
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/refunds', 'legal.refunds')->name('legal.refunds');
Route::view('/contact', 'legal.contact')->name('legal.contact');

// ---------- Client portal (Phase 3): auth ----------
Route::get('/client/login', [Client\AuthController::class, 'showAuth'])->name('client.login');
Route::get('/client/signup', [Client\AuthController::class, 'showAuth']);
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/client/login', [Client\AuthController::class, 'login']);
    Route::post('/client/signup/request-otp', [Client\AuthController::class, 'signupRequestOtp'])->middleware('throttle:5,1');
    Route::post('/client/signup/verify', [Client\AuthController::class, 'signupVerify']);
    Route::post('/client/forgot/request-otp', [Client\AuthController::class, 'forgotRequestOtp'])->middleware('throttle:5,1');
    Route::post('/client/forgot/reset', [Client\AuthController::class, 'forgotReset']);
});
Route::post('/client/logout', [Client\AuthController::class, 'logout']);

// ---------- Client portal: tenant self-service (auth-walled) ----------
Route::middleware('client.auth')->prefix('client')->group(function () {
    Route::get('/', [Client\PortalController::class, 'index']);
    Route::get('/console', [Client\PortalController::class, 'console']); // one-click SSO into hosted console
    Route::get('/invoices/{invoice}/print', [Client\PortalController::class, 'invoicePrint']);
    Route::get('/orders/{order}/quote-print', [Client\PortalController::class, 'quotePrint']);
    Route::get('/download/{artifact}', [Client\PortalController::class, 'download']); // R3 installers

    Route::prefix('api')->group(function () {
        Route::get('overview', [Client\PortalApiController::class, 'overview']);
        Route::get('downloads', [Client\PortalApiController::class, 'downloads']); // R3 installers
        Route::get('licences', [Client\PortalApiController::class, 'licences']);
        Route::get('orders', [Client\PortalApiController::class, 'orders']);
        Route::get('invoices', [Client\PortalApiController::class, 'invoices']);
        Route::get('plans', [Client\PortalApiController::class, 'plans']);
        Route::get('storage', [Client\PortalApiController::class, 'storage']);
        Route::post('quote', [Client\PortalApiController::class, 'quote']);
        Route::post('orders', [Client\PortalApiController::class, 'createOrder']);
        Route::post('licences/{licence}/renew', [Client\PortalApiController::class, 'renew']);
        Route::get('account/billing', [Client\PortalApiController::class, 'billingProfile']);
        Route::put('account/billing', [Client\PortalApiController::class, 'updateBillingProfile']);
        Route::post('account/password', [Client\PortalApiController::class, 'changePassword']);
    });
});

// ---------- Public: checkout ----------
Route::get('/pay/{number}/{token}', [CheckoutController::class, 'show']);
Route::post('/pay/{number}/{token}/razorpay-order', [CheckoutController::class, 'createRazorpayOrder']);
Route::post('/pay/{number}/{token}/razorpay-callback', [CheckoutController::class, 'razorpayCallback']);
Route::get('/pay/{number}/{token}/stripe', [CheckoutController::class, 'stripeRedirect']);

// ---------- Public: gateway webhooks (CSRF-exempt in bootstrap/app.php) ----------
Route::post('/webhooks/razorpay', [WebhookController::class, 'razorpay']);
Route::post('/webhooks/stripe', [WebhookController::class, 'stripe']);

// ---------- Admin auth ----------
Route::get('/admin/login', [Admin\AuthController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [Admin\AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/admin/logout', [Admin\AuthController::class, 'logout']);

// ---------- Admin console ----------
Route::middleware('admin.auth')->prefix('admin')->group(function () {
    Route::get('/', [Admin\ConsoleController::class, 'index']);
    Route::get('/invoices/{invoice}/print', [Admin\InvoicePrintController::class, 'show']);
    Route::get('/orders/{order}/quote-print', [Admin\InvoicePrintController::class, 'quote']);

    Route::prefix('api')->group(function () {
        // Read endpoints — all roles
        Route::get('dashboard', [Admin\DashboardApiController::class, 'stats']);
        Route::get('tenants', [Admin\TenantApiController::class, 'index']);
        Route::get('tenants/{tenant}', [Admin\TenantApiController::class, 'show']);
        Route::get('licences', [Admin\LicenceApiController::class, 'index']);
        Route::get('orders', [Admin\BillingApiController::class, 'orders']);
        Route::get('credit-clients', [Admin\BillingApiController::class, 'creditClients']); // §10 credit table
        Route::get('invoices', [Admin\BillingApiController::class, 'invoices']);
        Route::get('trials', [Admin\BillingApiController::class, 'trials']);
        Route::get('storage', [Admin\BillingApiController::class, 'storage']);
        Route::get('plans', [Admin\ConfigApiController::class, 'plans']);
        Route::get('webhooks', [Admin\BillingApiController::class, 'webhooks']);
        Route::get('audit', [Admin\ConfigApiController::class, 'audit']);
        Route::get('leads', [Admin\LeadApiController::class, 'index']);       // R3-7
        Route::get('coupons', [Admin\CouponApiController::class, 'index']);   // R3-7
        Route::post('quote', [Admin\BillingApiController::class, 'quote']);

        // Write endpoints — super + sales
        Route::middleware('admin.role:super,sales')->group(function () {
            // Accountant CSV exports (GET downloads).
            Route::get('reports/gst-register', [Admin\ReportExportController::class, 'gstRegister']);
            Route::get('reports/collections', [Admin\ReportExportController::class, 'collections']);
            Route::get('reports/outstanding', [Admin\ReportExportController::class, 'outstanding']);

            Route::post('tenants', [Admin\TenantApiController::class, 'store']);
            Route::put('tenants/{tenant}', [Admin\TenantApiController::class, 'update']);
            Route::post('licences', [Admin\LicenceApiController::class, 'store']);
            Route::post('licences/{licence}/action', [Admin\LicenceApiController::class, 'action']);
            Route::put('licences/{licence}/limit', [Admin\LicenceApiController::class, 'updateLimit']);
            Route::post('licences/{licence}/deactivate-device', [Admin\LicenceApiController::class, 'deactivateDevice']);
            Route::post('orders', [Admin\BillingApiController::class, 'createOrder']);
            Route::post('setup-invoice', [Admin\BillingApiController::class, 'raiseSetupInvoice']);
            Route::post('orders/{order}/mark-paid', [Admin\BillingApiController::class, 'markPaid']);
            Route::post('orders/{order}/record-balance', [Admin\BillingApiController::class, 'recordBalance']); // §10 instalments
            Route::post('orders/{order}/approve-quote', [Admin\BillingApiController::class, 'approveQuote']);
            Route::post('trials/{tenant}/extend', [Admin\BillingApiController::class, 'extendTrial']);
            Route::post('storage', [Admin\BillingApiController::class, 'recordStorage']);
            // R3-7 sales ops
            Route::post('leads', [Admin\LeadApiController::class, 'store']);
            Route::put('leads/{lead}', [Admin\LeadApiController::class, 'update']);
            Route::post('coupons', [Admin\CouponApiController::class, 'store']);
            Route::put('coupons/{coupon}', [Admin\CouponApiController::class, 'update']);
        });

        // Super only
        Route::middleware('admin.role:super')->group(function () {
            Route::put('plans/{plan}', [Admin\ConfigApiController::class, 'updatePlan']);
            Route::get('settings', [Admin\ConfigApiController::class, 'settings']);
            Route::put('settings', [Admin\ConfigApiController::class, 'updateSettings']);
        Route::post('config/test-email', [Admin\ConfigApiController::class, 'testEmail']);
        Route::get('wa-templates', [Admin\WaTemplateController::class, 'index']);
        Route::post('wa-templates', [Admin\WaTemplateController::class, 'store']);
        Route::put('wa-templates/{waTemplate}', [Admin\WaTemplateController::class, 'update']);
        Route::delete('wa-templates/{waTemplate}', [Admin\WaTemplateController::class, 'destroy']);
        Route::post('wa-templates/{waTemplate}/test', [Admin\WaTemplateController::class, 'test']);

            // Help -> Troubleshooting: live System Health + in-app log viewer
            // (Ametecs troubleshooting-in-app standard — non-technical self-service).
            Route::get('diagnostics', [Admin\DiagnosticsController::class, 'checks']);
            Route::get('logs', [Admin\DiagnosticsController::class, 'logs']);

            // Managed installer catalogue — upload/publish agent (Win/Mac/Linux) + server.
            Route::get('download-artifacts', [Admin\DownloadApiController::class, 'index']);
            Route::post('download-artifacts', [Admin\DownloadApiController::class, 'save']);
            Route::post('download-artifacts/{artifact}', [Admin\DownloadApiController::class, 'save']);
            Route::delete('download-artifacts/{artifact}', [Admin\DownloadApiController::class, 'destroy']);
            Route::post('download-limits', [Admin\DownloadApiController::class, 'saveLimits']);
        });
    });
});
