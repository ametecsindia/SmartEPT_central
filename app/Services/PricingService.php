<?php

namespace App\Services;

use App\Models\Plan;
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
    public const SETUP_FEE_INCLUDED_DEVICES = 25;
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

        if ($deployment === 'cloud') {
            $rate = round($rate * $cfg['cloud_multiplier']);
        }

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
        $deployment = $deployment ?: ($tenant->deployment ?: 'client_hosted');
        $rate = $this->deviceRate($plan, $devices, $billing, $deployment, (bool) $tenant->ecosystem_customer);
        $months = LicenceService::billingMonths($billing);

        $lines = [[
            'type' => 'licence',
            'description' => sprintf('%s plan — %d devices × ₹%s/device/month × %d months (%s, %s)',
                $plan->name, $devices, number_format($rate, $rate == (int) $rate ? 0 : 2),
                $months, str_replace('_', '-', $deployment), str_replace('_', '-', $billing)),
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

    /**
     * Build the line items for a perpetual licence order.
     */
    public function perpetualQuote(Tenant $tenant, Plan $plan, int $devices): array
    {
        $lines = [
            [
                'type' => 'perpetual_devices',
                'description' => sprintf('%s perpetual — %d device licences × ₹%s',
                    $plan->name, $devices, number_format($plan->perpetual_device_inr)),
                'qty' => $devices,
                'unit' => (float) $plan->perpetual_device_inr,
                'amount' => round($devices * $plan->perpetual_device_inr, 2),
            ],
            [
                'type' => 'perpetual_server',
                'description' => sprintf('%s central server licence', $plan->name),
                'qty' => 1,
                'unit' => (float) $plan->perpetual_server_inr,
                'amount' => (float) $plan->perpetual_server_inr,
            ],
        ];

        if (! $tenant->setup_fee_paid) {
            $fee = $this->setupFee($devices);
            $lines[] = [
                'type' => 'setup_fee',
                'description' => 'One-time Setup & Onboarding',
                'qty' => 1,
                'unit' => $fee,
                'amount' => (float) $fee,
            ];
        }

        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);

        return ['lines' => $lines, 'subtotal' => $subtotal];
    }
}
