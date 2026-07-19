<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use App\Models\AuditLog;
use Illuminate\Http\Request;

/**
 * Manage Ametecs staff logins to SmartEPT Central (Ejaz 20-Jul, SmartPRS pattern).
 * Super-admin only. Roles: super (full), sales (business + money, no system config),
 * support (read + support desk). Guardrails keep at least one active super admin
 * and stop an admin from locking themselves out.
 */
class AdminUserController extends Controller
{
    public const ROLES = ['super', 'sales', 'support'];

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
            'role'     => ['required', 'in:' . implode(',', self::ROLES)],
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
            'role'   => ['sometimes', 'in:' . implode(',', self::ROLES)],
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

    private function activeSupers(): int
    {
        return AdminUser::where('role', 'super')->where('active', true)->count();
    }
}
