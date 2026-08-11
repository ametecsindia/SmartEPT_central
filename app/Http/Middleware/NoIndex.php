<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * SEO (12-Aug-2026, Google Search Console cleanup): workflow pages — client
 * auth/portal, /buy, /pay/*, /cms-preview — must not appear in organic search.
 *
 * X-Robots-Tag header, NOT robots.txt Disallow: Google must stay able to
 * crawl these URLs so it can SEE the noindex. The header covers every
 * variant in one place — query strings (?plan=enterprise), token URLs,
 * printable sub-pages, redirects — with no template changes. "follow" keeps
 * link equity flowing back to the indexable marketing pages.
 */
class NoIndex
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        if (method_exists($response, 'header')) {
            $response->header('X-Robots-Tag', 'noindex, follow');
        }

        return $response;
    }
}
