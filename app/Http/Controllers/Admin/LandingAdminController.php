<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\LandingMedia;
use App\Models\Setting;
use App\Models\LandingSection;
use App\Models\LandingVersion;
use App\Support\LandingRenderer;
use App\Support\LandingSync;
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
        // Self-heal (12-Aug-2026, extended 31-Aug-2026): on a fresh deployment
        // landing_sections is empty and "Publish -> make live" rendered EMPTY; worse,
        // once the rows existed they were never refreshed, so a publish re-rendered
        // the page from a month-old snapshot and silently reverted shipped code.
        // LandingSync re-imports after a deployment, and refuses when re-importing
        // would discard CMS edits instead of quietly picking a winner.
        if ($reason = LandingSync::guard()) {
            return response()->json(['ok' => false, 'error' => $reason], 409);
        }

        $html = LandingRenderer::html();
        if (trim($html) === '') {
            return response()->json(['ok' => false, 'error' => 'Render was empty — nothing published.'], 422);
        }
        $file = public_path('landing.html');
        if (is_file($file)) {
            @copy($file, $file.'.bak-'.date('Ymd-His').'-prepublish');
        }
        file_put_contents($file, $html);
        LandingSync::stamp($file); // the DB and the file are in step again

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
        $secs = LandingSection::orderBy('sort')->get(['title', 'html']);
        return LandingMedia::orderByDesc('id')->get()->map(function ($m) use ($secs) {
            $url = (string) $m->url;
            $bn  = $url !== '' ? basename((string) (parse_url($url, PHP_URL_PATH) ?: $url)) : '';
            $used = $secs->filter(function ($s) use ($url, $bn) {
                return ($url !== '' && str_contains((string) $s->html, $url))
                    || ($bn !== '' && str_contains((string) $s->html, $bn));
            })->pluck('title')->values();
            $a = $m->toArray();
            $a['used_in'] = $used;
            return $a;
        });
    }

    /** Pull images already referenced in the page HTML into the media library (skips embedded data: images). */
    public function mediaScan()
    {
        $existing = LandingMedia::pluck('url')->all();
        $added = 0;
        foreach (LandingSection::get(['html']) as $s) {
            if (preg_match_all('#<img\b[^>]*\bsrc="([^"]+)"#i', (string) $s->html, $mm)) {
                foreach ($mm[1] as $src) {
                    if (str_starts_with($src, 'data:')) { continue; }
                    if (in_array($src, $existing, true)) { continue; }
                    LandingMedia::create([
                        'disk' => 'public',
                        'path' => ltrim((string) (parse_url($src, PHP_URL_PATH) ?: $src), '/'),
                        'url'  => $src,
                        'kind' => stripos($src, 'logo') !== false ? 'logo' : 'image',
                        'alt'  => '',
                        'uploaded_by' => auth('admin')->id(),
                    ]);
                    $existing[] = $src;
                    $added++;
                }
            }
        }
        return ['ok' => true, 'added' => $added];
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
    private const SEO_KEYS = ['seo_title','seo_description','seo_keywords','seo_canonical','seo_robots','seo_og_image','seo_site_name','seo_twitter_handle','seo_favicon','seo_logo','track_ga4','track_gtm','track_fb_pixel','track_google_ads','track_head_html','track_body_html','track_conversion_html','thankyou_headline','thankyou_message'];

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

    /** Decode base64 images embedded in the page into real files + register them; replaces the data URI with the file URL. */
    public function mediaExtract()
    {
        $extracted = 0; $n = 0;
        $extMap = ['jpeg'=>'jpg','jpg'=>'jpg','png'=>'png','gif'=>'gif','webp'=>'webp','svg+xml'=>'svg'];
        foreach (LandingSection::orderBy('sort')->get() as $s) {
            $html = (string) $s->html; $orig = $html; $uris = [];
            if (preg_match_all('#src="(data:image/[^"]+)"#i', $html, $m)) { foreach ($m[1] as $u) { $uris[$u] = true; } }
            if (preg_match_all('#url\(\s*[\'"]?(data:image/[^)\'"]+)[\'"]?\s*\)#i', $html, $m)) { foreach ($m[1] as $u) { $uris[$u] = true; } }
            foreach (array_keys($uris) as $uri) {
                if (! preg_match('#^data:image/([a-zA-Z0-9.+-]+);base64,(.*)$#s', $uri, $mm)) { continue; }
                $mime = strtolower($mm[1]);
                $ext = $extMap[$mime] ?? 'img';
                $bin = base64_decode($mm[2], true);
                if ($bin === false) { continue; }
                $n++;
                $path = 'landing/extracted-'.$s->id.'-'.$n.'.'.$ext;
                \Storage::disk('public')->put($path, $bin);
                $url = \Storage::disk('public')->url($path);
                $w = $h = null;
                if ($ext !== 'svg' && function_exists('getimagesizefromstring')) {
                    $sz = @getimagesizefromstring($bin);
                    if ($sz) { $w = $sz[0]; $h = $sz[1]; }
                }
                LandingMedia::create([
                    'disk' => 'public', 'path' => $path, 'url' => $url, 'kind' => 'image',
                    'alt' => '', 'width' => $w, 'height' => $h, 'bytes' => strlen($bin),
                    'uploaded_by' => auth('admin')->id(),
                ]);
                $html = str_replace($uri, $url, $html);
                $extracted++;
            }
            if ($html !== $orig) { $s->update(['html' => $html]); }
        }
        return ['ok' => true, 'extracted' => $extracted];
    }
}
