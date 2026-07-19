<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DownloadArtifact;
use App\Models\DownloadLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin management of the installer catalogue (Ejaz 19-Jul).
 * Upload OR attach an already-built file (from storage/app/downloads, where the
 * BUILD-*.bat scripts drop them), set a version + description + release notes,
 * and publish. Published rows appear on each client's Install & Downloads page.
 */
class DownloadApiController extends Controller
{
    private const ALLOWED_EXT = ['exe', 'msi', 'zip', 'dmg', 'pkg', 'deb', 'rpm', 'appimage', 'gz', 'tar', 'bin', 'run'];

    /** GET /admin/api/download-artifacts — catalogue + files on disk + upload limits. */
    public function index(): JsonResponse
    {
        $rows = DownloadArtifact::orderBy('sort')->orderBy('category')->orderBy('platform')->get()
            ->map(fn (DownloadArtifact $a) => $this->present($a));

        return response()->json([
            'data'            => $rows,
            'available_files' => $this->filesOnDisk(),
            'limits'          => [
                'upload_max' => ini_get('upload_max_filesize'),
                'post_max'   => ini_get('post_max_size'),
            ],
            'quota'           => DownloadLog::limits(),
            'log'             => $this->recentLog(),
            'tenant_stats'    => $this->tenantStats(),
        ]);
    }

    /**
     * POST /admin/api/download-limits — set the per-client download quotas.
     * Blank clears the override (falls back to the built-in default).
     */
    public function saveLimits(Request $request): JsonResponse
    {
        $data = $request->validate([
            'download_daily_free'   => ['nullable', 'integer', 'min:0', 'max:1000'],
            'download_daily_paid'   => ['nullable', 'integer', 'min:0', 'max:1000'],
            'download_monthly_free' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'download_monthly_paid' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ]);

        foreach (array_keys(DownloadLog::DEFAULTS) as $key) {
            if (array_key_exists($key, $data)) {
                \App\Models\Setting::set($key, $data[$key] === null ? '' : (string) $data[$key]);
            }
        }
        AuditLog::write('download.limits_updated', null, DownloadLog::limits());

        return response()->json(['ok' => true, 'quota' => DownloadLog::limits()]);
    }

    /**
     * POST /admin/api/download-artifacts            (create)
     * POST /admin/api/download-artifacts/{artifact} (update)
     * multipart/form-data — optional 'file' upload OR 'existing_file' name.
     */
    public function save(Request $request, ?DownloadArtifact $artifact = null): JsonResponse
    {
        // A body that exceeded post_max_size arrives empty — give a clear reason.
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            return response()->json(['message' =>
                'The file was too large for the server to accept. Increase upload_max_filesize and post_max_size '
                . 'in php.ini and restart, or drop the file into storage/app/downloads and choose “Use a file already on the server”.',
            ], 422);
        }

        $data = $request->validate([
            'category'      => ['required', 'in:agent,server'],
            'platform'      => ['nullable', 'in:windows,mac,linux'],
            'title'         => ['required', 'string', 'max:160'],
            'version'       => ['nullable', 'string', 'max:60'],
            'description'   => ['nullable', 'string', 'max:2000'],
            'notes'         => ['nullable', 'string', 'max:4000'],
            'existing_file' => ['nullable', 'string', 'max:255'],
            'file'          => ['nullable', 'file', 'max:1048576'], // 1 GB ceiling (php.ini may be lower)
        ]);

        $downloadsDir = storage_path('app/downloads');
        if (! is_dir($downloadsDir)) {
            @mkdir($downloadsDir, 0775, true);
        }

        $artifact ??= new DownloadArtifact();
        $filename = $artifact->filename;
        $size     = $artifact->size_bytes;
        $fileTouched = false;

        if ($request->hasFile('file')) {
            $f = $request->file('file');
            $ext = strtolower($f->getClientOriginalExtension());
            if (! in_array($ext, self::ALLOWED_EXT, true)) {
                return response()->json(['message' => 'Unsupported file type “.' . $ext . '”. Allowed: '
                    . implode(', ', self::ALLOWED_EXT) . '.'], 422);
            }
            $safe = $this->safeName($f->getClientOriginalName());
            $f->move($downloadsDir, $safe);
            $filename = $safe;
            $size = is_file($downloadsDir . '/' . $safe) ? filesize($downloadsDir . '/' . $safe) : null;
            $fileTouched = true;
        } elseif ($request->filled('existing_file')) {
            $base = basename($request->input('existing_file'));
            $path = $downloadsDir . '/' . $base;
            if (! is_file($path)) {
                return response()->json(['message' => 'That file is no longer in the downloads folder. Refresh and pick again.'], 422);
            }
            $filename = $base;
            $size = filesize($path);
            $fileTouched = true;
        }

        $publish = $request->boolean('is_published');
        if ($publish && ! $filename) {
            return response()->json(['message' => 'Attach a file (upload or pick an existing one) before publishing this download.'], 422);
        }

        $artifact->category    = $data['category'];
        $artifact->platform    = $data['platform'] ?? null;
        $artifact->title       = $data['title'];
        $artifact->version     = $data['version'] ?? null;
        $artifact->description = $data['description'] ?? null;
        $artifact->notes       = $data['notes'] ?? null;
        $artifact->filename    = $filename;
        $artifact->size_bytes  = $size;
        $artifact->is_published = $publish;

        if (! $artifact->exists) {
            $artifact->slug = $this->uniqueSlug($data['category'], $data['platform'] ?? 'x');
            $artifact->sort = (int) (DownloadArtifact::max('sort') + 10);
        }
        if ($fileTouched) {
            $artifact->uploaded_by = optional(auth('admin')->user())->name;
        }
        $artifact->save();

        AuditLog::write('download.saved', $artifact, [
            'slug' => $artifact->slug, 'published' => $artifact->is_published, 'file' => $artifact->filename,
        ]);

        return response()->json(['data' => $this->present($artifact->fresh())]);
    }

    /** DELETE /admin/api/download-artifacts/{artifact} — remove the entry (leaves the file on disk). */
    public function destroy(DownloadArtifact $artifact): JsonResponse
    {
        $slug = $artifact->slug;
        $artifact->delete();
        AuditLog::write('download.deleted', null, ['slug' => $slug]);

        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------------

    private function present(DownloadArtifact $a): array
    {
        return [
            'id'           => $a->id,
            'slug'         => $a->slug,
            'category'     => $a->category,
            'platform'     => $a->platform,
            'title'        => $a->title,
            'version'      => $a->version,
            'description'  => $a->description,
            'notes'        => $a->notes,
            'filename'     => $a->filename,
            'size_human'   => $a->humanSize(),
            'file_present' => (bool) $a->filePath(),
            'is_published' => $a->is_published,
            'uploaded_by'  => $a->uploaded_by,
            'updated_at'   => optional($a->updated_at)->toDateTimeString(),
        ];
    }

    /** Most recent downloads (who / what / when / from where). */
    private function recentLog(): array
    {
        return DownloadLog::latest('id')->limit(100)->get()->map(fn (DownloadLog $l) => [
            'tenant_id'  => $l->tenant_id,
            'tenant'     => $l->tenant_name ?: ('#' . $l->tenant_id),
            'artifact'   => $l->artifact_title ?: $l->artifact_slug,
            'platform'   => $l->platform,
            'ip'         => $l->ip,
            'at'         => optional($l->created_at)->toDateTimeString(),
        ])->all();
    }

    /** Per-tenant cumulative totals (all-time / this month / today / last). */
    private function tenantStats(): array
    {
        $monthStart = now()->startOfMonth();
        $today = now()->toDateString();

        $month = DownloadLog::where('created_at', '>=', $monthStart)
            ->selectRaw('tenant_id, count(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id');
        $day = DownloadLog::whereDate('created_at', $today)
            ->selectRaw('tenant_id, count(*) c')->groupBy('tenant_id')->pluck('c', 'tenant_id');

        return DownloadLog::selectRaw('tenant_id, max(tenant_name) tenant_name, count(*) total, max(created_at) last_at')
            ->groupBy('tenant_id')
            ->orderByDesc('total')
            ->limit(200)
            ->get()
            ->map(fn ($r) => [
                'tenant_id'  => $r->tenant_id,
                'tenant'     => $r->tenant_name ?: ('#' . $r->tenant_id),
                'total'      => (int) $r->total,
                'this_month' => (int) ($month[$r->tenant_id] ?? 0),
                'today'      => (int) ($day[$r->tenant_id] ?? 0),
                'last_at'    => $r->last_at,
            ])->all();
    }

    /** Installer files currently sitting in storage/app/downloads. */
    private function filesOnDisk(): array
    {
        $dir = storage_path('app/downloads');
        $out = [];
        foreach (glob($dir . '/*') ?: [] as $p) {
            if (! is_file($p)) {
                continue;
            }
            $bytes = filesize($p);
            $out[] = ['name' => basename($p), 'size_human' => $this->human((int) $bytes)];
        }

        return $out;
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);

        return trim($name, '._') ?: ('download_' . time());
    }

    private function uniqueSlug(string $category, string $platform): string
    {
        $base = $category . '-' . $platform;
        $slug = $base;
        $i = 2;
        while (DownloadArtifact::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }

        return $slug;
    }

    private function human(int $bytes): string
    {
        foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $unit => $s) {
            if ($bytes >= $s) {
                return number_format($bytes / $s, 1) . ' ' . $unit;
            }
        }

        return $bytes . ' B';
    }
}
