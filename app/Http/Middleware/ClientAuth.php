<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ClientAuth
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth('client')->check()) {
            return $request->expectsJson()
                ? response()->json(['error' => 'unauthenticated'], 401)
                : redirect('/client/login');
        }

        return $next($request);
    }
}
