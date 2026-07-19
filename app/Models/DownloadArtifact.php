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
