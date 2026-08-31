<?php

declare(strict_types=1);

use App\Support\Iam\RoleTemplateMatrix;

/*
|--------------------------------------------------------------------------
| tempo — service authorization catalog (source of truth)
|--------------------------------------------------------------------------
|
| This follows the same downstream contract as dxs-kintai: concrete service
| permissions are synchronized to Platform with `php artisan dxs:sync-authz`.
| Self-service and cross-tenant superuser capabilities are not tenant roles.
|
*/

$permissionSlugs = RoleTemplateMatrix::for('org-admin');

$permissions = array_map(
    static fn (string $slug): array => [
        'slug' => $slug,
        'display_name' => implode(' · ', array_map(
            static fn (string $segment): string => ucwords(str_replace('_', ' ', $segment)),
            explode('.', $slug),
        )),
        'group' => explode('.', $slug)[0],
    ],
    $permissionSlugs,
);

return [
    'permissions' => $permissions,

    'roles' => [
        [
            'role' => 'member',
            'display_name' => 'Tempo Member',
            'level' => 30,
            'permissions' => RoleTemplateMatrix::for('staff'),
        ],
        [
            'role' => 'manager',
            'display_name' => 'Tempo Manager',
            'level' => 80,
            'permissions' => RoleTemplateMatrix::for('org-manager'),
        ],
        [
            'role' => 'admin',
            'display_name' => 'Tempo Administrator',
            'level' => 100,
            'permissions' => $permissionSlugs,
        ],
    ],

    'default_role' => 'member',
];
