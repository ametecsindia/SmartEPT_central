<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Setting;
use App\Services\MailService;
use App\Services\PricingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Public, unauthenticated pricing feed for the landing page.
 * Edit a plan in /admin → Plans and the public page follows automatically.
 * No customer data here — plans and published commercial constants only.
 */
class PublicController extends Controller
{
    public function plans()
    {
        $payload = Cache::remember('public_plans_v2', 300, function () {
            return [
                'plans' => Plan::with('volumeTiers')->where('active', true)->orderBy('sort')->get()
                    ->map(fn ($p) => [
                        'code' => $p->code,
                        'name' => $p->name,
                        'inr_annual' => (float) $p->inr_annual,
                        'inr_monthly' => (float) $p->inr_monthly,
                        'usd_annual' => (float) $p->usd_annual,
                        'usd_monthly' => (float) $p->usd_monthly,
                        'min_devices' => (int) $p->min_devices,
                        'volume_tiers' => $p->volumeTiers->map(fn ($t) => [
                            'min' => (int) $t->min_devices,
                            'max' => $t->max_devices === null ? null : (int) $t->max_devices,
                            'rate' => (float) $t->rate_inr_annual,
                        ])->all(),
                    ])->all(),
                'cloud_multiplier' => PricingService::CLOUD_MULTIPLIER,
                'setup' => [
                    'base' => PricingService::SETUP_FEE_BASE_INR,
                    'included' => PricingService::SETUP_FEE_INCLUDED_DEVICES,
                    'per_extra' => PricingService::SETUP_FEE_PER_EXTRA_DEVICE_INR,
                ],
                'storage' => [
                    'slabs' => PricingService::STORAGE_SLABS,
                    'min_gb' => PricingService::STORAGE_MIN_GB,
                    'min_inr' => 150,
                ],
                'gst_rate' => (float) Setting::get('gst_rate', 18),
                'trial' => ['days' => 7, 'devices' => 10, 'plan' => 'professional'],
            ];
        });

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=300')
            ->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * R3-7: public lead capture (landing form / campaigns). Heavily rate-limited
     * in routes. Sales gets an email instantly; the lead lands in /admin → Leads.
     */
    public function lead(Request $request, MailService $mail)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:190'],
            'devices_interested' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'message' => ['nullable', 'string', 'max:2000'],
            'source' => ['nullable', 'string', 'max:64'],
        ]);

        if (empty($data['email']) && empty($data['phone'])) {
            return response()->json(['error' => 'Please share an email or phone number so we can reach you.'], 422);
        }

        $lead = Lead::create($data + ['source' => $data['source'] ?? 'website', 'status' => 'NEW']);

        $mail->send(
            Setting::get('sales_email', 'sales@ametecsindia.com'),
            'New SmartEPT lead: ' . $lead->name . ($lead->company ? ' — ' . $lead->company : ''),
            "A new lead just arrived from the website.\n\n"
            . 'Name    : ' . $lead->name . "\n"
            . 'Company : ' . ($lead->company ?: '—') . "\n"
            . 'Email   : ' . ($lead->email ?: '—') . "\n"
            . 'Phone   : ' . ($lead->phone ?: '—') . "\n"
            . 'City    : ' . ($lead->city ?: '—') . "\n"
            . 'Devices : ' . ($lead->devices_interested ?: '—') . "\n"
            . 'Message : ' . ($lead->message ?: '—') . "\n\n"
            . 'Work it from /admin → Leads.'
            . MailService::signature()
        );

        return response()->json(['ok' => true, 'message' => 'Thank you! Our team will contact you shortly.'], 201);
    }

    /** R3-7: live coupon validation for signup/portal calculators. */
    public function couponCheck(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'devices' => ['nullable', 'integer', 'min:0'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'email' => ['nullable', 'email', 'max:190'],
        ]);

        [$coupon, $reason] = Coupon::check($data['code'], (int) ($data['devices'] ?? 0), $data['email'] ?? null);

        if (! $coupon) {
            return response()->json(['ok' => false, 'reason' => $reason]);
        }

        return response()->json([
            'ok' => true,
            'code' => $coupon->code,
            'description' => $coupon->description,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
            'discount' => isset($data['subtotal']) ? $coupon->discountFor((float) $data['subtotal']) : null,
        ]);
    }

    /**
     * Master prompt §6 — the exclusive-offer catch. When the email field fills
     * in at signup, the page quietly asks whether an exclusive coupon was sent
     * to that email and auto-applies it. Sales loves this. Returns the same
     * "nothing" answer for unknown emails so it cannot be used to probe codes.
     */
    public function exclusiveOffer(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:190']]);

        $coupon = Coupon::exclusiveFor($data['email']);

        if (! $coupon) {
            return response()->json(['ok' => false]);
        }

        return response()->json([
            'ok' => true,
            'code' => $coupon->code,
            'description' => $coupon->description,
            'type' => $coupon->type,
            'value' => (float) $coupon->value,
        ]);
    }

    /**
     * CMS-driven landing content (master prompt §5): hero, announcement,
     * contact and testimonials editable by Super Admin in /admin → Landing CMS.
     * The landing page applies these OVER its baked-in copy on load, so an
     * empty value simply keeps the built-in text (file:// preview safe).
     */
    public function content()
    {
        $payload = Cache::remember('public_content_v1', 300, function () {
            $keys = [
                'landing_hero_title', 'landing_hero_subtitle', 'landing_announcement',
                'landing_contact_phone', 'landing_contact_email', 'whatsapp_number',
                'landing_testimonials',
            ];

            $out = [];
            foreach ($keys as $k) {
                $out[$k] = Setting::get($k, '');
            }

            // Testimonials are stored as JSON [{quote, name, role}]; pass through parsed.
            $t = json_decode((string) $out['landing_testimonials'], true);
            $out['landing_testimonials'] = is_array($t) ? $t : [];

            return $out;
        });

        return response()->json($payload)
            ->header('Cache-Control', 'public, max-age=300')
            ->header('Access-Control-Allow-Origin', '*');
    }
}
