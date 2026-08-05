<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LandingMedia;
use App\Models\Setting;
use App\Models\LandingSection;
use App\Models\LandingVersion;
use App\Support\LandingRenderer;
use Illuminate\Http\Request;

/**
 * Landing Page editor (super-admin). Isolated from the main console SPA.
 * Sections are stored verbatim; editing/reordering/showing-hiding never alters
 * other sections. '/' keeps serving the static file until Publish regenerates it.
 */
class LandingAdminController extends Controller
{
    public function page()
    {
        return view('admin.landing', ['user' => auth('admin')->user()]);
    }

    public function sections()
    {
        return LandingSection::orderBy('sort')
            ->get(['id', 'key', 'title', 'type', 'html', 'sort', 'is_visible', 'is_layout']);
    }

    public function updateSection(Request $r, LandingSection $section)
    {
        $data = $r->validate([
            'title'      => ['sometimes', 'string', 'max:200'],
            'html'       => ['sometimes', 'string'],
            'is_visible' => ['sometimes', 'boolean'],
        ]);
        if (isset($data['is_visible']) && $section->is_layout) {
            unset($data['is_visible']); // layout chrome is always rendered
        }
        $data['updated_by'] = auth('admin')->id();
        $section->update($data);

        return $section->only(['id', 'key', 'title', 'sort', 'is_visible', 'is_layout']);
    }

    public function reorder(Request $r)
    {
        $ids = $r->validate([
            'order'   => ['required', 'array'],
            'order.*' => ['integer'],
        ])['order'];
        foreach ($ids as $i => $id) {
            LandingSection::where('id', $id)->update(['sort' => $i]);
        }
        return ['ok' => true];
    }

    public function publish(Request $r)
    {
        $html = LandingRenderer::html();
        if (trim($html) === '') {
            return response()->json(['ok' => false, 'error' => 'Render was empty — nothing published.'], 422);
        }
        $file = public_path('landing.html');
        if (is_file($file)) {
            @copy($file, $file.'.bak-'.date('Ymd-His').'-prepublish');
        }
        file_put_contents($file, $html);

        LandingVersion::create([
            'snapshot'     => LandingSection::orderBy('sort')
                ->get(['key', 'title', 'type', 'html', 'sort', 'is_visible', 'is_layout'])->toJson(),
            'note'         => (string) $r->input('note', ''),
            'published_by' => auth('admin')->id(),
            'published_at' => now(),
        ]);

        AuditLog::write('landing.publish', null, ['bytes' => strlen($html), 'note' => (string) $r->input('note', '')]);

        return ['ok' => true, 'bytes' => strlen($html)];
    }

    public function versions()
    {
        return LandingVersion::orderByDesc('id')->limit(50)
            ->get(['id', 'note', 'published_by', 'published_at', 'created_at']);
    }

    public function rollback(LandingVersion $version)
    {
        $rows = json_decode((string) $version->snapshot, true) ?: [];
        if (! $rows) {
            return response()->json(['ok' => false, 'error' => 'Empty snapshot.'], 422);
        }
        foreach ($rows as $row) {
            LandingSection::updateOrCreate(['key' => $row['key']], [
                'title'      => $row['title'] ?? '',
                'type'       => $row['type'] ?? 'raw',
                'html'       => $row['html'] ?? '',
                'sort'       => $row['sort'] ?? 0,
                'is_visible' => $row['is_visible'] ?? true,
                'is_layout'  => $row['is_layout'] ?? false,
            ]);
        }
        return ['ok' => true, 'restored' => count($rows)];
    }

    // ---------- Media library ----------
    public function media()
    {
        return LandingMedia::orderByDesc('id')->get();
    }

    public function mediaUpload(Request $r)
    {
        $r->validate(['file' => ['required', 'file', 'max:8192', 'mimes:jpg,jpeg,png,webp,gif,svg,ico']]);
        $file = $r->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());
        $path = $file->store('landing', 'public');
        $url  = \Storage::disk('public')->url($path);
        $kind = $ext === 'svg' ? 'icon-svg' : 'image';
        $w = $h = null;
        if ($kind === 'image') {
            $sz = @getimagesize(\Storage::disk('public')->path($path));
            if ($sz) { $w = $sz[0]; $h = $sz[1]; }
        }
        return LandingMedia::create([
            'disk' => 'public', 'path' => $path, 'url' => $url, 'kind' => $kind,
            'alt' => (string) $r->input('alt', ''), 'width' => $w, 'height' => $h,
            'bytes' => $file->getSize(), 'uploaded_by' => auth('admin')->id(),
        ]);
    }

    public function mediaUpdate(Request $r, LandingMedia $media)
    {
        $media->update($r->validate(['alt' => ['sometimes', 'string', 'max:255']]));
        return $media;
    }

    public function mediaDelete(LandingMedia $media)
    {
        try { \Storage::disk($media->disk)->delete($media->path); } catch (\Throwable $e) {}
        $media->delete();
        return ['ok' => true];
    }

    // ---------- SEO & tracking ----------
    private const SEO_KEYS = ['seo_title','seo_description','seo_canonical','seo_robots','seo_og_image','seo_site_name','seo_twitter_handle','seo_favicon','seo_logo','track_ga4','track_gtm','track_fb_pixel','track_google_ads','track_head_html','track_body_html','track_conversion_html','thankyou_headline','thankyou_message'];

    public function seo()
    {
        $out = [];
        foreach (self::SEO_KEYS as $k) { $out[$k] = (string) Setting::get($k, ''); }
        return $out;
    }

    public function saveSeo(Request $r)
    {
        foreach (self::SEO_KEYS as $k) {
            if ($r->has($k)) { Setting::set($k, (string) $r->input($k, '')); }
        }
        return ['ok' => true];
    }
}
