<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\CheckoutController;
use App\Http\Controllers\Controller;
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

    /**
     * R3 installers: newest published installer file for an artifact, or null.
     * Files live in storage/app/downloads/ — the BUILD-*.bat scripts drop them here.
     */
    public static function artifactPath(string $artifact): ?string
    {
        $patterns = [
            'agent' => ['SmartEPT-Agent-Setup*.exe', 'SmartEPT-Agent*.zip'],
            'admin' => ['SmartEPT-Admin-Server-Setup*.exe', 'SmartEPT-Admin-Server*.zip'],
        ][$artifact] ?? null;

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

    /** GET /client/download/{artifact} — auth-walled installer download. */
    public function download(string $artifact)
    {
        abort_unless(in_array($artifact, ['agent', 'admin'], true), 404);

        $path = self::artifactPath($artifact);

        abort_unless($path, 404, 'This installer is not published yet — WhatsApp 90000 98877 and we will send it to you.');

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
