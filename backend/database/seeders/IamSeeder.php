<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Iam\RoleTemplateMatrix;
use Illuminate\Database\Seeder;

/**
 * Seeds the 5 GLOBAL system roles and their 33 permissions.
 *
 * Role definitions + the permission matrix are the single source of truth in
 * {@see RoleTemplateMatrix} (shared with GodxOrgSyncService per-org seeding —
 * plan-fix-issue-847 — plan đã archive rồi xoá khỏi cây #2188, xem git history). Idempotent — safe to re-run via firstOrCreate / sync.
 */
class IamSeeder extends Seeder
{
    public function run(): void
    {
        // ----------------------------------------------------------------
        // Roles (system-wide, no organization scope) — from RoleTemplateMatrix
        // ----------------------------------------------------------------

        $roleModels = [];
        foreach (RoleTemplateMatrix::ROLES as $slug => $data) {
            $roleModels[$slug] = Role::firstOrCreate(
                ['slug' => $slug],
                ['name' => $data['name'], 'level' => $data['level'], 'description' => $data['description']],
            );
        }

        // ----------------------------------------------------------------
        // Permissions (grouped by domain)
        // ----------------------------------------------------------------

        $permissions = [
            // Catalog (HQ)
            ['slug' => 'catalog.view', 'name' => 'View Catalog', 'group' => 'catalog'],
            ['slug' => 'catalog.create', 'name' => 'Create Catalog', 'group' => 'catalog'],
            ['slug' => 'catalog.update', 'name' => 'Update Catalog', 'group' => 'catalog'],
            ['slug' => 'catalog.delete', 'name' => 'Delete Catalog', 'group' => 'catalog'],
            ['slug' => 'catalog.import', 'name' => 'Import Catalog', 'group' => 'catalog'],
            ['slug' => 'catalog.export', 'name' => 'Export Catalog', 'group' => 'catalog'],
            ['slug' => 'catalog.approve', 'name' => 'Approve Catalog', 'group' => 'catalog'],

            // Material & Recipe (HQ)
            ['slug' => 'material.view', 'name' => 'View Materials', 'group' => 'material'],
            ['slug' => 'material.create', 'name' => 'Create Materials', 'group' => 'material'],
            ['slug' => 'material.update', 'name' => 'Update Materials', 'group' => 'material'],
            ['slug' => 'material.delete', 'name' => 'Delete Materials', 'group' => 'material'],
            ['slug' => 'material.import', 'name' => 'Import Materials', 'group' => 'material'],
            ['slug' => 'material.export', 'name' => 'Export Materials', 'group' => 'material'],
            ['slug' => 'material.approve', 'name' => 'Approve Materials', 'group' => 'material'],

            // Menu (HQ + Shop)
            ['slug' => 'menu.view', 'name' => 'View Menus', 'group' => 'menu'],
            ['slug' => 'menu.manage', 'name' => 'Manage Menus', 'group' => 'menu'],
            ['slug' => 'menu.publish', 'name' => 'Publish Menus', 'group' => 'menu'],

            // Inventory (Shop)
            ['slug' => 'inventory.view', 'name' => 'View Inventory', 'group' => 'inventory'],
            ['slug' => 'inventory.transaction.create', 'name' => 'Create Stock Transactions', 'group' => 'inventory'],
            ['slug' => 'inventory.transaction.approve', 'name' => 'Approve Stock Transactions', 'group' => 'inventory'],
            ['slug' => 'inventory.transfer.create', 'name' => 'Create Stock Transfers', 'group' => 'inventory'],
            ['slug' => 'inventory.transfer.approve', 'name' => 'Approve Stock Transfers', 'group' => 'inventory'],
            ['slug' => 'inventory.count.manage', 'name' => 'Manage Stock Counts', 'group' => 'inventory'],
            ['slug' => 'inventory.count.approve', 'name' => 'Approve Stock Counts', 'group' => 'inventory'],
            ['slug' => 'inventory.production.manage', 'name' => 'Manage Production Orders', 'group' => 'inventory'],
            ['slug' => 'inventory.production.approve', 'name' => 'Approve Production Orders', 'group' => 'inventory'],
            ['slug' => 'inventory.warehouse.manage', 'name' => 'Manage Warehouses', 'group' => 'inventory'],

            // Shop
            ['slug' => 'shop.view', 'name' => 'View Shop', 'group' => 'shop'],
            ['slug' => 'shop.create', 'name' => 'Create Shop', 'group' => 'shop'],
            ['slug' => 'shop.manage', 'name' => 'Manage Shop', 'group' => 'shop'],

            // IAM
            ['slug' => 'iam.member.view', 'name' => 'View IAM Members', 'group' => 'iam'],
            ['slug' => 'iam.assign', 'name' => 'Assign IAM Roles', 'group' => 'iam'],
            ['slug' => 'iam.permissions', 'name' => 'Manage IAM Permissions', 'group' => 'iam'],
        ];

        $permModels = [];
        foreach ($permissions as $data) {
            $permModels[$data['slug']] = Permission::firstOrCreate(
                ['slug' => $data['slug']],
                ['name' => $data['name'], 'group' => $data['group']],
            );
        }

        // ----------------------------------------------------------------
        // Permission matrix (single source of truth: RoleTemplateMatrix)
        // org-admin gets everything; each other role gets a subset.
        // ----------------------------------------------------------------

        foreach (RoleTemplateMatrix::matrix() as $roleSlug => $permSlugs) {
            $role = $roleModels[$roleSlug];
            $permIds = array_map(fn ($slug) => $permModels[$slug]->id, $permSlugs);
            $role->permissions()->sync($permIds);
        }
    }
}
