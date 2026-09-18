<?php

namespace App\Console\Commands;

use App\Models\Licence;
use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Standard/Enforcer/Commander (18-Sep-2026): READ-ONLY preview of what
 * PlanTierSeeder would do to every EXISTING licence, meant to be run BEFORE
 * seeding so Ejaz can see the impact first. Mirrors PlanTierSeeder's own
 * backfill rule exactly — whereNull('tier')->where('active', true)->update
 * (['tier' => 'standard']) — without writing anything. A plan that would be
 * left untagged (inactive, so the seeder skips it) is flagged explicitly
 * rather than discovered later.
 *
 * Run: php artisan smartept:plan-tier-migration-report [--csv=path.csv]
 * Requires the plans.tier column to exist (run `php artisan migrate` first),
 * but must run BEFORE `php artisan db:seed --class=PlanTierSeeder`.
 */
class PlanTierMigrationReport extends Command
{
    protected $signature = 'smartept:plan-tier-migration-report {--csv= : Also write a CSV export to this path}';
    protected $description = 'Read-only preview: which tier every existing licence would end up on after PlanTierSeeder runs';

    public function handle(): int
    {
        if (! Schema::hasColumn('plans', 'tier')) {
            $this->error('The plans.tier column does not exist yet. Run `php artisan migrate` first, '
                . 'then this report, then `php artisan db:seed --class=PlanTierSeeder` (see §6 of the project doc).');

            return self::FAILURE;
        }

        $plans = Plan::withCount('licences')->orderBy('sort')->orderBy('id')->get();
        $rows = [];

        foreach ($plans as $plan) {
            // Same rule as PlanTierSeeder::run(): an active plan with no tier
            // yet becomes 'standard'; an inactive one is left untagged.
            $futureTier = $plan->tier ?? ($plan->active ? 'standard' : null);

            $activeLicences = Licence::where('plan_id', $plan->id)->where('status', 'active')->count();

            $rows[] = [
                $plan->id,
                $plan->code,
                $plan->name,
                $plan->active ? 'yes' : 'no',
                $plan->tier ?? '—',
                $futureTier ?? 'STAYS UNTAGGED',
                $plan->licences_count,
                $activeLicences,
            ];
        }

        $this->table(
            ['Plan ID', 'Code', 'Name', 'Active', 'Tier now', 'Tier after seeding', 'Licences', 'Active licences'],
            $rows
        );

        $risk = $plans->filter(fn ($p) => is_null($p->tier) && ! $p->active && $p->licences_count > 0);
        if ($risk->isNotEmpty()) {
            $this->newLine();
            $this->warn('These plans have licences but would stay UNTAGGED (tier=null) because they are inactive — '
                . 'PlanTierSeeder only backfills active plans. Nothing breaks for an existing licence on an untagged '
                . 'plan (it works exactly as it does today); it just won\'t be pickable as "Standard" wherever a tier '
                . 'is later required. Reactivate the plan first if you want its licences folded into Standard:');
            foreach ($risk as $p) {
                $this->line("  - Plan #{$p->id} ({$p->code}, {$p->name}) — {$p->licences_count} licence(s)");
            }
        }

        if ($csv = $this->option('csv')) {
            $fh = fopen($csv, 'w');
            fputcsv($fh, ['Plan ID', 'Code', 'Name', 'Active', 'Tier now', 'Tier after seeding', 'Licences', 'Active licences']);
            foreach ($rows as $r) {
                fputcsv($fh, $r);
            }
            fclose($fh);
            $this->info("CSV written to {$csv}");
        }

        return self::SUCCESS;
    }
}
