<?php

declare(strict_types=1);

namespace App\Support\Iam;

/**
 * Single source of truth for the system IAM role templates and their permission
 * matrix (originally inline in IamSeeder).
 *
 * Consumed by:
 *  - IamSeeder — seeds the 5 GLOBAL system roles + the matrix.
 *  - Platform provisioning — seeds organization-scoped roles.
 *  - UserProvisioner — sync permission khi role `tempo-*` vừa tạo hoặc chưa có gì.
 *    (Lệnh backfill `iam:seed-tempo-role-permissions` ĐÃ GỠ #2507: đo trên
 *    production 0/1 role `tempo-*` thiếu permission, và đường sync trong
 *    provisioner đã bao trùm nó.)
 *
 * Keeping the matrix here (rather than reading it off the seeded global Role rows at
 * runtime) removes the dependency on `db:seed` having run in the target environment —
 * see plan-fix-issue-847 NOTES §9 (archived, removed from tree #2188 — git history).
 */
final class RoleTemplateMatrix
{
    /**
     * Platform service-role slug to the local template whose capabilities it
     * represents. Keep this mapping here so provisioning, backfills, and
     * runtime authorization cannot drift apart.
     *
     * @var array<string, string>
     */
    public const PLATFORM_ROLE_TEMPLATES = [
        'tempo-owner' => 'org-admin',
        'tempo-admin' => 'org-admin',
        'tempo-manager' => 'shop-manager',
        'tempo-staff' => 'shop-staff',
        // #2460 — `member` là giá trị MẶC ĐỊNH mà Platform gửi khi user không
        // có `ServiceUserAccess.role` nào (`$role = $access?->role ?? 'member'`),
        // nên `tempo-member` là slug hay gặp nhất chứ không phải trường hợp lạ.
        // Thiếu nó ở đây thì user đó có quyền `staff` nhưng KHÔNG khớp bất kỳ
        // truy vấn theo vai nào — vô hình với mọi audience và mọi policy.
        'tempo-member' => 'staff',
    ];

    /**
     * The 5 system role templates, highest level first.
     *
     * @var array<string, array{name: string, level: int, description: string}>
     */
    public const ROLES = [
        'org-admin' => ['name' => 'Org Admin', 'level' => 100, 'description' => 'Full access to all resources in the organization.'],
        'org-manager' => ['name' => 'Org Manager', 'level' => 80, 'description' => 'Manage catalog, materials, menus, inventory, and shops across the organization.'],
        'shop-manager' => ['name' => 'Shop Manager', 'level' => 60, 'description' => 'Manage a specific shop — menus, inventory, tables, and staff.'],
        'staff' => ['name' => 'Staff', 'level' => 30, 'description' => 'Create and update catalog and materials, view inventory.'],
        'shop-staff' => ['name' => 'Shop Staff', 'level' => 10, 'description' => 'View menus, inventory, and shop info. Update table status.'],
    ];

    /**
     * Role slug → the permission slugs it grants (from plan-007 DESIGN §6).
     *
     * @var array<string, list<string>>
     */
    private const MATRIX = [
        'org-admin' => [
            'catalog.view', 'catalog.create', 'catalog.update', 'catalog.delete', 'catalog.import', 'catalog.export', 'catalog.approve',
            'material.view', 'material.create', 'material.update', 'material.delete', 'material.import', 'material.export', 'material.approve',
            'menu.view', 'menu.manage', 'menu.publish',
            'inventory.view', 'inventory.transaction.create', 'inventory.transaction.approve',
            'inventory.transfer.create', 'inventory.transfer.approve',
            'inventory.count.manage', 'inventory.count.approve',
            'inventory.production.manage', 'inventory.production.approve',
            'inventory.warehouse.manage',
            'shop.view', 'shop.create', 'shop.manage',
            'iam.member.view', 'iam.assign', 'iam.permissions',
        ],

        'org-manager' => [
            'catalog.view', 'catalog.create', 'catalog.update', 'catalog.delete', 'catalog.import', 'catalog.export', 'catalog.approve',
            'material.view', 'material.create', 'material.update', 'material.delete', 'material.import', 'material.export', 'material.approve',
            'menu.view', 'menu.manage', 'menu.publish',
            'inventory.view', 'inventory.transaction.create', 'inventory.transaction.approve',
            'inventory.transfer.create', 'inventory.transfer.approve',
            'inventory.count.manage', 'inventory.count.approve',
            'inventory.production.manage', 'inventory.production.approve',
            'inventory.warehouse.manage',
            'shop.view', 'shop.manage',
            'iam.member.view',
        ],

        'shop-manager' => [
            'catalog.view',
            'material.view',
            'menu.view', 'menu.manage', 'menu.publish',
            'inventory.view', 'inventory.transaction.create', 'inventory.transaction.approve',
            'inventory.transfer.create',
            'inventory.count.manage',
            'inventory.production.manage',
            'inventory.warehouse.manage',
            'shop.view', 'shop.manage',
            'iam.member.view', 'iam.assign',
        ],

        'staff' => [
            'catalog.view', 'catalog.create', 'catalog.update', 'catalog.import', 'catalog.export',
            'material.view', 'material.create', 'material.update', 'material.import', 'material.export',
            'menu.view',
        ],

        'shop-staff' => [
            'catalog.view',
            'menu.view',
            'inventory.view',
            'shop.view',
        ],
    ];

    /**
     * The full role → permission-slugs matrix.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        return self::MATRIX;
    }

    /**
     * Permission slugs granted by a template role, or [] if the slug is unknown.
     *
     * @return list<string>
     */
    public static function for(string $slug): array
    {
        return self::MATRIX[$slug] ?? [];
    }

    /**
     * The template role slugs (org-admin, org-manager, …).
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_keys(self::MATRIX);
    }

    /**
     * Return every persisted role slug equivalent to a requested template.
     *
     * @return list<string>
     */
    public static function equivalentSlugs(string $templateSlug): array
    {
        $platformSlugs = array_keys(array_filter(
            self::PLATFORM_ROLE_TEMPLATES,
            static fn (string $mappedTemplate): bool => $mappedTemplate === $templateSlug,
        ));

        return array_values(array_unique([$templateSlug, ...$platformSlugs]));
    }
}
