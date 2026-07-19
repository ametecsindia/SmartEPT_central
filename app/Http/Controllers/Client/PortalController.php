<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Controller;
use App\Models\DownloadArtifact;
use App\Models\DownloadLog;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Setting;
use App\Services\ProductProvisioner;

/**
 * /client portal shell + tenant-scoped printable documents.
 * Every record is checked against the logged-in user's tenant — a client can
 * never open another company's invoice or quotation.
 */
class PortalController extends Controller
{
    public function index()
    {
        return view('client.portal', ['user' => auth('client')->user()->load('tenant')]);
    }

    /**
     * GET /client/console — one-click SSO into the tenant's hosted SmartEPT
     * console. Mints a short-lived signed ticket and redirects the browser to
     * the product app's /admin?sso=… which trades it for a signed-in session.
     */
    public function console(ProductProvisioner $provisioner)
    {
        $tenant = auth('client')->user()->tenant;
        $url = $provisioner->ssoUrl($tenant);
        abort_unless($url, 404, 'Your hosted console is still being set up — WhatsApp 90000 98877 if this takes more than a day.');

        return redirect()->away($url);
    }

    public function invoicePrint(Invoice $invoice)
    {
        abort_unless($invoice->tenant_id === auth('client')->user()->tenant_id, 404);

        return view('invoice-print', [
            'invoice' => $invoice->load('tenant', 'order'),
            'company' => $this->company(),
        ]);
    }

    public function quotePrint(Order $order)
    {
        abort_unless($order->tenant_id === auth('client')->user()->tenant_id && $order->quote_number, 404);

        return view('quote-print', [
            'order' => $order->load('tenant'),
            'payUrl' => url('/pay/' . $order->number . '/' . CheckoutController::token($order)),
            'company' => $this->company(),
        ]);
    }

    /** Old download links / API keys → new per-OS slugs. */
    public const LEGACY_ALIAS = [
        'agent' => 'agent-windows',
        'admin' => 'server-windows',
    ];

    /** Build-script drop patterns per slug — the fallback when no file is attached in the admin. */
    private const LEGACY_GLOB = [
        'agent-windows'  => ['SmartEPT-Agent-Setup*.exe', 'SmartEPT-Agent*.exe', 'SmartEPT-Agent*.zip'],
        'agent-mac'      => ['SmartEPT-Agent*.dmg', 'SmartEPT-Agent*.pkg'],
        'agent-linux'    => ['SmartEPT-Agent*.deb', 'SmartEPT-Agent*.AppImage', 'SmartEPT-Agent*.tar.gz'],
        'server-windows' => ['SmartEPT-Admin-Server-Setup*.exe', 'SmartEPT-Admin-Server*.exe', 'SmartEPT-Admin-Server*.zip'],
    ];

    /**
     * Resolve the installer file for a slug, or null when unavailable.
     * Rule: if a managed file is attached in the admin, the publish flag decides.
     * If no managed file is attached, fall back to whatever the BUILD-*.bat
     * scripts dropped in storage/app/downloads/ (so existing builds keep working).
     */
    public static function artifactPath(string $slug): ?string
    {
        $slug = self::LEGACY_ALIAS[$slug] ?? $slug;

        try {
            $row = DownloadArtifact::where('slug', $slug)->first();
            if ($row && $row->filename) {
                return $row->is_published ? $row->filePath() : null;
            }
        } catch (\Throwable $e) {
            // download_artifacts table not migrated yet — fall through to legacy glob.
        }

        $patterns = self::LEGACY_GLOB[$slug] ?? null;
        if (! $patterns) {
            return null;
        }

        $files = [];
        foreach ($patterns as $p) {
            $files = array_merge($files, glob(storage_path('app/downloads/' . $p)) ?: []);
        }
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    /** GET /client/download/{slug} — auth-walled installer download (quota-limited + logged). */
    public function download(string $slug)
    {
        $slug = self::LEGACY_ALIAS[$slug] ?? $slug;
        $path = self::artifactPath($slug);

        abort_unless($path, 404, 'This installer is not published yet — WhatsApp 90000 98877 and we will send it to you.');

        $tenant = auth('client')->user()->tenant;
        if ($tenant) {
            $q = DownloadLog::quotaFor($tenant);

            // Per-app, per-day cap.
            $today = DownloadLog::where('tenant_id', $tenant->id)
                ->where('artifact_slug', $slug)
                ->whereDate('created_at', now()->toDateString())
                ->count();
            abort_if($today >= $q['daily'], 429,
                "Daily download limit reached for this installer ({$q['daily']} per day). Please try again tomorrow — "
                . 'or WhatsApp 90000 98877 if you need it sooner.');

            // Total downloads this calendar month.
            $month = DownloadLog::where('tenant_id', $tenant->id)
                ->where('created_at', '>=', now()->startOfMonth())
                ->count();
            abort_if($month >= $q['monthly'], 429,
                "Monthly download limit reached ({$q['monthly']} per month). WhatsApp 90000 98877 and we'll sort you out.");

            $row = DownloadArtifact::where('slug', $slug)->first();
            DownloadLog::create([
                'tenant_id'      => $tenant->id,
                'tenant_name'    => $tenant->company_name,
                'artifact_slug'  => $slug,
                'artifact_title' => $row->title ?? $slug,
                'platform'       => $row->platform ?? null,
                'ip'             => request()->ip(),
            ]);
        }

        return response()->download($path);
    }

    private function company(): array
    {
        return [
            'name' => Setting::get('company_name', 'Ametecs India Private Limited'),
            'address' => Setting::get('company_address', ''),
            'gstin' => Setting::get('company_gstin', ''),
            'phone' => Setting::get('company_phone', ''),
            'email' => Setting::get('company_email', ''),
            // Seller state + bank/UPI block for the GST tax invoice.
            'state' => \App\Support\IndianStates::placeOfSupply(Setting::get('seller_state_code', '36')),
            'bank_account_name' => Setting::get('bank_account_name', ''),
            'bank_name' => Setting::get('bank_name', ''),
            'bank_branch' => Setting::get('bank_branch', ''),
            'bank_account_no' => Setting::get('bank_account_no', ''),
            'bank_ifsc' => Setting::get('bank_ifsc', ''),
            'upi_id' => Setting::get('upi_id', ''),
        ];
    }
}
