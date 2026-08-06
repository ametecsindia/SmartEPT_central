<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\PermissionService;
use Illuminate\Http\Request;

/**
 * Manage Ametecs staff logins to SmartEPT Central (Ejaz 20-Jul, SmartPRS pattern).
 * Super-admin only. Guardrails keep at least one active super admin and stop an
 * admin from locking themselves out. 7-Aug: roles are dynamic — built-in
 * (super/sales/support) + custom roles from the editable permissions matrix
 * (PermissionService, Setting 'role_permissions').
 */
class AdminUserController extends Controller
{

    public function index()
    {
        return response()->json([
            'data' => AdminUser::orderByDesc('active')->orderBy('name')->get()->map(fn (AdminUser $u) => [
                'id'            => $u->id,
                'name'          => $u->name,
                'email'         => $u->email,
                'role'          => $u->role,
                'active'        => (bool) $u->active,
                'last_login_at' => optional($u->last_login_at)->toDateTimeString(),
                'is_self'       => $u->id === auth('admin')->id(),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:190', 'unique:admin_users,email'],
            'role'     => ['required', 'in:' . implode(',', PermissionService::roleKeys())],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $user = AdminUser::create($data + ['active' => true]);
        AuditLog::write('admin_user.created', $user, ['role' => $user->role]);

        return response()->json(['data' => ['id' => $user->id]], 201);
    }

    public function update(Request $request, AdminUser $adminUser)
    {
        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:120'],
            'role'   => ['sometimes', 'in:' . implode(',', PermissionService::roleKeys())],
            'active' => ['sometimes', 'boolean'],
        ]);

        $demotes = (isset($data['role']) && $data['role'] !== 'super')
            || (array_key_exists('active', $data) && ! $data['active']);

        if ($adminUser->id === auth('admin')->id() && $demotes) {
            return response()->json(['message' => 'You cannot change your own role or deactivate your own account.'], 422);
        }
        if ($adminUser->role === 'super' && $demotes && $this->activeSupers() <= 1) {
            return response()->json(['message' => 'At least one active super admin must remain.'], 422);
        }

        $adminUser->update($data);
        AuditLog::write('admin_user.updated', $adminUser, $data);

        return response()->json(['ok' => true]);
    }

    public function resetPassword(Request $request, AdminUser $adminUser)
    {
        $data = $request->validate(['password' => ['required', 'string', 'min:8']]);
        $adminUser->update(['password' => $data['password']]);
        AuditLog::write('admin_user.password_reset', $adminUser);

        return response()->json(['ok' => true]);
    }

    public function destroy(AdminUser $adminUser)
    {
        if ($adminUser->id === auth('admin')->id()) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }
        if ($adminUser->role === 'super' && $this->activeSupers() <= 1) {
            return response()->json(['message' => 'At least one active super admin must remain.'], 422);
        }

        $id = $adminUser->id;
        $adminUser->delete();
        AuditLog::write('admin_user.deleted', null, ['id' => $id]);

        return response()->json(['ok' => true]);
    }

    /** GET role-permissions — matrix data for the Users & Roles screen. */
    public function rolePermissions()
    {
        $usage = AdminUser::selectRaw('role, count(*) as n')->groupBy('role')->pluck('n', 'role');
        $roles = [];
        foreach (PermissionService::roles() as $key => $cfg) {
            $roles[] = [
                'key' => $key,
                'label' => $cfg['label'],
                'builtin' => $cfg['builtin'],
                'perms' => $cfg['perms'],
                'users' => (int) ($usage[$key] ?? 0),
            ];
        }

        return response()->json(['data' => [
            'modules' => PermissionService::MODULES,
            'roles' => $roles,
            'super_users' => (int) ($usage['super'] ?? 0),
        ]]);
    }

    /** PUT role-permissions — save the whole matrix (super only, via routes). */
    public function saveRolePermissions(Request $request)
    {
        $data = $request->validate(['roles' => ['required', 'array']]);
        $modules = array_keys(PermissionService::MODULES);
        $clean = [];

        foreach ($data['roles'] as $key => $cfg) {
            $key = strtolower(trim((string) $key));
            if ($key === 'super') {
                return response()->json(['message' => 'The Super role is locked to full access and cannot be edited.'], 422);
            }
            if (! preg_match('/^[a-z][a-z0-9_-]{1,29}$/', $key)) {
                return response()->json(['message' => 'Role key "' . $key . '" is invalid — use 2-30 lowercase letters, digits, - or _.'], 422);
            }
            $perms = [];
            foreach ($modules as $m) {
                $v = $cfg['perms'][$m] ?? 'none';
                $perms[$m] = in_array($v, ['none', 'view', 'manage'], true) ? $v : 'none';
            }
            $clean[$key] = [
                'label' => mb_substr(trim((string) ($cfg['label'] ?? ucfirst($key))), 0, 40) ?: ucfirst($key),
                'perms' => $perms,
            ];
        }

        // A role removed from the matrix must not still be assigned to staff.
        $inUse = AdminUser::whereNotIn('role', array_merge(['super'], array_keys($clean)))->pluck('role')->unique();
        if ($inUse->isNotEmpty()) {
            return response()->json(['message' => 'Cannot remove role(s) still assigned to staff: ' . $inUse->implode(', ') . '. Reassign those users first.'], 422);
        }

        Setting::set('role_permissions', json_encode($clean));
        AuditLog::write('roles.matrix_updated', null, ['roles' => array_keys($clean)]);

        return response()->json(['ok' => true]);
    }

    private function activeSupers(): int
    {
        return AdminUser::where('role', 'super')->where('active', true)->count();
    }
}
