<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\PlanPerpetualBand;
use App\Models\Setting;
use App\Models\Tenant;

/**
 * All SmartEPT commercial arithmetic in ONE place.
 * Source: SmartEPT Pricing, Licensing & Cloud Hosting Framework (Jul 2026)
 * + Ejaz's Setup & Onboarding fee rule (14-Jul-2026).
 */
class PricingService
{
    public const CLOUD_MULTIPLIER = 1.5;

    // One-time Setup & Onboarding: ₹5,000 covers up to 25 devices, +₹100/device beyond.
    public const SETUP_FEE_BASE_INR = 5000;
    public const SETUP_FEE_INCLUDED_DEVICES = 30;
    public const SETUP_FEE_PER_EXTRA_DEVICE_INR = 100;

    // Cloud storage slabs (₹ per GB per month) + minimum commitment.
    public const STORAGE_MIN_GB = 50;
    public const STORAGE_SLABS = [
        [1, 500, 3.00],
        [501, 2048, 2.50],
        [2049, null, 2.00],
    ];

    // Existing-customer discount (locked 19-Jul): a flat 10% off for SmartDCM /
    // SmartPRS customers, applied to EVERY plan, billing cycle and deployment.
    // (Replaces the old ₹39 Professional-only intro; constants kept for reference.)
    public const ECOSYSTEM_DISCOUNT = 0.10;
    public const ECOSYSTEM_RATE_INR = 39;
    public const ECOSYSTEM_MIN_DEVICES = 25;

    /** In-process memo of the DB-configurable pricing knobs. */
    protected static ?array $cfg = null;

    /**
     * The admin-editable commercial knobs (Central -> Settings -> Pricing & Cloud).
     * Every value falls back to the constant above when its Setting is unset, so
     * the money path never breaks on a fresh install. Memoised per request.
     */
    public static function config(): array
    {
        if (self::$cfg !== null) {
            return self::$cfg;
        }

        $r = \App\Models\Setting::whereIn('key', [
            'pricing_annual_discount_pct', 'pricing_half_yearly_discount_pct', 'pricing_cloud_multiplier',
            'pricing_setup_base_inr', 'pricing_setup_included_devices', 'pricing_setup_per_extra_inr',
            'pricing_storage_min_gb', 'pricing_storage_min_inr', 'pricing_storage_slabs',
        ])->pluck('value', 'key');

        $slabs = self::STORAGE_SLABS;
        if (! empty($r['pricing_storage_slabs'])) {
            $decoded = json_decode($r['pricing_storage_slabs'], true);
            if (is_array($decoded) && $decoded) {
                $clean = [];
                foreach ($decoded as $row) {
                    if (! is_array($row) || count($row) < 3) {
                        continue;
                    }
                    $clean[] = [(int) $row[0], ($row[1] === null || $row[1] === '') ? null : (int) $row[1], (float) $row[2]];
                }
                if ($clean) {
                    $slabs = $clean;
                }
            }
        }

        $num = fn ($key, $def) => (isset($r[$key]) && $r[$key] !== '') ? (float) $r[$key] : $def;

        return self::$cfg = [
            'annual_discount'      => max(0.0, min(0.9, $num('pricing_annual_discount_pct', 25) / 100)),
            'half_yearly_discount' => max(0.0, min(0.9, $num('pricing_half_yearly_discount_pct', 10) / 100)),
            'cloud_multiplier'     => max(1.0, $num('pricing_cloud_multiplier', self::CLOUD_MULTIPLIER)),
            'setup_base'           => (int) $num('pricing_setup_base_inr', self::SETUP_FEE_BASE_INR),
            'setup_included'       => (int) $num('pricing_setup_included_devices', self::SETUP_FEE_INCLUDED_DEVICES),
            'setup_per_extra'      => (int) $num('pricing_setup_per_extra_inr', self::SETUP_FEE_PER_EXTRA_DEVICE_INR),
            'storage_min_gb'       => (int) $num('pricing_storage_min_gb', self::STORAGE_MIN_GB),
            'storage_min_inr'      => $num('pricing_storage_min_inr', 150),
            'storage_slabs'        => $slabs,
        ];
    }

    /** "covers N devices[, +X x Rupee Y]" descriptor for setup lines (config-aware). */
    public function setupCoverLabel(int $devices): string
    {
        $cfg = self::config();
        $extra = max(0, $devices - $cfg['setup_included']);

        return sprintf('covers %d devices%s', $cfg['setup_included'],
            $extra > 0 ? ', +' . $extra . ' × ₹' . $cfg['setup_per_extra'] : '');
    }

    /**
     * Per-device per-month licence rate.
     * Nullable params on purpose: a freshly-created Tenant model may not have
     * hydrated its DB defaults (deployment / ecosystem_customer come back
     * null) — normalise here instead of TypeError-ing the whole money path.
     */
    public function deviceRate(Plan $plan, int $devices, ?string $billing = 'annual',
                               ?string $deployment = 'client_hosted', ?bool $ecosystem = false): float
    {
        $billing = $billing ?: 'annual';
        $deployment = $deployment ?: 'client_hosted';
        $ecosystem = (bool) $ecosystem;

        // Volume tiers are defined against the annual client-hosted rate.
        $annual = (float) $plan->inr_annual;

        foreach ($plan->volumeTiers as $tier) {
            $inTier = $devices >= $tier->min_devices
                && ($tier->max_devices === null || $devices <= $tier->max_devices);
            if ($inTier) {
                $annual = (float) $tier->rate_inr_annual;
                break;
            }
        }

        // Ejaz's advance-period rule (locked 16-Jul): base monthly = annual / 0.75.
        // Quarterly pays base (0% off), half-yearly 10% off base, annual 25% off base
        // (which lands exactly on the published annual rate).
        // Ejaz's advance-period rule (locked 16-Jul), now admin-configurable:
        // base monthly = annual / (1 - annual_discount); quarterly pays base,
        // half-yearly takes half_yearly_discount off base, annual is the published rate.
        $cfg = self::config();
        $annualBase = $annual / max(0.1, 1 - $cfg['annual_discount']);
        $rate = match ($billing) {
            'annual' => $annual,
            'half_yearly' => round($annualBase * (1 - $cfg['half_yearly_discount']), 2),
            'quarterly' => round($annualBase, 2),
            default => (float) $plan->inr_monthly, // legacy monthly
        };

        // Pricing v2: subscription is Cloud-only, so the band rate IS the cloud price —
        // no client-hosted base + ×1.5 multiplier any more. (cloud_multiplier retained in
        // config only for backward compatibility with historical quotes.)

        // Existing-customer discount (locked 19-Jul): flat 10% off for SmartDCM /
        // SmartPRS customers — every plan, cycle and deployment.
        if ($ecosystem) {
            $rate = round($rate * (1 - self::ECOSYSTEM_DISCOUNT), 2);
        }

        return $rate;
    }

    /**
     * One-time Setup & Onboarding fee (first invoice only).
     * ₹5,000 minimum covering up to 25 devices; ₹100 per additional device.
     */
    /**
     * Standalone Setup & Onboarding quote — used when installation was NOT bought
     * up front and the client later asks Ametecs to install/onboard. Admin raises
     * this as its own invoice (no subscription line).
     */
    public function setupOnlyQuote(Tenant $tenant, int $devices): array
    {
        $fee = $this->setupFee($devices);
        $lines = [[
            'type' => 'setup_fee',
            'description' => 'Installation & Onboarding service (' . $this->setupCoverLabel($devices) . ')',
            'qty' => 1,
            'unit' => $fee,
            'amount' => (float) $fee,
        ]];
        return ['lines' => $lines, 'subtotal' => (float) $fee];
    }

    public function setupFee(int $devices): int
    {
        $cfg = self::config();
        $extra = max(0, $devices - $cfg['setup_included']);

        return $cfg['setup_base'] + $extra * $cfg['setup_per_extra'];
    }

    /**
     * Monthly cloud storage rental for a given average GB.
     */
    public function storageMonthly(float $gb): float
    {
        $cfg = self::config();
        $billableGb = (int) ceil(max($gb, $cfg['storage_min_gb']));
        $cost = 0.0;

        foreach ($cfg['storage_slabs'] as [$from, $to, $rate]) {
            if ($billableGb < $from) {
                break;
            }
            $upper = $to === null ? $billableGb : min($billableGb, $to);
            $cost += ($upper - $from + 1) * $rate;
        }

        return max($cost, (float) $cfg['storage_min_inr']); // minimum monthly storage commitment
    }

    /**
     * Build the line items for a subscription order.
     *
     * @return array{lines: array<int, array>, subtotal: float}
     */
    public function subscriptionQuote(Tenant $tenant, Plan $plan, int $devices,
                                      string $billing = 'annual', ?string $deployment = null, bool $includeSetup = true): array
    {
        $deployment = 'cloud'; // v2: subscription = SmartEPT Cloud only
        $rate = $this->deviceRate($plan, $devices, $billing, $deployment, (bool) $tenant->ecosystem_customer);
        $months = LicenceService::billingMonths($billing);

        $lines = [[
            'type' => 'licence',
            'description' => sprintf('SmartEPT Cloud — %d users × ₹%s/user/month × %d months (%s)',
                $devices, number_format($rate, $rate == (int) $rate ? 0 : 2),
                $months, str_replace('_', '-', $billing)),
            'qty' => $devices,
            'unit' => $rate * $months,
            'amount' => round($devices * $rate * $months, 2),
        ]];

        if ($includeSetup && ! $tenant->setup_fee_paid) {
            $fee = $this->setupFee($devices);
            $lines[] = [
                'type' => 'setup_fee',
                'description' => 'One-time Setup & Onboarding (' . $this->setupCoverLabel($devices) . ')',
                'qty' => 1,
                'unit' => $fee,
                'amount' => (float) $fee,
            ];
        }

        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);

        return ['lines' => $lines, 'subtotal' => $subtotal];
    }

    /** The perpetual band a given user count falls into, or null when above the top band (custom quote). */
    public function perpetualBandFor(Plan $plan, int $users): ?PlanPerpetualBand
    {
        foreach ($plan->perpetualBands as $band) {
            if ($users >= $band->min_users && ($band->max_users === null || $users <= $band->max_users)) {
                return $band;
            }
        }

        return null;
    }

    /**
     * PROGRESSIVE / PROPORTIONAL On-Premise Lifetime pricing (Ejaz, 11-Aug-2026).
     *
     * Each configured band's price_inr means "one-time price AT the band's
     * max_users milestone" — NOT a flat price for every count inside the band.
     * Counts between two milestones are priced by straight-line interpolation:
     *
     *   price = prevMilestonePrice
     *         + (users - prevMilestoneUsers)
     *         × (currMilestonePrice - prevMilestonePrice) / (currMilestoneUsers - prevMilestoneUsers)
     *
     * Rules:
     *  - FIRST band = the minimum licence package: flat first-band price for any
     *    count inside it — never proportionately reduced below it.
     *  - Below the first band's min_users → below_min (validation, not a price).
     *  - Open-ended band (max_users NULL) or above the last priced milestone
     *    → custom quote. NEVER a ₹0 price.
     *  - All maths on integers with ONE final round, so every configured
     *    milestone lands exactly on its configured price and there is no
     *    cumulative float drift.
     *
     * Everything is derived from the admin-saved bands — nothing hard-coded.
     */
    public function calculateLifetimeLicencePrice(Plan $plan, int $requestedUsers): array
    {
        $result = [
            'requested_users'    => $requestedUsers,
            'price'              => null,   // int rupees, null when custom/below_min
            'previous_milestone' => null,   // ['users' => int, 'price' => int]
            'next_milestone'     => null,   // ['users' => int, 'price' => int]
            'per_user_rate'      => null,   // float ₹/additional user in this segment
            'custom'             => false,  // true = Custom Quote / Contact Sales
            'below_min'          => false,  // true = below the minimum licence capacity
            'min_users'          => null,   // configured minimum (first band min_users)
            'max_priced_users'   => null,   // last automatically priced milestone
        ];

        // Only bands with a max milestone AND a price participate in automatic
        // pricing; an open-ended band (max NULL) is the explicit Custom Quote band.
        $priced = $plan->perpetualBands
            ->filter(fn ($b) => $b->max_users !== null && $b->price_inr !== null && (int) $b->price_inr > 0)
            ->sortBy('min_users')->values();

        if ($priced->isEmpty()) {
            $result['custom'] = true;

            return $result;
        }

        $first = $priced->first();
        $last = $priced->last();
        $result['min_users'] = (int) $first->min_users;
        $result['max_priced_users'] = (int) $last->max_users;

        if ($requestedUsers < (int) $first->min_users) {
            $result['below_min'] = true;

            return $result;
        }

        if ($requestedUsers > (int) $last->max_users) {
            $result['custom'] = true;

            return $result;
        }

        // First band = minimum licence package: flat price, never reduced.
        if ($requestedUsers <= (int) $first->max_users) {
            $result['price'] = (int) $first->price_inr;
            $result['previous_milestone'] = ['users' => (int) $first->max_users, 'price' => (int) $first->price_inr];
            $next = $priced->get(1);
            $result['next_milestone'] = $next
                ? ['users' => (int) $next->max_users, 'price' => (int) $next->price_inr]
                : $result['previous_milestone'];
            $result['per_user_rate'] = 0.0;

            return $result;
        }

        // Interpolate between the previous band's milestone and this band's milestone.
        $prev = $first;
        foreach ($priced->slice(1) as $band) {
            if ($requestedUsers <= (int) $band->max_users) {
                $prevUsers = (int) $prev->max_users;
                $prevPrice = (int) $prev->price_inr;
                $currUsers = (int) $band->max_users;
                $currPrice = (int) $band->price_inr;
                $span = max(1, $currUsers - $prevUsers);
                $deltaPrice = $currPrice - $prevPrice;

                // Integer numerator, ONE final round → milestones stay exact.
                $price = (int) round($prevPrice + (($requestedUsers - $prevUsers) * $deltaPrice) / $span);

                $result['price'] = $price;
                $result['previous_milestone'] = ['users' => $prevUsers, 'price' => $prevPrice];
                $result['next_milestone'] = ['users' => $currUsers, 'price' => $currPrice];
                $result['per_user_rate'] = round($deltaPrice / $span, 4);

                return $result;
            }
            $prev = $band;
        }

        // Unreachable when bands are contiguous (validated on save) — safety net.
        $result['custom'] = true;

        return $result;
    }

    /**
     * Build the line items for a PERPETUAL (own-it) order — pricing v2,
     * PROGRESSIVE since 11-Aug-2026 (see calculateLifetimeLicencePrice()).
     * One-time lifetime licence priced proportionately by exact user count,
     * all features, client-hosted. Setup is always extra.
     * Above the last priced milestone → custom quote (never ₹0).
     * Below the configured minimum → below_min (validation, never a price).
     */
    public function perpetualQuote(Tenant $tenant, Plan $plan, int $devices): array
    {
        $users = max(1, $devices);
        $calc = $this->calculateLifetimeLicencePrice($plan, $users);

        if ($calc['below_min']) {
            return ['lines' => [], 'subtotal' => 0.0, 'custom' => false,
                'below_min' => true, 'min_users' => $calc['min_users'], 'pricing' => $calc];
        }
        if ($calc['custom'] || $calc['price'] === null || $calc['price'] <= 0) {
            return ['lines' => [], 'subtotal' => 0.0, 'custom' => true,
                'max_priced_users' => $calc['max_priced_users'], 'pricing' => $calc];
        }

        $lines = [[
            'type' => 'perpetual_licence',
            'description' => sprintf('SmartEPT Perpetual — lifetime licence (%d users, all features)', $users),
            'qty' => 1,
            'unit' => (float) $calc['price'],
            'amount' => (float) $calc['price'],
        ]];

        if (! $tenant->setup_fee_paid) {
            $fee = $this->setupFee($users);
            $lines[] = [
                'type' => 'setup_fee',
                'description' => 'One-time Setup & Onboarding (' . $this->setupCoverLabel($users) . ')',
                'qty' => 1,
                'unit' => $fee,
                'amount' => (float) $fee,
            ];
        }

        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);

        return ['lines' => $lines, 'subtotal' => $subtotal, 'pricing' => $calc];
    }

    /**
     * CUSTOM (admin-entered) price quote (Ejaz, 13-Aug-2026): the operator's
     * figure REPLACES the band/tier calculation entirely — any user count is
     * allowed (including above the last priced milestone). ₹0 is impossible
     * (validated at the controller, guarded here too). Setup fee, coupon and
     * GST behave exactly as on a calculated order. Used by admin New Order/
     * Quote, Prospect Quote and the request-queue conversion — never public.
     */
    public function customPriceQuote(Tenant $tenant, Plan $plan, int $users, string $kind,
                                     string $billing, int $price, bool $includeSetup = true): array
    {
        $users = max(1, $users);
        $price = max(1, $price);

        $lines = [[
            'type' => $kind === 'perpetual' ? 'perpetual_licence' : 'licence',
            'description' => $kind === 'perpetual'
                ? sprintf('SmartEPT Perpetual — lifetime licence (%d users, all features) · special price', $users)
                : sprintf('SmartEPT Cloud — %d users (%s) · special price', $users, str_replace('_', '-', $billing)),
            'qty' => 1,
            'unit' => (float) $price,
            'amount' => (float) $price,
        ]];

        if ($includeSetup && ! $tenant->setup_fee_paid) {
            $fee = $this->setupFee($users);
            $lines[] = [
                'type' => 'setup_fee',
                'description' => 'One-time Setup & Onboarding (' . $this->setupCoverLabel($users) . ')',
                'qty' => 1,
                'unit' => $fee,
                'amount' => (float) $fee,
            ];
        }

        return ['lines' => $lines, 'subtotal' => round(array_sum(array_column($lines, 'amount')), 2),
            'custom_price' => $price];
    }

    /**
     * Optional Annual Maintenance & Support for a perpetual client — pricing v2.
     * Priced at pricing_amc_pct (default 18%, the mid of the 15–20% band) of the
     * PREVAILING price — since 11-Aug-2026 the progressive/interpolated price
     * for the client's exact user count (Ejaz's decision), not the band-max price.
     */
    public function amcQuote(Tenant $tenant, Plan $plan, int $users, ?float $pct = null): array
    {
        $users = max(1, $users);
        $calc = $this->calculateLifetimeLicencePrice($plan, $users);
        if ($calc['below_min'] || $calc['custom'] || $calc['price'] === null || $calc['price'] <= 0) {
            return ['lines' => [], 'subtotal' => 0.0, 'custom' => true, 'pricing' => $calc];
        }

        $pct = $pct ?? (float) Setting::get('pricing_amc_pct', 18);
        $amc = round($calc['price'] * $pct / 100, 2);

        return ['lines' => [[
            'type' => 'amc',
            'description' => sprintf('Annual Maintenance & Support — %s%% of prevailing licence (%d users)',
                rtrim(rtrim(number_format($pct, 2), '0'), '.'), $users),
            'qty' => 1,
            'unit' => $amc,
            'amount' => $amc,
        ]], 'subtotal' => $amc, 'pricing' => $calc];
    }
}
