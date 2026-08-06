<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Professional role-permission matrix (Ejaz, 7-Aug-2026).
 *
 * Module-level permissions (none | view | manage) per role, editable in
 * /admin -> Users & Roles, stored as ONE JSON Setting ('role_permissions') —
 * no migrations. Custom roles supported. SUPER is hard-locked to full access
 * and can never be edited or deleted. Enforcement is SERVER-SIDE in the
 * AdminRole middleware (module resolved from the request path).
 */
class PermissionService
{
    /** Every admin module (key => human label). Keys match console page ids. */
    public const MODULES = [
        'dashboard' => 'Dashboard',
        'tenants' => 'Clients / Tenants',
        'trials' => 'Trials',
        'leads' => 'Leads',
        'support' => 'Support tickets',
        'licences' => 'Licences',
        'plans' => 'Plans & Pricing',
        'orders' => 'Orders & Payments',
        'credit' => 'Credit Clients',
        'invoices' => 'Invoices',
        'storage' => 'Cloud Storage',
        'coupons' => 'Coupons',
        'reports' => 'Accountant Reports',
        'landing' => 'Landing CMS',
        'watemplates' => 'WhatsApp Templates',
        'downloads' => 'Downloads',
        'settings' => 'Settings',
        'users' => 'Users & Roles',
        'audit' => 'Audit Log',
        'help' => 'Help & Troubleshooting',
    ];

    /** Built-in roles + their default permissions (mirrors the pre-matrix behaviour). */
    public const DEFAULTS = [
        'sales' => ['label' => 'Sales', 'perms' => [
            'dashboard' => 'view', 'tenants' => 'manage', 'trials' => 'manage', 'leads' => 'manage',
            'support' => 'manage', 'licences' => 'manage', 'plans' => 'view', 'orders' => 'manage',
            'credit' => 'manage', 'invoices' => 'view', 'storage' => 'manage', 'coupons' => 'manage',
            'reports' => 'view', 'landing' => 'none', 'watemplates' => 'none', 'downloads' => 'none',
            'settings' => 'none', 'users' => 'none', 'audit' => 'view', 'help' => 'none',
        ]],
        'support' => ['label' => 'Support', 'perms' => [
            'dashboard' => 'view', 'tenants' => 'view', 'trials' => 'view', 'leads' => 'view',
            'support' => 'manage', 'licences' => 'view', 'plans' => 'none', 'orders' => 'none',
            'credit' => 'none', 'invoices' => 'none', 'storage' => 'none', 'coupons' => 'none',
            'reports' => 'none', 'landing' => 'none', 'watemplates' => 'none', 'downloads' => 'none',
            'settings' => 'none', 'users' => 'none', 'audit' => 'view', 'help' => 'none',
        ]],
    ];

    /**
     * Map an /admin/api/... path (first segment) to its module. Entries with a
     * forced level override the GET=view / write=manage rule.
     */
    public const PATH_MODULES = [
        'dashboard' => 'dashboard',
        'tenants' => 'tenants',
        'trials' => 'trials',
        'leads' => 'leads',
        'tickets' => 'support',
        'licences' => 'licences',
        'plans' => 'plans',
        'orders' => 'orders',
        'prospect-quote' => 'orders',
        'setup-invoice' => 'orders',
        'quote' => ['orders', 'view'],       // live price preview — read-only maths
        'credit-clients' => 'credit',
        'invoices' => 'invoices',
        'storage' => 'storage',
        'coupons' => 'coupons',
        'reports' => 'reports',
        'webhooks' => ['orders', 'view'],
        'audit' => 'audit',
        'landing' => 'landing',
        'wa-templates' => 'watemplates',
        'config' => 'settings',
        'settings' => 'settings',
        'diagnostics' => 'help',
        'logs' => 'help',                     // logs/purge is special-cased below
        'scheduler' => 'help',
        'download-artifacts' => 'downloads',
        'download-limits' => 'downloads',
        'admin-users' => 'users',
        'role-permissions' => 'users',
    ];

    /** All roles (built-in + custom + edits), merged: key => ['label','perms'=>[module=>level]] */
    public static function roles(): array
    {
        $stored = [];
        try {
            $raw = Setting::get('role_permissions');
            $decoded = $raw ? json_decode((string) $raw, true) : null;
            if (is_array($decoded)) {
                $stored = $decoded;
            }
        } catch (\Throwable $e) {
            // defaults only
        }

        $roles = [];
        foreach (self::DEFAULTS as $key => $def) {
            $roles[$key] = [
                'label' => $stored[$key]['label'] ?? $def['label'],
                'builtin' => true,
                'perms' => array_merge($def['perms'], (array) ($stored[$key]['perms'] ?? [])),
            ];
        }
        foreach ($stored as $key => $cfg) {
            if ($key === 'super' || isset($roles[$key]) || ! is_array($cfg)) {
                continue;
            }
            $perms = [];
            foreach (array_keys(self::MODULES) as $m) {
                $perms[$m] = in_array($cfg['perms'][$m] ?? 'none', ['none', 'view', 'manage'], true)
                    ? ($cfg['perms'][$m] ?? 'none') : 'none';
            }
            $roles[$key] = ['label' => (string) ($cfg['label'] ?? ucfirst($key)), 'builtin' => false, 'perms' => $perms];
        }

        return $roles;
    }

    public static function roleKeys(): array
    {
        return array_merge(['super'], array_keys(self::roles()));
    }

    /** Permission level for a role on a module: none | view | manage. */
    public static function level(?string $role, string $module): string
    {
        if ($role === 'super') {
            return 'manage';
        }
        $roles = self::roles();

        return $roles[$role]['perms'][$module] ?? 'none';
    }

    /** Full module=>level map for one role (for the console UI). */
    public static function mapFor(?string $role): array
    {
        if ($role === 'super') {
            return array_fill_keys(array_keys(self::MODULES), 'manage');
        }
        $roles = self::roles();

        return $roles[$role]['perms'] ?? array_fill_keys(array_keys(self::MODULES), 'none');
    }

    /**
     * Resolve an admin request to [module, requiredLevel] — used by the
     * AdminRole middleware. Returns null when the path is not permission-mapped
     * (then plain admin auth applies).
     */
    public static function requirementFor(string $path, string $method): ?array
    {
        // e.g. admin/api/orders/12/mark-paid  → segment 'orders'
        if (! preg_match('#^admin/api/([^/]+)#', $path, $m)) {
            return null;
        }
        $seg = $m[1];

        // Special case: purging logs is an audit-manage action, not help.
        if ($seg === 'logs' && str_contains($path, 'logs/purge')) {
            return ['audit', 'manage'];
        }

        $map = self::PATH_MODULES[$seg] ?? null;
        if ($map === null) {
            return null; // unmapped → auth-only (fail open to authenticated admins)
        }
        if (is_array($map)) {
            return [$map[0], $map[1]];
        }

        $level = in_array(strtoupper($method), ['GET', 'HEAD'], true) ? 'view' : 'manage';

        return [$map, $level];
    }

    public static function allows(?string $role, string $module, string $required): bool
    {
        $have = self::level($role, $module);

        return $required === 'view' ? in_array($have, ['view', 'manage'], true) : $have === 'manage';
    }
}
