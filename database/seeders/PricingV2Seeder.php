<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Pricing model v2 — single all-features SmartEPT product, priced by user scale.
 *  - Cloud (rental): per-user/month bands (stored as plan_volume_tiers, treated as USERS).
 *  - Perpetual (own it): one-time price by licensed-user capacity (plan_perpetual_bands).
 * Idempotent. Old Core/Professional/Enterprise plans are DEACTIVATED, never deleted,
 * so historical licences/orders keep their references.
 */
class PricingV2Seeder extends Seeder
{
    public function run(): void
    {
        // The one product — every feature on.
        $plan = Plan::firstOrCreate(['code' => 'smartept'], [
            'name' => 'SmartEPT', 'sort' => 0,
            // inr_annual is the >5,000-users cloud floor fallback; bands drive the real price.
            'inr_annual' => 6, 'inr_monthly' => 8, 'usd_annual' => 0.15, 'usd_monthly' => 0.20,
            'perpetual_device_inr' => 0, 'perpetual_server_inr' => 0,
            'min_devices' => 1, 'storage_gb' => 0,
            'features' => [
                'attendance' => true, 'activity' => true, 'screenshots' => true, 'reports' => true,
                'manager_accounts' => -1, 'live_status' => true, 'live_screen' => true, 'restrictions' => true,
                'scoring' => 'advanced', 'multi_office' => true, 'api' => true,
                'screen_recording' => true, 'camera_presence' => true, 'usb_controls' => true,
                'sso' => true, 'gate_to_pc' => true,
            ],
            'active' => true,
        ]);

        // Cloud rental — per-user/month (annual rate); half-yearly/quarterly are derived.
        $cloud = [
            [1, 30, 45], [31, 100, 28], [101, 250, 20],
            [251, 500, 15], [501, 1000, 12], [1001, 2000, 9], [2001, 5000, 6],
        ];
        // SAFETY (Ejaz 5-Aug-2026): pricing is ADMIN-MANAGED ONLY. Never wipe, never auto-seed
        // bands — even when empty. Deliberate first-time bootstrap only via SEED_PRICING_BANDS=true.
        $seedBands = filter_var(env('SEED_PRICING_BANDS', false), FILTER_VALIDATE_BOOLEAN);
        if ($seedBands && $plan->volumeTiers()->count() === 0) {
            foreach ($cloud as [$min, $max, $rate]) {
                $plan->volumeTiers()->create(['min_devices' => $min, 'max_devices' => $max, 'rate_inr_annual' => $rate]);
            }
        }

        // Perpetual — one-time lifetime licence by user capacity. Above 5,000 = custom quote.
        $perp = [
            [1, 30, 25000], [31, 100, 50000], [101, 250, 85000], [251, 500, 125000],
            [501, 1000, 200000], [1001, 2000, 325000], [2001, 5000, 500000],
        ];
        if ($seedBands && $plan->perpetualBands()->count() === 0) {
            $i = 0;
            foreach ($perp as [$min, $max, $price]) {
                $plan->perpetualBands()->create(['min_users' => $min, 'max_users' => $max, 'price_inr' => $price, 'sort' => $i++]);
            }
        }

        // Retire the old tiered plans (kept for history).
        Plan::whereIn('code', ['core', 'professional', 'enterprise'])->update(['active' => false]);

        // Commercial knobs for v2.
        Setting::firstOrCreate(['key' => 'pricing_setup_included_devices'], ['value' => 30]);
        if (! Setting::where('key', 'pricing_amc_pct')->exists()) {
            Setting::updateOrCreate(['key' => 'pricing_amc_pct'], ['value' => 18]); // mid of the 15–20% band
        }
    }
}
