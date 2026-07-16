<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth('admin')->check()) {
            return $request->expectsJson()
                ? response()->json(['error' => 'unauthenticated'], 401)
                : redirect('/admin/login');
        }

        return $next($request);
    }
}
