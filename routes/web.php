<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Client;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

// ---------- Public: landing ----------
Route::get('/', fn () => response()->file(public_path('landing.html'), ['Cache-Control' => 'no-cache, no-store, must-revalidate']));
Route::get('/cms-preview', [\App\Http\Controllers\LandingController::class, 'show'])->middleware(\App\Http\Middleware\NoIndex::class); // CMS draft preview (Task 4) — '/' stays static until publish
Route::get('/thank-you', [\App\Http\Controllers\LandingController::class, 'thanks']); // conversion / thank-you page
Route::get('/robots.txt', [\App\Http\Controllers\LandingController::class, 'robots']);
Route::get('/sitemap.xml', [\App\Http\Controllers\LandingController::class, 'sitemap']);
Route::get('/llms.txt', [\App\Http\Controllers\LandingController::class, 'llms']);

// ---------- Public: legal & contact (linked from landing + portal footers) ----------
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/refunds', 'legal.refunds')->name('legal.refunds');
Route::view('/contact', 'legal.contact')->name('legal.contact');
Route::view('/security', 'legal.security')->name('legal.security');
Route::view('/system-requirements', 'legal.system-requirements')->name('legal.system-requirements');

// ---------- Client portal (Phase 3): auth ----------
Route::get('/client/login', [Client\AuthController::class, 'showAuth'])->name('client.login')->middleware(\App\Http\Middleware\NoIndex::class);
Route::get('/client/signup', [Client\AuthController::class, 'showAuth'])->middleware(\App\Http\Middleware\NoIndex::class);
Route::middleware('throttle:10,1')->group(function () {
    Route::post('/client/login', [Client\AuthController::class, 'login']);
    Route::post('/client/signup/request-otp', [Client\AuthController::class, 'signupRequestOtp'])->middleware('throttle:5,1');
    Route::post('/client/signup/verify', [Client\AuthController::class, 'signupVerify']);
    Route::post('/client/forgot/request-otp', [Client\AuthController::class, 'forgotRequestOtp'])->middleware('throttle:5,1');
    Route::post('/client/forgot/reset', [Client\AuthController::class, 'forgotReset']);
});
Route::post('/client/logout', [Client\AuthController::class, 'logout']);

// ---------- Client portal: tenant self-service (auth-walled) ----------
Route::middleware(['client.auth', \App\Http\Middleware\NoIndex::class])->prefix('client')->group(function () {
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
        Route::post('licences/{licence}/upgrade', [Client\PortalApiController::class, 'upgrade']); // Phase 5: pro-rata mid-period upgrade
        Route::get('account/billing', [Client\PortalApiController::class, 'billingProfile']);
        Route::put('account/billing', [Client\PortalApiController::class, 'updateBillingProfile']);
        Route::post('account/password', [Client\PortalApiController::class, 'changePassword']);
        Route::get('tickets', [Client\PortalApiController::class, 'tickets']);
        Route::post('tickets', [Client\PortalApiController::class, 'createTicket']);
    });
});

// ---------- Public: BUY front door (Phase 1 money rework — pay first, account after) ----------
Route::get('/buy', [\App\Http\Controllers\BuyController::class, 'show'])->middleware(\App\Http\Middleware\NoIndex::class);
Route::post('/buy/order', [\App\Http\Controllers\BuyController::class, 'order'])->middleware('throttle:10,1');
Route::post('/buy/quote', [\App\Http\Controllers\BuyController::class, 'quote'])->middleware('throttle:6,1'); // Phase 3: self-serve quotation

// ---------- Public: checkout ----------
Route::get('/pay/{number}/{token}', [CheckoutController::class, 'show'])->middleware(\App\Http\Middleware\NoIndex::class);
Route::get('/pay/{number}/{token}/quote', [CheckoutController::class, 'quotePrint'])->middleware(\App\Http\Middleware\NoIndex::class); // Phase 3: printable quotation, token-secured
Route::get('/pay/{number}/{token}/proforma', [CheckoutController::class, 'quotePrint'])->middleware(\App\Http\Middleware\NoIndex::class); // 6-Aug: same doc as proforma invoice for plain orders
Route::post('/pay/{number}/{token}/razorpay-order', [CheckoutController::class, 'createRazorpayOrder']);
Route::post('/pay/{number}/{token}/razorpay-callback', [CheckoutController::class, 'razorpayCallback']);
Route::get('/pay/{number}/{token}/stripe', [CheckoutController::class, 'stripeRedirect'])->middleware(\App\Http\Middleware\NoIndex::class);

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
    Route::get('/landing', [Admin\LandingAdminController::class, 'page'])->middleware('admin.role:super'); // Landing CMS editor
    Route::get('/invoices/{invoice}/print', [Admin\InvoicePrintController::class, 'show']);
    Route::get('/orders/{order}/quote-print', [Admin\InvoicePrintController::class, 'quote']);
    Route::get('/orders/{order}/proforma', [Admin\InvoicePrintController::class, 'proforma']); // 6-Aug: proforma invoice for payment-pending orders

    // 7-Aug: whole api surface goes through the permissions matrix (module-level,
    // editable in Users & Roles). Inner admin.role:... lists remain as fallback
    // for any path the matrix does not map.
    Route::prefix('api')->middleware('admin.role')->group(function () {
        // Read endpoints — all roles
        Route::get('dashboard', [Admin\DashboardApiController::class, 'stats']);
        Route::get('tenants', [Admin\TenantApiController::class, 'index']);
        Route::get('tenants/{tenant}', [Admin\TenantApiController::class, 'show']);
        Route::get('licences', [Admin\LicenceApiController::class, 'index']);
        Route::get('licences/{licence}/devices', [Admin\LicenceApiController::class, 'devices']); // 6-Aug: device seats (free a formatted/replaced PC)
        Route::get('licences/{licence}/history', [Admin\LicenceApiController::class, 'history']); // 6-Aug: full licence timeline
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
        Route::get('tickets', [Admin\SupportTicketController::class, 'index']); // support desk
        Route::get('tickets/{ticket}', [Admin\SupportTicketController::class, 'show']); // full thread + timeline (bug #2)
        Route::post('quote', [Admin\BillingApiController::class, 'quote']);

        // Write endpoints — super + sales
        Route::middleware('admin.role:super,sales')->group(function () {
            Route::put('tickets/{ticket}', [Admin\SupportTicketController::class, 'update']);
            // Accountant CSV exports (GET downloads).
            Route::get('reports/gst-register', [Admin\ReportExportController::class, 'gstRegister']);
            Route::get('reports/collections', [Admin\ReportExportController::class, 'collections']);
            Route::get('reports/outstanding', [Admin\ReportExportController::class, 'outstanding']);

            Route::post('tenants', [Admin\TenantApiController::class, 'store']);
            Route::put('tenants/{tenant}', [Admin\TenantApiController::class, 'update']);
            Route::post('licences', [Admin\LicenceApiController::class, 'store']);
            Route::post('licences/{licence}/action', [Admin\LicenceApiController::class, 'action']);
            Route::post('licences/{licence}/license-file', [Admin\LicenceApiController::class, 'licenseFile']);
            Route::put('licences/{licence}', [Admin\LicenceApiController::class, 'update']);
            Route::put('licences/{licence}/limit', [Admin\LicenceApiController::class, 'updateLimit']);
            Route::post('licences/{licence}/deactivate-device', [Admin\LicenceApiController::class, 'deactivateDevice']);
            Route::post('licences/{licence}/shift-machine', [Admin\LicenceApiController::class, 'shiftMachine']); // 6-Aug: shift licence to a new machine ID (damaged/replaced PC), history kept
            Route::post('orders', [Admin\BillingApiController::class, 'createOrder']);
            Route::post('prospect-quote', [Admin\BillingApiController::class, 'prospectQuote']); // Phase 3: one-screen quote for a NEW prospect
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
            Route::put('plans/{plan}/volume-tiers', [Admin\ConfigApiController::class, 'saveVolumeTiers']);
            Route::put('plans/{plan}/perpetual-bands', [Admin\ConfigApiController::class, 'savePerpetualBands']);
            // 11-Aug: garbage cleanup — delete quotes/unpaid orders + invoices (super only, audit-logged)
            Route::delete('orders/{order}', [Admin\BillingApiController::class, 'deleteOrder']);
            Route::delete('invoices/{invoice}', [Admin\BillingApiController::class, 'deleteInvoice']);
            Route::get('settings', [Admin\ConfigApiController::class, 'settings']);
            Route::put('settings', [Admin\ConfigApiController::class, 'updateSettings']);
            Route::post('logs/purge', [Admin\ConfigApiController::class, 'purgeLogs']); // 6-Aug: category+date-range log cleanup (verified dailies roll up to monthly summaries)
            // ----- Landing CMS (super only) -----
            Route::get('landing/sections', [Admin\LandingAdminController::class, 'sections']);
            Route::put('landing/sections/{section}', [Admin\LandingAdminController::class, 'updateSection']);
            Route::post('landing/reorder', [Admin\LandingAdminController::class, 'reorder']);
            Route::post('landing/publish', [Admin\LandingAdminController::class, 'publish']);
            Route::get('landing/versions', [Admin\LandingAdminController::class, 'versions']);
            Route::post('landing/versions/{version}/rollback', [Admin\LandingAdminController::class, 'rollback']);
            Route::get('landing/media', [Admin\LandingAdminController::class, 'media']);
            Route::post('landing/media', [Admin\LandingAdminController::class, 'mediaUpload']);
            Route::post('landing/media/scan', [Admin\LandingAdminController::class, 'mediaScan']);
            Route::post('landing/media/extract', [Admin\LandingAdminController::class, 'mediaExtract']);
            Route::put('landing/media/{media}', [Admin\LandingAdminController::class, 'mediaUpdate']);
            Route::delete('landing/media/{media}', [Admin\LandingAdminController::class, 'mediaDelete']);
            Route::get('landing/seo', [Admin\LandingAdminController::class, 'seo']);
            Route::put('landing/seo', [Admin\LandingAdminController::class, 'saveSeo']);
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
            Route::post('scheduler/run-now', [Admin\DiagnosticsController::class, 'schedulerRunNow']);   // 6-Aug: run due jobs from the panel
            Route::post('scheduler/install', [Admin\DiagnosticsController::class, 'schedulerInstall']);  // 6-Aug: one-click auto-scheduler (Windows Task Scheduler / Linux cron)
            Route::get('scheduler/instructions', [Admin\DiagnosticsController::class, 'schedulerInstructions']); // 6-Aug: all-hosting setup options

            // Managed installer catalogue — upload/publish agent (Win/Mac/Linux) + server.
            Route::get('download-artifacts', [Admin\DownloadApiController::class, 'index']);
            Route::post('download-artifacts', [Admin\DownloadApiController::class, 'save']);
            Route::post('download-artifacts/{artifact}', [Admin\DownloadApiController::class, 'save']);
            Route::delete('download-artifacts/{artifact}', [Admin\DownloadApiController::class, 'destroy']);
            Route::post('download-limits', [Admin\DownloadApiController::class, 'saveLimits']);

            // Admin staff accounts + roles (SmartPRS-style).
            Route::get('admin-users', [Admin\AdminUserController::class, 'index']);
            Route::post('admin-users', [Admin\AdminUserController::class, 'store']);
            Route::put('admin-users/{adminUser}', [Admin\AdminUserController::class, 'update']);
            Route::post('admin-users/{adminUser}/reset-password', [Admin\AdminUserController::class, 'resetPassword']);
            Route::delete('admin-users/{adminUser}', [Admin\AdminUserController::class, 'destroy']);
            // 7-Aug: editable role-permissions matrix (custom roles, module level)
            Route::get('role-permissions', [Admin\AdminUserController::class, 'rolePermissions']);
            Route::put('role-permissions', [Admin\AdminUserController::class, 'saveRolePermissions']);
        });
    });
});
