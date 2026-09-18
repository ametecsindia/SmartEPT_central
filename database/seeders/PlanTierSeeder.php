<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Standard / Enforcer / Commander plans (18-Sep-2026).
 *
 * Backfills every existing plan to tier=standard — code, prices, perpetual
 * bands and volume tiers are completely untouched, only the new nullable
 * `tier` column is set — then creates the Enforcer and Commander Plan rows
 * EMPTY: zero perpetual bands, zero volume tiers, inr_annual/inr_monthly = 0.
 *
 * That emptiness is deliberate, not an oversight: Ejaz has not priced these
 * two plans yet, and this task must never invent a number for him. It is
 * safe specifically because of the companion fix in PricingService::
 * cloudIsCustom() (18-Sep-2026) — a plan with no priced volume tiers at all
 * now resolves to "custom quote" on the Cloud side, exactly as a plan with
 * no perpetual bands already resolved to "custom quote" on the On-Premises
 * side. Without that fix, seeding these rows would silently reopen the
 * ₹0-sale hole closed on 31-Aug-2026 for a different case. Do not run this
 * seeder before that fix is deployed.
 *
 * Run manually and only once reviewed: php artisan db:seed --class=PlanTierSeeder
 * Never auto-run against production as part of a deploy script.
 */
class PlanTierSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Backfill every existing ACTIVE plan (today just 'smartept') to the
        //    Standard tier. Scoped to active=true deliberately: a retired,
        //    already-inactive plan (e.g. an old 'professional' fallback) must
        //    stay untagged rather than start competing with 'smartept' as a
        //    second active-looking 'standard' row for BuyController::resolvePlan()
        //    and the admin Plan dropdowns to pick between.
        Plan::whereNull('tier')->where('active', true)->update(['tier' => 'standard']);

        // 1b. The pre-existing Standard row keeps its original bare "SmartEPT"
        //     name, which reads oddly next to "SmartEPT Enforcer"/"SmartEPT
        //     Commander" in every Plan dropdown (Issue Licence, Edit Licence,
        //     New Order). Rename it once — only the exact untouched legacy
        //     name, so a name Ejaz has since customised is never overwritten.
        //     Safe to re-run: does nothing once the name has changed.
        Plan::where('tier', 'standard')->where('name', 'SmartEPT')->update(['name' => 'SmartEPT Standard']);

        // Courtesy defaults only (min_devices/storage_gb already have sane
        // schema defaults) — mirrors the live Standard plan so Enforcer/
        // Commander don't start from an arbitrary number for non-pricing fields.
        $standard = Plan::where('tier', 'standard')->where('active', true)->orderBy('id')->first();

        $newPlans = [
            [
                'code' => 'enforcer',
                'tier' => 'enforcer',
                'name' => 'SmartEPT Enforcer',
                // Enforcement on, Live View off — the exact matrix from the brief.
                'features' => ['enforcement' => true, 'live_view' => false],
            ],
            [
                'code' => 'commander',
                'tier' => 'commander',
                'name' => 'SmartEPT Commander',
                'features' => ['enforcement' => true, 'live_view' => true, 'liveview_max_concurrent' => 1],
            ],
        ];

        foreach ($newPlans as $p) {
            Plan::updateOrCreate(
                ['code' => $p['code']],
                [
                    'tier' => $p['tier'],
                    'name' => $p['name'],
                    // Deliberately unpriced — no automatic price until Ejaz configures
                    // real perpetual bands / volume tiers on the Central pricing
                    // screen. NEVER invented here. See the class docblock above.
                    'inr_annual' => 0,
                    'inr_monthly' => 0,
                    'min_devices' => $standard->min_devices ?? 10,
                    'storage_gb' => $standard->storage_gb ?? null,
                    'features' => $p['features'],
                    'sort' => $standard ? $standard->sort + 1 : 1,
                    // Active = selectable everywhere a plan appears. Being priced
                    // as "custom quote" until bands/tiers exist is the unpublished-
                    // pricing state the brief asks for — never hidden outright.
                    'active' => true,
                ]
            );
        }
    }
}
