<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminRole
{
    /**
     * Usage: middleware('admin.role:super') or ('admin.role:super,sales').
     */
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = auth('admin')->user();

        if (! $user || ! in_array($user->role, $roles)) {
            return $request->expectsJson()
                ? response()->json(['error' => 'forbidden'], 403)
                : abort(403);
        }

        return $next($request);
    }
}
