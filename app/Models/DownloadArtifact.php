<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A publishable installer (Employee Agent per-OS, or the Admin Server).
 * The actual file lives in storage/app/downloads/<filename>.
 */
class DownloadArtifact extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_published' => 'boolean',
        'size_bytes'   => 'integer',
        'sort'         => 'integer',
    ];

    /**
     * The four slots a client's Install & Downloads page can show, and the
     * category+platform that fills each one.
     */
    public const SLOTS = [
        'agent-windows'  => ['agent', 'windows'],
        'agent-mac'      => ['agent', 'mac'],
        'agent-linux'    => ['agent', 'linux'],
        'server-windows' => ['server', 'windows'],
    ];

    /**
     * The live artifact for a slot — resolved by WHAT THE ROW IS, not by its slug.
     *
     * 1-Sep-2026: the portal looked each slot up by a hard-coded slug, but a slug
     * is assigned once at creation (uniqueSlug) and never realigned when the row
     * is edited. So "+ Add download" produced `server-windows-2` / `-3`, which no
     * client could ever see, and a row created as a macOS agent and later switched
     * to the Admin Server kept the slug `agent-mac` — offering the server zip in
     * the macOS agent slot. Category + platform are what the row actually IS, and
     * they follow the operator's edits.
     *
     * Newest published row carrying a file wins, so duplicates are harmless.
     */
    public static function forSlot(string $slug): ?self
    {
        [$category, $platform] = self::SLOTS[$slug] ?? [null, null];
        if (! $category) {
            return null;
        }

        return static::where('category', $category)
            ->where('platform', $platform)
            ->where('is_published', true)
            ->whereNotNull('filename')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->first();
    }

    /** Absolute path to the attached file, or null if none / missing on disk. */
    public function filePath(): ?string
    {
        if (! $this->filename) {
            return null;
        }
        $path = storage_path('app/downloads/' . $this->filename);

        return is_file($path) ? $path : null;
    }

    public function humanSize(): ?string
    {
        $bytes = $this->size_bytes;
        if (! $bytes) {
            $p = $this->filePath();
            $bytes = $p ? filesize($p) : null;
        }
        if (! $bytes) {
            return null;
        }
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $s) {
            if ($bytes >= $s) {
                return number_format($bytes / $s, 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
