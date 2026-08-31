<?php

namespace App\Support;

use App\Models\LandingSection;
use App\Models\Setting;
use Illuminate\Support\Facades\Artisan;

/**
 * Keeps landing_sections in step with public/landing.html.
 *
 * 31-Aug-2026 — smartept.com served a 5-Aug page for two weeks. Cause: the CMS
 * snapshot (landing_sections) was taken on 5-Aug-2026 and never refreshed, so a
 * later Publish re-rendered the live page from that stale snapshot and silently
 * discarded every code change made to public/landing.html since — progressive
 * On-Premise pricing, the users= handoff to /buy, the lead-form honeypot and the
 * Razorpay copy all reverted with no warning anywhere.
 *
 * The rule this class enforces:
 *   the FILE is the source of truth for code, the DB is the source of truth for
 *   content edits, and a deployment that changes the file must retake the
 *   snapshot BEFORE anything is published from it.
 */
class LandingSync
{
    public const SHA_KEY = 'landing_source_sha';
    public const AT_KEY = 'landing_source_at';

    /** Record the landing.html we are currently in step with. */
    public static function stamp(?string $file = null): void
    {
        $file = $file ?: public_path('landing.html');
        Setting::set(self::SHA_KEY, is_file($file) ? hash_file('sha256', $file) : '');
        Setting::set(self::AT_KEY, now()->toDateTimeString());
    }

    /** True when landing.html changed outside the CMS — i.e. a code deployment. */
    public static function fileChangedOutsideCms(?string $file = null): bool
    {
        $file = $file ?: public_path('landing.html');
        if (! is_file($file)) {
            return false;
        }
        $known = (string) Setting::get(self::SHA_KEY, '');

        // No stamp yet = a database that predates this guard. Treat it as changed
        // so the very first publish resyncs instead of shipping a stale snapshot.
        return $known === '' || $known !== hash_file('sha256', $file);
    }

    /** Keys of sections a human edited in the CMS since the last sync. */
    public static function pendingEdits()
    {
        $at = (string) Setting::get(self::AT_KEY, '');
        if ($at === '') {
            return collect();
        }

        return LandingSection::where('updated_at', '>', $at)->pluck('key');
    }

    /**
     * Run this before every publish.
     *
     * @return string|null null when publishing is safe, otherwise the reason it
     *                     must be refused (safe to show an admin verbatim).
     */
    public static function guard(): ?string
    {
        // Fresh deployment, empty CMS: import the current file (12-Aug-2026 self-heal).
        if (LandingSection::count() === 0) {
            Artisan::call('landing:import');
            self::stamp();

            return null;
        }

        if (! self::fileChangedOutsideCms()) {
            return null; // snapshot already matches the file — nothing to do
        }

        $pending = self::pendingEdits();
        if ($pending->isNotEmpty()) {
            return 'public/landing.html was changed by a deployment, and these sections were also '
                .'edited in the CMS since the last sync: '.$pending->implode(', ').'. Publishing now '
                .'would throw one of the two away. Run `php artisan landing:import --force`, re-apply '
                .'those section edits, then publish.';
        }

        // The file moved and nobody edited content — a deployment. Retake the
        // snapshot so it is the NEW code that gets published, never the old one.
        Artisan::call('landing:import', ['--force' => true]);
        self::stamp();

        return null;
    }
}
