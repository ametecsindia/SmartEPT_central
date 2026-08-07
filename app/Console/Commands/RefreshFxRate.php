<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Keep usd_inr_rate LIVE. Fetches the current USD->INR rate from a free, key-less
 * FX feed and writes it to the Setting. Every currency conversion (BillingService,
 * /buy, PublicController, diagnostics) already reads that Setting, so this one job
 * is all that's needed to make USD pricing track the market. On ANY failure the
 * last good value is kept — checkout never breaks. Scheduled twice daily.
 */
class RefreshFxRate extends Command
{
    protected $signature = 'smartept:refresh-fx {--show : Show the stored rate and exit}';

    protected $description = 'Refresh the live USD->INR exchange rate used for USD pricing.';

    public function handle(): int
    {
        if ($this->option('show')) {
            $this->info('usd_inr_rate = ' . Setting::get('usd_inr_rate', 88)
                . ' (updated ' . (Setting::get('usd_inr_rate_updated_at') ?: 'never') . ')');

            return self::SUCCESS;
        }

        $rate = $this->fetchRate();
        if ($rate === null) {
            $this->warn('No live rate fetched — keeping last value (' . Setting::get('usd_inr_rate', 88) . ').');
            Log::warning('FX refresh failed; kept existing usd_inr_rate');

            return self::FAILURE;
        }

        $rate = round($rate, 2);
        Setting::set('usd_inr_rate', $rate);
        Setting::set('usd_inr_rate_updated_at', now()->toDateTimeString());
        $this->info('usd_inr_rate updated: 1 USD = ' . $rate . ' INR.');

        return self::SUCCESS;
    }

    /** Live USD->INR from free key-less feeds; sanity-bounded so a bad payload can't poison pricing. */
    private function fetchRate(): ?float
    {
        $sources = [
            fn () => (float) (Http::timeout(8)->get('https://open.er-api.com/v6/latest/USD')->json('rates.INR') ?? 0),
            fn () => (float) (Http::timeout(8)->get('https://api.frankfurter.app/latest', ['from' => 'USD', 'to' => 'INR'])->json('rates.INR') ?? 0),
        ];

        foreach ($sources as $get) {
            try {
                $rate = $get();
                if ($rate >= 40 && $rate <= 200) { // INR/USD realistically ~75-100
                    return $rate;
                }
            } catch (\Throwable $e) {
                // fall through to the next source
            }
        }

        return null;
    }
}
