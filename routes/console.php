<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Money automation (upgraded 6-Aug-2026, Ejaz-approved): renewal reminders
// (T-30/15/7/3/1/0 + daily grace ladder), trial reminders + expiry flips,
// purge close-out, abandoned-buy rescue (~3h), quote-expiry chaser and the
// MD daily money digest (first run after 07:00). EVERYTHING is deduped via
// mail_logs subjects, so running hourly never double-sends — hourly is what
// makes the abandoned-buy rescue timely.
Schedule::command('smartept:dunning')->hourly();

// Scheduler heartbeat (Ametecs troubleshooting standard): stamps a cache key every minute
// so Help -> System Health can tell whether "php artisan schedule:run" is actually running.
Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put('smartept:scheduler_heartbeat', now()->toDateTimeString(), 900);
})->everyMinute()->name('central-scheduler-heartbeat')->withoutOverlapping();
