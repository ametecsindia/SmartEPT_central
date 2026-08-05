<?php

namespace App\Console\Commands;

use App\Support\LandingRenderer;
use Illuminate\Console\Command;

class LandingVerify extends Command
{
    protected $signature = 'landing:verify';
    protected $description = 'Compare the DB-rendered landing page to public/landing.html byte-for-byte.';

    public function handle(): int
    {
        $file = public_path('landing.html');
        $orig = is_file($file) ? file_get_contents($file) : '';
        $db   = LandingRenderer::html();

        if ($db === $orig) {
            $this->info('Byte-identical: YES ('.strlen($db).' bytes). DB rendering matches the live file exactly.');
            return self::SUCCESS;
        }

        $this->error('Byte-identical: NO  (file='.strlen($orig).' bytes, db='.strlen($db).' bytes)');
        $n = min(strlen($orig), strlen($db));
        $i = 0;
        while ($i < $n && $orig[$i] === $db[$i]) { $i++; }
        $this->line('First difference at byte '.$i.':');
        $this->line('  file: ...'.substr($orig, max(0, $i - 40), 80).'...');
        $this->line('  db  : ...'.substr($db, max(0, $i - 40), 80).'...');
        $this->warn('Do NOT publish until this says YES. Report to Claude.');
        return self::FAILURE;
    }
}
