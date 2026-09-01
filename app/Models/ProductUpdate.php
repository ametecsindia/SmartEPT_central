<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One published build of an on-prem product, offered to servers that phone home.
 * The ZIP itself lives in storage/app/updates — never in public/.
 */
class ProductUpdate extends Model
{
    protected $fillable = ['product', 'version', 'min_version', 'channel', 'title', 'notes',
        'filename', 'size_bytes', 'sha256', 'signature', 'is_published', 'released_at', 'uploaded_by'];

    protected $casts = ['is_published' => 'boolean', 'released_at' => 'datetime', 'size_bytes' => 'integer'];

    public const DIR = 'app/updates';

    public function path(): ?string
    {
        if (! $this->filename) {
            return null;
        }
        $path = storage_path(self::DIR . '/' . $this->filename);

        return is_file($path) ? $path : null;
    }

    public function fileExists(): bool
    {
        return $this->path() !== null;
    }

    /**
     * The build to offer a server currently running $current, or null.
     *
     * Rules, in order: published, file actually on disk, strictly newer than
     * what the server runs, and the server is not below the build's own
     * min_version (a 1.2 install that must pass through 1.5 first is told
     * nothing rather than handed a package that would break it).
     */
    public static function latestFor(string $current, string $product = 'smartept', string $channel = 'stable'): ?self
    {
        return static::query()
            ->where('product', $product)
            ->where('channel', $channel)
            ->where('is_published', true)
            ->get()
            ->filter(fn (self $u) => $u->fileExists()
                && version_compare($u->version, $current, '>')
                && ($u->min_version === null || $u->min_version === ''
                    || version_compare($current, $u->min_version, '>=')))
            ->sort(fn (self $a, self $b) => version_compare($a->version, $b->version))
            ->last();
    }
}
