<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Http\Request;

class ConfigApiController extends Controller
{
    public function plans()
    {
        return response()->json(Plan::with(['volumeTiers', 'perpetualBands'])->orderBy('sort')->get());
    }

    public function updatePlan(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'inr_annual' => ['sometimes', 'integer', 'min:0'],
            'inr_monthly' => ['sometimes', 'integer', 'min:0'],
            'usd_annual' => ['sometimes', 'numeric', 'min:0'],
            'usd_monthly' => ['sometimes', 'numeric', 'min:0'],
            'perpetual_device_inr' => ['sometimes', 'integer', 'min:0'],
            'perpetual_server_inr' => ['sometimes', 'integer', 'min:0'],
            'min_devices' => ['sometimes', 'integer', 'min:1'],
            'storage_gb' => ['sometimes', 'integer', 'min:0'],
            'features' => ['sometimes', 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $plan->update($data);
        AuditLog::write('plan.updated', $plan, ['fields' => array_keys($data)]);

        return response()->json($plan->fresh('volumeTiers'));
    }

    /** Full-control Cloud rental bands (PlanVolumeTier): replace-all editor. Super only. */
    public function saveVolumeTiers(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'tiers' => ['present', 'array'],
            'tiers.*.min_devices' => ['required', 'integer', 'min:1'],
            'tiers.*.max_devices' => ['nullable', 'integer', 'min:1'],
            'tiers.*.rate_inr_annual' => ['required', 'numeric', 'min:0'],
        ]);
        foreach ($data['tiers'] as $t) {
            if ($t['max_devices'] !== null && (int) $t['max_devices'] < (int) $t['min_devices']) {
                return response()->json(['message' => 'Max users of each band must be greater than or equal to its Min users, or left blank for the open-ended top band.'], 422);
            }
        }
        $tiers = collect($data['tiers'])->sortBy('min_devices')->values();
        \Illuminate\Support\Facades\DB::transaction(function () use ($plan, $tiers) {
            $plan->volumeTiers()->delete();
            foreach ($tiers as $t) {
                $plan->volumeTiers()->create([
                    'min_devices' => (int) $t['min_devices'],
                    'max_devices' => ($t['max_devices'] === null || $t['max_devices'] === '') ? null : (int) $t['max_devices'],
                    'rate_inr_annual' => (float) $t['rate_inr_annual'],
                ]);
            }
        });
        AuditLog::write('plan.cloud_bands.updated', $plan, ['count' => $tiers->count()]);
        \Illuminate\Support\Facades\Cache::forget('public_plans_v2');

        return response()->json($plan->fresh('volumeTiers'));
    }

    /** Full-control On-Premise Lifetime bands (PlanPerpetualBand): replace-all editor. Super only. */
    public function savePerpetualBands(Request $request, Plan $plan)
    {
        $data = $request->validate([
            'bands' => ['present', 'array'],
            'bands.*.min_users' => ['required', 'integer', 'min:1'],
            'bands.*.max_users' => ['nullable', 'integer', 'min:1'],
            'bands.*.price_inr' => ['required', 'numeric', 'min:0'],
        ]);
        foreach ($data['bands'] as $b) {
            if ($b['max_users'] !== null && (int) $b['max_users'] < (int) $b['min_users']) {
                return response()->json(['message' => 'Max users of each band must be greater than or equal to its Min users, or left blank for the open-ended top band.'], 422);
            }
        }
        $bands = collect($data['bands'])->sortBy('min_users')->values();
        \Illuminate\Support\Facades\DB::transaction(function () use ($plan, $bands) {
            $plan->perpetualBands()->delete();
            $i = 0;
            foreach ($bands as $b) {
                $plan->perpetualBands()->create([
                    'min_users' => (int) $b['min_users'],
                    'max_users' => ($b['max_users'] === null || $b['max_users'] === '') ? null : (int) $b['max_users'],
                    'price_inr' => (int) round((float) $b['price_inr']),
                    'sort' => $i++,
                ]);
            }
        });
        AuditLog::write('plan.lifetime_bands.updated', $plan, ['count' => $bands->count()]);
        \Illuminate\Support\Facades\Cache::forget('public_plans_v2');

        return response()->json($plan->fresh('perpetualBands'));
    }

    private const EDITABLE_SETTINGS = [
        'gst_rate', 'invoice_prefix', 'quote_prefix', 'order_prefix', 'company_name', 'company_address',
        'company_gstin', 'company_phone', 'company_email', 'whatsapp_number',
        'mail_host', 'mail_port', 'mail_username', 'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
        'interakt_api_url', 'interakt_api_key', 'interakt_sender_number', 'interakt_waba_id', 'interakt_status',
        'razorpay_key_id', 'razorpay_key_secret', 'razorpay_webhook_secret',
        'stripe_publishable_key', 'stripe_secret_key', 'stripe_webhook_secret',
        // Landing CMS (master prompt §5) — marketing edits at the owner's speed.
        'landing_hero_title', 'landing_hero_subtitle', 'landing_announcement',
        'landing_contact_phone', 'landing_contact_email', 'landing_testimonials',
        'sales_email',
        // SmartEPT Cloud — default hosted console URL prefilled into new Cloud clients.
        'default_console_url',
        // SmartEPT Product connection (replaces .env) — the hosted product address the
        // Central uses to provision consoles + SSO. Enter the base URL only.
        'product_base_url', 'product_provision_secret', 'product_sso_secret',
        // Pricing, billing cycles & cloud — Central -> Settings -> Pricing & Cloud
        'pricing_annual_discount_pct', 'pricing_half_yearly_discount_pct', 'pricing_cloud_multiplier',
        'pricing_setup_base_inr', 'pricing_setup_included_devices', 'pricing_setup_per_extra_inr', 'pricing_amc_pct',
        'pricing_storage_min_gb', 'pricing_storage_min_inr', 'pricing_storage_slabs',
        // Phase 4 (6-Aug-2026): international pricing + MD digest recipient.
        'usd_inr_rate', 'md_digest_email',
    ];

    private const SECRET_SETTINGS = [
        'razorpay_key_secret', 'razorpay_webhook_secret', 'stripe_secret_key', 'stripe_webhook_secret',
        'mail_password', 'interakt_api_key',
        'product_provision_secret', 'product_sso_secret',
    ];

    /** Effective defaults surfaced in the Settings form when a pricing knob is unset. */
    public const PRICING_DEFAULTS = [
        'pricing_annual_discount_pct' => 25,
        'pricing_half_yearly_discount_pct' => 10,
        'pricing_cloud_multiplier' => 1.5,
        'pricing_setup_base_inr' => 5000,
        'pricing_setup_included_devices' => 25,
        'pricing_setup_per_extra_inr' => 100,
        'pricing_amc_pct' => 18,
        'pricing_storage_min_gb' => 50,
        'pricing_storage_min_inr' => 150,
        'pricing_storage_slabs' => '[[1,500,3],[501,2048,2.5],[2049,null,2]]',
        'usd_inr_rate' => 88,
    ];

    public function settings()
    {
        $out = [];
        foreach (self::EDITABLE_SETTINGS as $key) {
            $value = Setting::get($key, self::PRICING_DEFAULTS[$key] ?? '');
            if (in_array($key, self::SECRET_SETTINGS) && $value !== '') {
                $value = '••••••••' . substr($value, -4);
            }
            $out[$key] = $value;
        }

        return response()->json($out);
    }

    public function updateSettings(Request $request)
    {
        if ($request->filled('pricing_storage_slabs')) {
            $slabs = json_decode((string) $request->input('pricing_storage_slabs'), true);
            $valid = is_array($slabs) && $slabs !== [];
            foreach ((array) $slabs as $row) {
                if (! is_array($row) || count($row) < 3 || ! is_numeric($row[0])
                    || ! ($row[1] === null || is_numeric($row[1])) || ! is_numeric($row[2])) {
                    $valid = false;
                    break;
                }
            }
            if (! $valid) {
                return response()->json(['message' => 'Storage slabs must be a JSON list of [from_gb, to_gb|null, rate], e.g. [[1,500,3],[501,2048,2.5],[2049,null,2]].'], 422);
            }
        }

        foreach ($request->only(self::EDITABLE_SETTINGS) as $key => $value) {
            if ($value === null || str_starts_with((string) $value, '••••')) {
                continue; // masked secrets unchanged
            }
            Setting::set($key, $value);
        }

        AuditLog::write('settings.updated', null, ['keys' => array_keys($request->all())]);

        // The public landing reads these through a 5-minute cache — bust it so
        // "save → refresh landing" shows the edit immediately.
        \Illuminate\Support\Facades\Cache::forget('public_content_v1');
        \Illuminate\Support\Facades\Cache::forget('public_plans_v2');

        return response()->json(['ok' => true]);
    }

    /**
     * POST /admin/api/logs/purge (Ejaz, 6-Aug-2026) — keep the log tables small
     * without losing any client's individual history.
     *
     * Category + from/to date range. Two-step: first call returns a COUNT
     * preview; confirm=1 actually deletes. Special rule for the volume driver:
     * daily 'licence.verified' rows are ROLLED UP into one monthly summary per
     * licence ("verified N days in <month>") before deletion — so every
     * licence's History stays complete forever while the table shrinks ~30x.
     * Money records (orders, invoices, payments) live in their own tables and
     * are NEVER touched here. The purge itself is audit-logged.
     */
    public function purgeLogs(Request $request)
    {
        $data = $request->validate([
            'log' => ['required', 'in:audit,mail'],
            'category' => ['nullable', 'string', 'max:60'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'confirm' => ['nullable', 'boolean'],
        ]);

        $from = \Illuminate\Support\Carbon::parse($data['from'])->startOfDay();
        $to = \Illuminate\Support\Carbon::parse($data['to'])->endOfDay();
        $cat = $data['category'] ?? 'all';

        if ($data['log'] === 'mail') {
            $q = \App\Models\MailLog::whereBetween('created_at', [$from, $to]);
        } else {
            $q = \App\Models\AuditLog::whereBetween('created_at', [$from, $to]);
            if ($cat === 'licence.verified') {
                $q->where('action', 'licence.verified');
            } elseif ($cat === 'logins') {
                $q->whereIn('action', ['admin.login', 'admin.logout']);
            } elseif ($cat && $cat !== 'all') {
                $q->where('action', 'like', $cat . '.%');
            }

            // PERMANENT HISTORY (Ejaz, 6-Aug-2026): licence lifecycle events
            // (issued, shifted, bound, blocked, renewed, edited, .lic downloads,
            // monthly verified summaries) and the money trail (orders, quotes,
            // buys, setup invoices) can NEVER be deleted — whatever category or
            // date range is chosen. Only routine volume logs are cleanable:
            // daily licence.verified rows (rolled up first), sign-ins, client
            // activity, and the email log.
            $q->where(function ($w) {
                $w->where('action', 'not like', 'licence.%')
                  ->orWhere('action', 'licence.verified'); // dailies only — rolled up below
            })
            ->where('action', 'not like', 'order.%')
            ->where('action', 'not like', 'quote.%')
            ->where('action', 'not like', 'buy.%')
            ->where('action', 'not like', 'setup.%')
            ->where('action', '!=', 'logs.purged');
        }

        $count = (clone $q)->count();

        if (! ($data['confirm'] ?? false)) {
            return response()->json(['preview' => true, 'count' => $count]);
        }

        $summaries = 0;

        // Roll up daily verification rows into monthly per-licence summaries so
        // individual licence History is preserved (Ejaz's requirement).
        if ($data['log'] === 'audit' && in_array($cat, ['licence.verified', 'all'], true)) {
            $rows = \App\Models\AuditLog::whereBetween('created_at', [$from, $to])
                ->where('action', 'licence.verified')
                ->orderBy('created_at')
                ->get(['id', 'subject_id', 'created_at', 'meta']);

            foreach ($rows->groupBy(fn ($r) => $r->subject_id . '|' . $r->created_at->format('Y-m')) as $group) {
                $first = $group->first();
                if (! $first->subject_id) {
                    continue;
                }
                \App\Models\AuditLog::create([
                    'admin_user_id' => null,
                    'action' => 'licence.verified_summary',
                    'subject_type' => \App\Models\Licence::class,
                    'subject_id' => $first->subject_id,
                    'meta' => [
                        'month' => $first->created_at->format('M Y'),
                        'verified_days' => $group->count(),
                        'first' => $group->first()->created_at->toDateString(),
                        'last' => $group->last()->created_at->toDateString(),
                        'machine' => $group->last()->meta['machine'] ?? '—',
                    ],
                ]);
                $summaries++;
            }
        }

        $deleted = $q->delete();

        AuditLog::write('logs.purged', null, [
            'log' => $data['log'], 'category' => $cat,
            'from' => $from->toDateString(), 'to' => $to->toDateString(),
            'deleted' => $deleted, 'monthly_summaries_created' => $summaries,
        ]);

        return response()->json(['ok' => true, 'deleted' => $deleted, 'summaries' => $summaries]);
    }

    /** POST config/test-email — send a test using the current SMTP settings. */
    public function testEmail(Request $request)
    {
        $data = $request->validate(['to' => ['required', 'email']]);
        $ok = app(\App\Services\MailService::class)->send(
            $data['to'],
            'SmartEPT — test email',
            "This is a test email from SmartEPT Central.\nIf you received it, your SMTP settings are working."
            . \App\Services\MailService::signature()
        );

        return response()->json([
            'ok' => $ok,
            'message' => $ok
                ? 'Test email sent to ' . $data['to'] . ' — check the inbox (and spam).'
                : 'Could not send. Check the SMTP host, port, username and password, then try again.',
        ]);
    }

    public function audit(Request $request)
    {
        $q = \App\Models\AuditLog::with('adminUser:id,name')->latest();
        if ($request->filled('action')) {
            $q->where('action', $request->query('action'));
        }

        return response()->json($q->paginate(50));
    }
}
