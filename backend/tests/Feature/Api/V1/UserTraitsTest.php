<?php

use App\Models\Branch;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

// =============================================================================
// Local scoped roles
// =============================================================================

describe('local scoped roles', function () {
    it('assigns a role with organization scope', function () {
        $user = User::factory()->sso()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => 'admin', 'level' => 100]);
        $org = Organization::factory()->create();

        $user->assignRole($role, $org->id);

        expect($user->roles()->count())->toBe(1);
        expect($user->roles()->first()->pivot->organization_id)->toBe($org->id);
    });

    it('assigns role idempotently', function () {
        $user = User::factory()->sso()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => 'admin', 'level' => 100]);
        $org = Organization::factory()->create();

        $user->assignRole($role, $org->id);
        $user->assignRole($role, $org->id); // duplicate

        expect($user->roles()->count())->toBe(1);
    });

    it('assigns role by slug string', function () {
        $user = User::factory()->sso()->create();
        Role::create(['name' => 'Staff', 'slug' => 'staff', 'level' => 10]);
        $org = Organization::factory()->create();

        $user->assignRole('staff', $org->id);

        expect($user->roles()->count())->toBe(1);
        expect($user->roles()->first()->slug)->toBe('staff');
    });

    it('throws exception for non-existent role slug', function () {
        $user = User::factory()->sso()->create();

        expect(fn () => $user->assignRole('nonexistent'))->toThrow(InvalidArgumentException::class);
    });

    it('removes a scoped role', function () {
        $user = User::factory()->sso()->create();
        $role = Role::create(['name' => 'Admin', 'slug' => 'admin', 'level' => 100]);
        $org = Organization::factory()->create();

        $user->assignRole($role, $org->id);
        expect($user->roles()->count())->toBe(1);

        $user->removeRole($role, $org->id);
        expect($user->roles()->count())->toBe(0);
    });

    it('gets roles for context with hierarchy', function () {
        $user = User::factory()->sso()->create();
        $org = Organization::factory()->create();
        $branch = Branch::factory()->create();

        $globalRole = Role::create(['name' => 'System', 'slug' => 'system', 'level' => 90]);
        $orgRole = Role::create(['name' => 'OrgAdmin', 'slug' => 'org-admin', 'level' => 50]);
        $branchRole = Role::create(['name' => 'BranchMgr', 'slug' => 'branch-mgr', 'level' => 30]);

        $user->assignRole($globalRole); // global
        $user->assignRole($orgRole, $org->id); // org-wide
        $user->assignRole($branchRole, $org->id, $branch->id); // branch-specific

        // No context → global only
        $globalRoles = $user->getRolesForContext();
        expect($globalRoles)->toHaveCount(1);
        expect($globalRoles->first()->slug)->toBe('system');

        // Org context → global + org-wide
        $orgRoles = $user->getRolesForContext($org->id);
        expect($orgRoles)->toHaveCount(2);

        // Branch context → global + org-wide + branch-specific
        $branchRoles = $user->getRolesForContext($org->id, $branch->id);
        expect($branchRoles)->toHaveCount(3);
    });

    it('gets highest role level in context', function () {
        $user = User::factory()->sso()->create();
        $org = Organization::factory()->create();

        $staff = Role::create(['name' => 'Staff', 'slug' => 'staff', 'level' => 10]);
        $admin = Role::create(['name' => 'Admin', 'slug' => 'admin', 'level' => 100]);

        $user->assignRole($staff, $org->id);
        $user->assignRole($admin, $org->id);

        expect($user->getHighestRoleLevelInContext($org->id))->toBe(100);
    });

    it('returns 0 when user has no roles', function () {
        $user = User::factory()->sso()->create();

        expect($user->getHighestRoleLevelInContext('some-org'))->toBe(0);
    });

    it('checks permissions through roles', function () {
        $user = User::factory()->sso()->create();
        $org = Organization::factory()->create();

        $role = Role::create(['name' => 'Editor', 'slug' => 'editor', 'level' => 30]);
        $p1 = Permission::create(['name' => 'Create Products', 'slug' => 'products.create']);
        $p2 = Permission::create(['name' => 'Edit Products', 'slug' => 'products.edit']);
        Permission::create(['name' => 'Delete Products', 'slug' => 'products.delete']);

        $role->permissions()->attach([$p1->id, $p2->id]);
        $user->assignRole($role, $org->id);

        expect($user->hasPermission('products.create', $org->id))->toBeTrue();
        expect($user->hasPermission('products.edit', $org->id))->toBeTrue();
        expect($user->hasPermission('products.delete', $org->id))->toBeFalse();

        expect($user->hasAnyPermission(['products.delete', 'products.create'], $org->id))->toBeTrue();
        expect($user->hasAnyPermission(['products.delete', 'users.manage'], $org->id))->toBeFalse();
    });

    it('gets all permissions for context', function () {
        $user = User::factory()->sso()->create();
        $org = Organization::factory()->create();

        $role = Role::create(['name' => 'Editor', 'slug' => 'editor', 'level' => 30]);
        $p1 = Permission::create(['name' => 'Create', 'slug' => 'products.create']);
        $p2 = Permission::create(['name' => 'Edit', 'slug' => 'products.edit']);

        $role->permissions()->attach([$p1->id, $p2->id]);
        $user->assignRole($role, $org->id);

        $permissions = $user->getAllPermissions($org->id);
        expect($permissions)->toHaveCount(2);
        expect($permissions->pluck('slug')->toArray())->toContain('products.create', 'products.edit');
    });

    it('assigns same role to different organizations independently', function () {
        $user = User::factory()->sso()->create();
        $orgA = Organization::factory()->create();
        $orgB = Organization::factory()->create();

        $admin = Role::create(['name' => 'Admin', 'slug' => 'admin', 'level' => 100]);

        $user->assignRole($admin, $orgA->id);
        $user->assignRole($admin, $orgB->id);

        expect($user->roles()->count())->toBe(2);

        // Remove from orgA only
        $user->removeRole($admin, $orgA->id);
        expect($user->roles()->count())->toBe(1);

        $remaining = $user->roles()->first();
        expect($remaining->pivot->organization_id)->toBe($orgB->id);
    });

    it('handles multiple permissions with OR logic correctly', function () {
        $user = User::factory()->sso()->create();
        $org = Organization::factory()->create();

        $role = Role::create(['name' => 'Editor', 'slug' => 'editor', 'level' => 30]);
        $pCreate = Permission::create(['name' => 'Create', 'slug' => 'products.create']);
        $pEdit = Permission::create(['name' => 'Edit', 'slug' => 'products.edit']);
        Permission::create(['name' => 'Delete', 'slug' => 'products.delete']);
        Permission::create(['name' => 'Manage Users', 'slug' => 'users.manage']);

        $role->permissions()->attach([$pCreate->id, $pEdit->id]);
        $user->assignRole($role, $org->id);

        // Has both → true
        expect($user->hasAnyPermission(['products.create', 'products.edit'], $org->id))->toBeTrue();

        // Has one of two → true
        expect($user->hasAnyPermission(['products.delete', 'products.create'], $org->id))->toBeTrue();

        // Has none → false
        expect($user->hasAnyPermission(['products.delete', 'users.manage'], $org->id))->toBeFalse();

        // Empty array → false
        expect($user->hasAnyPermission([], $org->id))->toBeFalse();
    });

    it('resolves permissions from branch-specific role', function () {
        $user = User::factory()->sso()->create();
        $org = Organization::factory()->create();
        $branch = Branch::factory()->create();

        $branchRole = Role::create(['name' => 'Branch Manager', 'slug' => 'branch-mgr', 'level' => 30]);
        $pApprove = Permission::create(['name' => 'Approve', 'slug' => 'tasks.approve']);
        $branchRole->permissions()->attach($pApprove->id);

        $user->assignRole($branchRole, $org->id, $branch->id);

        // Without branch context → no permission
        expect($user->hasPermission('tasks.approve', $org->id))->toBeFalse();

        // With branch context → has permission
        expect($user->hasPermission('tasks.approve', $org->id, $branch->id))->toBeTrue();
    });

    it('deduplicates permissions from multiple roles', function () {
        $user = User::factory()->sso()->create();
        $org = Organization::factory()->create();

        $admin = Role::create(['name' => 'Admin', 'slug' => 'admin', 'level' => 100]);
        $editor = Role::create(['name' => 'Editor', 'slug' => 'editor', 'level' => 30]);
        $pView = Permission::create(['name' => 'View', 'slug' => 'products.view']);

        // Both roles have same permission
        $admin->permissions()->attach($pView->id);
        $editor->permissions()->attach($pView->id);

        $user->assignRole($admin, $org->id);
        $user->assignRole($editor, $org->id);

        $allPerms = $user->getAllPermissions($org->id);
        // Should be deduplicated
        expect($allPerms)->toHaveCount(1);
        expect($allPerms->first()->slug)->toBe('products.view');
    });

    it('syncs roles in scope', function () {
        $user = User::factory()->sso()->create();
        $org = Organization::factory()->create();

        $staff = Role::create(['name' => 'Staff', 'slug' => 'staff', 'level' => 10]);
        $admin = Role::create(['name' => 'Admin', 'slug' => 'admin', 'level' => 100]);
        $manager = Role::create(['name' => 'Manager', 'slug' => 'manager', 'level' => 50]);

        $user->assignRole($staff, $org->id);
        $user->assignRole($admin, $org->id);
        expect($user->roles()->count())->toBe(2);

        // Sync to only manager
        $user->syncRolesInScope([$manager], $org->id);
        expect($user->roles()->count())->toBe(1);
        expect($user->roles()->first()->slug)->toBe('manager');
    });
});
