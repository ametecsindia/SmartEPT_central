<?php

return [
    'defaults' => [
        'guard' => 'admin',
        'passwords' => 'admin_users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'admin_users',
        ],
        'admin' => [
            'driver' => 'session',
            'provider' => 'admin_users',
        ],
        // /client tenant portal (Phase 3)
        'client' => [
            'driver' => 'session',
            'provider' => 'tenant_users',
        ],
    ],

    'providers' => [
        'admin_users' => [
            'driver' => 'eloquent',
            'model' => App\Models\AdminUser::class,
        ],
        'tenant_users' => [
            'driver' => 'eloquent',
            'model' => App\Models\TenantUser::class,
        ],
    ],

    'passwords' => [
        'admin_users' => [
            'provider' => 'admin_users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
];
