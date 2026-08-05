<?php

namespace App\Http\Controllers;

use App\Models\LandingSection;
use App\Models\Setting;
use App\Support\LandingRenderer;

class LandingController extends Controller
{
    /** Live DB render — used for CMS preview before publishing. */
    public function show()
    {
        // Preview render: identical to publish output, but each visible section is
        // preceded by an invisible scroll-anchor so the editor can jump to it.
        // Publish/verify use LandingRenderer::html() (no anchors) → still byte-identical.
        $out = '';
        foreach (LandingSection::orderBy('sort')->get() as $sec) {
            if ($sec->is_layout) { $out .= $sec->html; continue; }
            if (! $sec->is_visible) { continue; }
            $out .= '<span class="cms-anchor" id="cms-'.e($sec->key).'" style="display:block;height:0;scroll-margin-top:80px"></span>'.$sec->html;
        }
        return response(LandingRenderer::decorate($out))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    /** Thank-you page — where conversion pixels fire after a lead is submitted. */
    public function thanks()
    {
        $headline = (string) Setting::get('thankyou_headline', '') ?: 'Thank you!';
        $message  = (string) Setting::get('thankyou_message', '') ?: 'We have received your details and will be in touch shortly.';
        $conv     = (string) Setting::get('track_conversion_html', '');

        $doc = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($headline).'</title><meta name="robots" content="noindex">'
            .'<link rel="preconnect" href="https://fonts.googleapis.com">'
            .'<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">'
            .'<style>body{margin:0;font-family:Inter,\'Segoe UI\',system-ui,sans-serif;background:#FBFAF6;color:#15171C;display:grid;place-items:center;min-height:100vh}'
            .'.ty{max-width:520px;text-align:center;padding:40px 28px}'
            .'.ty .ic{width:74px;height:74px;border-radius:50%;background:linear-gradient(135deg,#0B6373,#12A0B5);display:flex;align-items:center;justify-content:center;margin:0 auto 18px}'
            .'.ty h1{font-size:30px;margin:0 0 8px;color:#0B6373}.ty p{font-size:16px;color:#4a5560;line-height:1.65}'
            .'.ty a{display:inline-block;margin-top:24px;background:#0E7C8F;color:#fff;text-decoration:none;padding:12px 24px;border-radius:10px;font-weight:800}</style>'
            .'</head><body><div class="ty">'
            .'<div class="ic"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg></div>'
            .'<h1>'.e($headline).'</h1><p>'.nl2br(e($message)).'</p>'
            .'<a href="/">Back to home</a></div>';
        if (trim($conv) !== '') { $doc .= "\n<!-- conversion --> ".$conv."\n"; }
        $doc .= '</body></html>';

        return response(LandingRenderer::decorate($doc))
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate');
    }
}
