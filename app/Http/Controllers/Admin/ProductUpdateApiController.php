<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ProductUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Super-admin "Upload Update" screen (Ejaz, 1-Sep-2026).
 *
 * Publishes a versioned update package for on-prem SmartEPT servers. A server
 * pressing "Check for Update" on its Licence screen asks
 * POST /api/v1/updates/check and is offered the newest published build here.
 *
 * Two ways in, because an update ZIP is far bigger than a web upload usually
 * survives: upload through the browser, OR drop the file into
 * storage/app/updates over FTP/RDP and attach it by name. The second path is
 * the reliable one on a server whose nginx caps client_max_body_size.
 */
class ProductUpdateApiController extends Controller
{
    private const ALLOWED_EXT = ['zip'];

    /** GET /admin/api/product-updates */
    public function index(): JsonResponse
    {
        $rows = ProductUpdate::orderBy('product')->get()
            ->sortByDesc(fn (ProductUpdate $u) => $u->version, SORT_NATURAL)
            ->values()
            ->map(fn (ProductUpdate $u) => $this->present($u));

        return response()->json([
            'data'            => $rows,
            'available_files' => $this->filesOnDisk(),
            'limits'          => [
                'upload_max' => ini_get('upload_max_filesize'),
                'post_max'   => ini_get('post_max_size'),
            ],
            'dir'             => 'storage/app/updates',
        ]);
    }

    /**
     * POST /admin/api/product-updates             (create)
     * POST /admin/api/product-updates/{update}    (update)
     * multipart/form-data — optional 'file' upload OR 'existing_file' name.
     */
    public function save(Request $request, ?ProductUpdate $update = null): JsonResponse
    {
        // A body over post_max_size arrives empty — say why instead of "Save failed".
        if (empty($_POST) && empty($_FILES) && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            return response()->json(['message' =>
                'The package was too large for the server to accept. Raise upload_max_filesize and post_max_size '
                . 'in php.ini (and client_max_body_size in nginx), or drop the ZIP into storage/app/updates '
                . 'and choose "Use a file already on the server".',
            ], 422);
        }

        $data = $request->validate([
            'product'       => ['nullable', 'string', 'max:40'],
            'version'       => ['required', 'string', 'max:60', 'regex:/^\d+(\.\d+){0,3}([A-Za-z0-9\.\-]*)$/'],
            'min_version'   => ['nullable', 'string', 'max:60', 'regex:/^\d+(\.\d+){0,3}([A-Za-z0-9\.\-]*)$/'],
            'channel'       => ['nullable', 'in:stable,beta'],
            'title'         => ['nullable', 'string', 'max:160'],
            'notes'         => ['nullable', 'string', 'max:8000'],
            'existing_file' => ['nullable', 'string', 'max:255'],
            'file'          => ['nullable', 'file', 'max:2097152'], // 2 GB ceiling; php.ini is usually lower
        ]);

        $product = $data['product'] ?? 'smartept';
        $dir     = storage_path(ProductUpdate::DIR);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (! is_writable($dir)) {
            return response()->json(['message' => 'storage/app/updates is not writable by the web server. '
                . 'Fix the folder permissions and try again.'], 422);
        }

        // Version is the identity of a build — never two rows for one version.
        $clash = ProductUpdate::where('product', $product)->where('version', $data['version'])
            ->when($update?->exists, fn ($q) => $q->where('id', '!=', $update->id))->first();
        if ($clash) {
            return response()->json(['message' => 'Version ' . $data['version'] . ' already exists in the catalogue. '
                . 'Edit that row instead, or use a new version number.'], 422);
        }

        $update ??= new ProductUpdate();
        $filename = $update->filename;
        $fileTouched = false;

        if ($request->hasFile('file')) {
            $f = $request->file('file');
            $ext = strtolower($f->getClientOriginalExtension());
            if (! in_array($ext, self::ALLOWED_EXT, true)) {
                return response()->json(['message' => 'An update package must be a .zip built by RELEASE.bat.'], 422);
            }
            $safe = $this->safeName($f->getClientOriginalName());
            $f->move($dir, $safe);
            $filename = $safe;
            $fileTouched = true;
        } elseif ($request->filled('existing_file')) {
            $base = basename($request->input('existing_file'));
            if (! is_file($dir . '/' . $base)) {
                return response()->json(['message' => 'That file is no longer in storage/app/updates. Refresh and pick again.'], 422);
            }
            $filename = $base;
            $fileTouched = true;
        }

        $update->fill([
            'product'     => $product,
            'version'     => $data['version'],
            'min_version' => $data['min_version'] ?? null,
            'channel'     => $data['channel'] ?? 'stable',
            'title'       => $data['title'] ?? null,
            'notes'       => $data['notes'] ?? null,
            'filename'    => $filename,
        ]);
        $update->uploaded_by = $update->uploaded_by ?: (auth('admin')->user()->name ?? 'admin');
        $update->released_at = $update->released_at ?: now();

        // The hash is what the on-prem updater re-checks after downloading, so it
        // is recomputed whenever the file changes — a stale hash aborts every install.
        if ($fileTouched && $update->path() === null && $filename) {
            $update->filename = $filename; // keep the name even if the file vanished mid-request
        }
        if ($fileTouched) {
            $path = storage_path(ProductUpdate::DIR . '/' . $filename);
            $update->size_bytes = is_file($path) ? filesize($path) : null;
            $update->sha256     = is_file($path) ? hash_file('sha256', $path) : null;
        }

        if ($request->has('_pub')) {
            $update->is_published = (bool) $request->input('_pub');
        }
        if ($update->is_published && ! $update->fileExists()) {
            $update->is_published = false;
        }

        $update->save();
        AuditLog::write('update.saved', null, [
            'product' => $update->product, 'version' => $update->version,
            'file' => $update->filename, 'published' => $update->is_published,
        ]);

        return response()->json(['ok' => true, 'data' => $this->present($update)]);
    }

    /** POST /admin/api/product-updates/{update}/publish — flip live/draft. */
    public function publish(Request $request, ProductUpdate $update): JsonResponse
    {
        $publish = (bool) $request->input('published', true);

        if ($publish && ! $update->fileExists()) {
            return response()->json(['message' => 'This row has no package file on the server, so it cannot be published. '
                . 'Attach the ZIP first.'], 422);
        }

        $update->is_published = $publish;
        $update->save();
        AuditLog::write($publish ? 'update.published' : 'update.unpublished', null,
            ['product' => $update->product, 'version' => $update->version]);

        return response()->json(['ok' => true, 'data' => $this->present($update)]);
    }

    /** DELETE /admin/api/product-updates/{update} — catalogue row only; the ZIP stays on disk. */
    public function destroy(ProductUpdate $update): JsonResponse
    {
        AuditLog::write('update.removed', null, ['product' => $update->product, 'version' => $update->version]);
        $update->delete();

        return response()->json(['ok' => true]);
    }

    // ---------------------------------------------------------------- helpers

    private function present(ProductUpdate $u): array
    {
        return [
            'id'           => $u->id,
            'product'      => $u->product,
            'version'      => $u->version,
            'min_version'  => $u->min_version,
            'channel'      => $u->channel,
            'title'        => $u->title,
            'notes'        => $u->notes,
            'filename'     => $u->filename,
            'file_present' => $u->fileExists(),
            'size_bytes'   => $u->size_bytes,
            'size_human'   => $u->size_bytes ? $this->human($u->size_bytes) : null,
            'sha256'       => $u->sha256,
            'is_published' => (bool) $u->is_published,
            'uploaded_by'  => $u->uploaded_by,
            'released_at'  => $u->released_at?->format('d-M-Y H:i'),
            'updated_at'   => $u->updated_at?->format('d-M-Y H:i'),
        ];
    }

    /** ZIPs sitting in storage/app/updates that were put there outside the browser. */
    private function filesOnDisk(): array
    {
        $dir = storage_path(ProductUpdate::DIR);
        if (! is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (glob($dir . '/*.zip') ?: [] as $path) {
            $out[] = ['name' => basename($path), 'size_human' => $this->human((int) filesize($path))];
        }

        return $out;
    }

    private function safeName(string $name): string
    {
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($name));

        return $base === '' ? 'update.zip' : $base;
    }

    private function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < 3) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i ? 1 : 0) . ' ' . $units[$i];
    }
}
