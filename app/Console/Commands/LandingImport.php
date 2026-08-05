<?php

namespace App\Console\Commands;

use App\Models\LandingSection;
use Illuminate\Console\Command;

/**
 * landing:import — lifts the CURRENT public/landing.html into landing_sections
 * VERBATIM. Each major HTML comment-marked section becomes one editable row;
 * everything before the first marker is stored as the '__head__' layout chrome.
 * It then verifies that concatenating the rows reproduces the file byte-for-byte,
 * guaranteeing the page can be re-rendered with ZERO visual change (Ejaz's hard
 * rule: never disturb current content). Non-destructive: does not touch the live
 * page — the '/' route keeps serving the static file until Task 4 flips rendering.
 */
class LandingImport extends Command
{
    protected $signature = 'landing:import {--force : wipe landing_sections and re-import}';
    protected $description = 'Import the current public/landing.html into landing_sections (verbatim, non-destructive).';

    public function handle(): int
    {
        $file = public_path('landing.html');
        if (! is_file($file)) {
            $this->error('public/landing.html not found.');
            return self::FAILURE;
        }

        if (LandingSection::query()->exists() && ! $this->option('force')) {
            $this->warn('landing_sections already has rows. Use --force to wipe & re-import. Nothing changed.');
            return self::SUCCESS;
        }
        if ($this->option('force')) {
            LandingSection::query()->delete();
            $this->line('Cleared existing landing_sections (--force).');
        }

        $html = file_get_contents($file);

        // Split on major HTML section comment markers (e.g. <!-- ===== HERO ===== -->), keeping them.
        $parts = preg_split('/(<!--\s*={5,}.*?={5,}\s*-->)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        $head = array_shift($parts) ?? '';
        $sections = [];
        $cur = -1;
        foreach ($parts as $chunk) {
            if (preg_match('/^<!--\s*={5,}\s*(.*?)\s*={5,}\s*-->$/s', $chunk, $m)) {
                $name = trim($m[1]);
                // A closing marker (e.g. "/HIDDEN LOSS") stays inside the current section.
                if ($name === '' || str_starts_with($name, '/')) {
                    if ($cur >= 0) { $sections[$cur]['html'] .= $chunk; }
                    else { $head .= $chunk; }
                    continue;
                }
                $sections[] = ['title' => $name, 'key' => $this->slug($name), 'html' => $chunk];
                $cur = count($sections) - 1;
            } else {
                if ($cur >= 0) { $sections[$cur]['html'] .= $chunk; }
                else { $head .= $chunk; }
            }
        }

        $sort = 0;
        LandingSection::create([
            'key' => '__head__', 'title' => 'Page head & chrome', 'type' => 'layout',
            'html' => $head, 'sort' => $sort++, 'is_visible' => true, 'is_layout' => true,
        ]);

        $seen = ['__head__' => true];
        foreach ($sections as $s) {
            $key = $s['key']; $n = 1;
            while (isset($seen[$key])) { $key = $s['key'].'_'.(++$n); }
            $seen[$key] = true;
            LandingSection::create([
                'key' => $key, 'title' => $s['title'], 'type' => 'raw',
                'html' => $s['html'], 'sort' => $sort++, 'is_visible' => true, 'is_layout' => false,
            ]);
        }

        // Proof of "never disturb": rows in order must equal the original file exactly.
        $rebuilt = LandingSection::orderBy('sort')->pluck('html')->implode('');
        $this->info(sprintf('Imported %d editable sections + head chrome:', count($sections)));
        foreach (LandingSection::orderBy('sort')->get(['key', 'is_layout']) as $r) {
            $this->line('   - '.$r->key.($r->is_layout ? '   (layout — hidden from editor)' : ''));
        }

        if ($rebuilt === $html) {
            $this->info('Byte-identical reconstruction: YES  — safe to wire rendering (Task 4).');
            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Byte-identical reconstruction: NO (original %d bytes, rebuilt %d). Do NOT switch rendering — report this to Claude.',
            strlen($html), strlen($rebuilt)
        ));
        return self::FAILURE;
    }

    private function slug(string $s): string
    {
        $s = strtolower(trim($s, " /\t\n\r"));
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        return trim($s, '_') ?: 'section';
    }
}
