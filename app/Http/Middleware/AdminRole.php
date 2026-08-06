<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;

/**
 * Role/permission gate for the admin console (matrix build, Ejaz 7-Aug-2026).
 *
 * Two modes:
 *  - Matrix (primary): the request path is resolved to a module + required
 *    level via PermissionService::requirementFor() and checked against the
 *    editable role_permissions matrix. Super always passes.
 *  - Legacy fallback: paths the matrix does not map (e.g. /admin/landing page)
 *    still honour middleware('admin.role:super,...') role lists.
 */
class AdminRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = auth('admin')->user();

        if (! $user) {
            return $this->deny($request, 'Please sign in again.');
        }
        if ($user->role === 'super') {
            return $next($request);
        }

        $req = PermissionService::requirementFor($request->path(), $request->method());
        if ($req !== null) {
            [$module, $level] = $req;
            if (! PermissionService::allows($user->role, $module, $level)) {
                $label = PermissionService::MODULES[$module] ?? $module;

                return $this->deny($request, 'Your role does not have '
                    . ($level === 'view' ? 'access to' : 'permission to make changes in')
                    . ' "' . $label . '". Ask a super admin if you need it.');
            }

            return $next($request);
        }

        // Unmapped path: legacy role-list check (empty list = any signed-in admin).
        if ($roles && ! in_array($user->role, $roles, true)) {
            return $this->deny($request, 'Your role does not have access to this area.');
        }

        return $next($request);
    }

    private function deny(Request $request, string $message)
    {
        return $request->expectsJson()
            ? response()->json(['error' => 'forbidden', 'message' => $message], 403)
            : abort(403, $message);
    }
}
