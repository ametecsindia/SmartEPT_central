<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// R2-2: daily dunning & lifecycle — renewal reminders (T-30/7/1/0), trial
// reminders (T-3/1/0) + trial→expired flip, lapsed-licence expiry, purge_after
// close-out. Deduped via mail_logs; safe to run manually any time.
Schedule::command('smartept:dunning')->dailyAt('08:00');
