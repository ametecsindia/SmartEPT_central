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

        // EPT-17: a user still holding a temp password is walled server-side to the
        // create-your-own-password screen (the browser overlay is only cosmetic).
        // Only the portal shell (renders the forced overlay) and the change-password
        // endpoint are reachable; logout lives outside this middleware group.
        if (auth('client')->user()->must_set_password) {
            $path = $request->path();
            $allowed = $path === 'client' || $path === 'client/api/account/password';
            if (! $allowed) {
                return $request->expectsJson()
                    ? response()->json(['error' => 'password_change_required'], 403)
                    : redirect('/client');
            }
        }

        return $next($request);
    }
}
