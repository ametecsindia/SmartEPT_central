<?php

namespace App\Console\Commands;

use App\Models\LandingSection;
use App\Models\LandingVersion;
use App\Support\LandingRenderer;
use App\Support\LandingSync;
use Illuminate\Console\Command;

class LandingPublish extends Command
{
    protected $signature = 'landing:publish {--note= : optional note for the version history}';
    protected $description = 'Render the landing page from the DB and write it to public/landing.html (backup + version snapshot).';

    public function handle(): int
    {
        $file = public_path('landing.html');

        // 31-Aug-2026: never publish an out-of-date snapshot over newer code.
        if ($reason = LandingSync::guard()) {
            $this->error('Refusing to publish: '.$reason);
            return self::FAILURE;
        }

        $html = LandingRenderer::html();

        if (trim($html) === '') {
            $this->error('Refusing to publish empty output. Run landing:import first.');
            return self::FAILURE;
        }
        if (is_file($file)) {
            @copy($file, $file.'.bak-'.date('Ymd-His').'-prepublish');
        }
        file_put_contents($file, $html);
        LandingSync::stamp($file); // the DB and the file are in step again

        LandingVersion::create([
            'snapshot'     => LandingSection::orderBy('sort')->get(['key', 'title', 'type', 'html', 'sort', 'is_visible', 'is_layout'])->toJson(),
            'note'         => (string) ($this->option('note') ?? ''),
            'published_at' => now(),
        ]);

        $this->info('Published '.strlen($html).' bytes to public/landing.html (backup + version snapshot saved).');
        return self::SUCCESS;
    }
}
