<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per successful client download. Also the single source of truth for
 * the per-client download quotas (anti-abuse): free/trial vs paid, per day
 * (per app) and per month (total). Limits are admin-editable in Settings.
 */
class DownloadLog extends Model
{
    protected $guarded = ['id'];

    /** Fallback quota if nothing is set in Settings. */
    public const DEFAULTS = [
        'download_daily_free'   => 2,
        'download_daily_paid'   => 5,
        'download_monthly_free' => 5,
        'download_monthly_paid' => 20,
    ];

    /** Current quota numbers (Settings override, else DEFAULTS). Blank never means zero. */
    public static function limits(): array
    {
        $out = [];
        foreach (self::DEFAULTS as $key => $default) {
            $v = Setting::get($key, null);
            $out[$key] = ($v === null || $v === '') ? $default : max(0, (int) $v);
        }

        return $out;
    }

    /** Resolve the daily(per-app) + monthly(total) caps + tier for a tenant. */
    public static function quotaFor(?Tenant $tenant): array
    {
        $l = self::limits();
        $isPaid = $tenant && $tenant->status === 'active';

        return [
            'is_paid'     => (bool) $isPaid,
            'daily'       => $isPaid ? $l['download_daily_paid'] : $l['download_daily_free'],
            'monthly'     => $isPaid ? $l['download_monthly_paid'] : $l['download_monthly_free'],
        ];
    }
}
